import CryptoKit
import ImageIO
import SwiftUI
import UIKit

/// What to download for one artwork drawn at one size.
///
/// The CDN originals are uploaded as-is (posters of 3+ MB, PNG backdrops of 7 MB), so the app
/// never downloads them directly: it asks the API's resize endpoint for a WebP sized to the
/// frame (the same endpoint the website uses). The endpoint generates the variant once, keeps
/// it on the CDN and redirects there, so a 3.4 MB poster arrives as ~20 KB.
nonisolated struct ImageRequest: Hashable, Sendable {
    /// What is actually fetched: the resize endpoint, or the source when it can't be resized.
    let url: URL
    /// The original, used if the resized variant can't be had.
    let source: URL
    /// Longest side to decode to, so even an original never sits in memory at full size.
    let maxPixelSize: Int

    var cacheKey: String { url.absoluteString }
}

nonisolated extension ImageRequest {
    private static let resizableHosts = ["cdn.filmoteca.md"]
    private static let resizableHostSuffixes = [".r2.dev"]
    /// Widths are rounded up to one of these so devices with similar screens share the
    /// variants the server has already generated (and so a frame that changes by a few points
    /// doesn't trigger a new download).
    private static let widthBuckets = [120, 160, 240, 320, 480, 640, 800, 960, 1280, 1600, 2000, 2400]
    private static let maxSide = 2400

    /// Nil until the view has a real size, so nothing is fetched at a placeholder size.
    static func make(source: URL, size: CGSize, scale: CGFloat, contentMode: ContentMode, apiBaseURL: URL) -> ImageRequest? {
        guard size.width >= 1, size.height >= 1, size.width.isFinite, size.height.isFinite else { return nil }
        let pixelWidth = size.width * max(scale, 1)
        let pixelHeight = size.height * max(scale, 1)

        guard isResizable(source) else {
            return ImageRequest(url: source, source: source, maxPixelSize: Int(max(pixelWidth, pixelHeight).rounded(.up)))
        }

        var width: Int
        var height: Int?
        switch contentMode {
        case .fill:
            // Cropped server-side exactly like `.fill` + `.clipped()` crops on screen.
            width = bucket(for: pixelWidth)
            var h = Int((Double(width) * pixelHeight / pixelWidth).rounded())
            if h > maxSide {
                h = maxSide
                width = max(32, Int((Double(maxSide) * pixelWidth / pixelHeight).rounded()))
            }
            height = max(32, h)
        case .fit:
            // Aspect unknown up front; the longest side bounds both orientations.
            width = bucket(for: max(pixelWidth, pixelHeight))
            height = nil
        }

        var components = URLComponents(url: apiBaseURL.appending(path: "public/images/resize"), resolvingAgainstBaseURL: false)
        var query = [URLQueryItem(name: "url", value: source.absoluteString), URLQueryItem(name: "w", value: String(width))]
        if let height { query.append(URLQueryItem(name: "h", value: String(height))) }
        query.append(URLQueryItem(name: "fit", value: contentMode == .fill ? "cover" : "contain"))
        components?.queryItems = query
        guard let url = components?.url else {
            return ImageRequest(url: source, source: source, maxPixelSize: Int(max(pixelWidth, pixelHeight).rounded(.up)))
        }
        return ImageRequest(url: url, source: source, maxPixelSize: max(width, height ?? width))
    }

    private static func bucket(for pixels: CGFloat) -> Int {
        widthBuckets.first { CGFloat($0) >= pixels } ?? maxSide
    }

    private static func isResizable(_ url: URL) -> Bool {
        guard let host = url.host()?.lowercased(), url.scheme?.hasPrefix("http") == true else { return false }
        if resizableHosts.contains(host) { return true }
        return resizableHostSuffixes.contains { host.hasSuffix($0) }
    }
}

/// Memory → disk → network loader for artwork.
///
/// - Decoded images live in memory (`NSCache`, evicted under pressure), so revisiting a screen
///   or scrolling back shows them instantly, with no placeholder flash.
/// - Downloaded files live in Caches, so a cold start shows the home screen from disk.
/// - Identical requests in flight share one download.
actor ImagePipeline {
    static let shared = ImagePipeline()

    private nonisolated let memory: ImageMemoryCache
    private let session: URLSession
    private let directory: URL
    private var inFlight: [ImageRequest: Task<UIImage?, Never>] = [:]

    private static let diskLimit = 400 * 1024 * 1024
    /// Above this the bytes are an original (resize fell back), so a downscaled copy is stored instead.
    private static let maxStoredBytes = 1_500_000

    init() {
        memory = ImageMemoryCache()
        let configuration = URLSessionConfiguration.default
        configuration.urlCache = nil // the disk cache below replaces it
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        configuration.httpMaximumConnectionsPerHost = 8
        configuration.timeoutIntervalForRequest = 30
        configuration.waitsForConnectivity = false
        session = URLSession(configuration: configuration)
        let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0]
        directory = caches.appending(path: "Artwork", directoryHint: .isDirectory)
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let directory = directory
        Task.detached(priority: .background) { Self.trimDisk(directory, limit: Self.diskLimit) }
    }

    /// Synchronous memory hit, for drawing the first frame without a placeholder.
    nonisolated func cachedImage(for request: ImageRequest) -> UIImage? {
        memory.image(for: request.cacheKey)
    }

    func image(for request: ImageRequest) async -> UIImage? {
        if let cached = memory.image(for: request.cacheKey) { return cached }
        if let running = inFlight[request] { return await running.value }

        let task = Task.detached(priority: .userInitiated) { [session, directory] in
            await Self.load(request, session: session, directory: directory)
        }
        inFlight[request] = task
        let image = await task.value
        inFlight[request] = nil
        if let image { memory.insert(image, for: request.cacheKey) }
        return image
    }

    /// Warms memory and disk for images about to be shown (e.g. other hero slides).
    nonisolated func prefetch(_ requests: [ImageRequest]) {
        for request in requests where memory.image(for: request.cacheKey) == nil {
            Task(priority: .utility) { _ = await self.image(for: request) }
        }
    }

    // MARK: - Loading

    private nonisolated static func load(_ request: ImageRequest, session: URLSession, directory: URL) async -> UIImage? {
        let file = directory.appending(path: fileName(for: request.cacheKey))

        if let data = try? Data(contentsOf: file), let image = decode(data, maxPixelSize: request.maxPixelSize) {
            return image
        }

        var candidates = [request.url]
        if request.source != request.url { candidates.append(request.source) }

        for url in candidates {
            guard !Task.isCancelled else { return nil }
            guard let (data, response) = try? await session.data(from: url),
                  let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode),
                  !data.isEmpty,
                  let image = decode(data, maxPixelSize: request.maxPixelSize) else { continue }
            store(data: data, image: image, at: file)
            return image
        }
        return nil
    }

    /// Decodes straight to the target size (ImageIO thumbnail), already rasterised, so the
    /// main thread never decodes or holds a full-resolution bitmap.
    private nonisolated static func decode(_ data: Data, maxPixelSize: Int) -> UIImage? {
        guard let source = CGImageSourceCreateWithData(data as CFData, [kCGImageSourceShouldCache: false] as CFDictionary) else { return nil }
        let options: [CFString: Any] = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceShouldCacheImmediately: true,
            kCGImageSourceThumbnailMaxPixelSize: max(maxPixelSize, 32),
        ]
        guard let cgImage = CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary) else { return nil }
        return UIImage(cgImage: cgImage)
    }

    private nonisolated static func store(data: Data, image: UIImage, at file: URL) {
        let bytes: Data?
        if data.count <= maxStoredBytes {
            bytes = data
        } else if let cgImage = image.cgImage, [.first, .last, .premultipliedFirst, .premultipliedLast].contains(cgImage.alphaInfo) {
            bytes = image.pngData()
        } else {
            bytes = image.jpegData(compressionQuality: 0.85)
        }
        if let bytes { try? bytes.write(to: file, options: .atomic) }
    }

    private nonisolated static func fileName(for key: String) -> String {
        SHA256.hash(data: Data(key.utf8)).map { String(format: "%02x", $0) }.joined()
    }

    /// Keeps the folder under the limit, dropping the least recently written files first.
    private nonisolated static func trimDisk(_ directory: URL, limit: Int) {
        let keys: [URLResourceKey] = [.contentModificationDateKey, .totalFileAllocatedSizeKey]
        guard let files = try? FileManager.default.contentsOfDirectory(at: directory, includingPropertiesForKeys: keys) else { return }
        var entries = files.compactMap { url -> (URL, Date, Int)? in
            guard let values = try? url.resourceValues(forKeys: Set(keys)) else { return nil }
            return (url, values.contentModificationDate ?? .distantPast, values.totalFileAllocatedSize ?? 0)
        }
        var total = entries.reduce(0) { $0 + $1.2 }
        guard total > limit else { return }
        entries.sort { $0.1 < $1.1 }
        for (url, _, size) in entries where total > limit * 3 / 4 {
            try? FileManager.default.removeItem(at: url)
            total -= size
        }
    }
}

/// Thread-safe (NSCache is) holder of decoded images, costed by bitmap size.
private nonisolated final class ImageMemoryCache: @unchecked Sendable {
    private let cache = NSCache<NSString, UIImage>()

    init() {
        cache.totalCostLimit = 150 * 1024 * 1024
    }

    func image(for key: String) -> UIImage? { cache.object(forKey: key as NSString) }

    func insert(_ image: UIImage, for key: String) {
        let cost = image.cgImage.map { $0.bytesPerRow * $0.height } ?? 0
        cache.setObject(image, forKey: key as NSString, cost: cost)
    }
}
