import AVFoundation
import Foundation
import OSLog

enum BunnyPlaybackError: LocalizedError {
    case invalidResponse
    case http(stage: String, status: Int)
    case missingPlaylist
    case invalidContentIdentifier
    case invalidCertificate
    case invalidLicense

    var errorDescription: String? {
        switch self {
        case .invalidResponse:
            "Bunny a returnat un răspuns invalid."
        case .http(let stage, let status):
            "Bunny a refuzat cererea \(stage) (HTTP \(status))."
        case .missingPlaylist:
            "Bunny nu a returnat adresa fluxului video."
        case .invalidContentIdentifier:
            "Identificatorul FairPlay nu este valid."
        case .invalidCertificate:
            "Certificatul FairPlay nu a putut fi încărcat."
        case .invalidLicense:
            "Licența FairPlay nu a putut fi obținută."
        }
    }
}

struct BunnyStreamService {
    private struct PlayResponse: Decodable {
        let videoPlaylistURL: String

        enum CodingKeys: String, CodingKey {
            case videoPlaylistURL = "videoPlaylistUrl"
        }
    }

    let refererURL: URL
    private let session: URLSession
    private let logger = Logger(subsystem: "md.filmoteca.ios", category: "BunnyStream")

    init(refererURL: URL, session: URLSession = .shared) {
        self.refererURL = refererURL
        self.session = session
    }

    func playlistURL(for reference: BunnyVideoReference) async throws -> URL {
        if reference.originalURL.pathExtension.lowercased() == "m3u8" {
            logger.info("Using the playback playlist supplied by the API.")
            return reference.originalURL
        }

        var components = URLComponents()
        components.scheme = "https"
        components.host = "video.bunnycdn.com"
        components.path = "/library/\(reference.libraryID)/videos/\(reference.videoID)/play"
        components.queryItems = reference.authenticationQueryItems

        guard let url = components.url else { throw BunnyPlaybackError.missingPlaylist }
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.timeoutInterval = 30
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(refererValue, forHTTPHeaderField: "Referer")
        request.setValue("Filmoteca-iOS/1.0", forHTTPHeaderField: "User-Agent")

        logger.info("Requesting Bunny playback configuration for library \(reference.libraryID, privacy: .public).")
        let (data, response) = try await session.data(for: request)
        guard let response = response as? HTTPURLResponse else {
            throw BunnyPlaybackError.invalidResponse
        }
        guard (200..<300).contains(response.statusCode) else {
            logger.error("Bunny playback configuration returned HTTP \(response.statusCode, privacy: .public).")
            throw BunnyPlaybackError.http(stage: "de configurare", status: response.statusCode)
        }

        let payload = try JSONDecoder().decode(PlayResponse.self, from: data)
        guard let playlistURL = URL(string: payload.videoPlaylistURL) else {
            throw BunnyPlaybackError.missingPlaylist
        }
        logger.info("Bunny playback configuration resolved an HLS playlist.")
        return playlistURL
    }

    var requestHeaders: [String: String] {
        [
            "Referer": refererValue,
            "Origin": refererURL.absoluteString,
            "User-Agent": "Filmoteca-iOS/1.0",
        ]
    }

    var refererValue: String {
        let value = refererURL.absoluteString
        return value.hasSuffix("/") ? value : value + "/"
    }
}

final class BunnyFairPlayResourceLoader: NSObject, AVAssetResourceLoaderDelegate, @unchecked Sendable {
    private struct LicenseRequest: Encodable { let spc: String }
    private struct LicenseResponse: Decodable { let ckc: String }

    private let reference: BunnyVideoReference
    private let service: BunnyStreamService
    private let session: URLSession
    private let logger = Logger(subsystem: "md.filmoteca.ios", category: "FairPlay")

    init(
        reference: BunnyVideoReference,
        refererURL: URL,
        session: URLSession = .shared
    ) {
        self.reference = reference
        self.service = BunnyStreamService(refererURL: refererURL, session: session)
        self.session = session
        super.init()
    }

    func playerItem(playlistURL: URL) -> AVPlayerItem {
        let options = ["AVURLAssetHTTPHeaderFieldsKey": service.requestHeaders]
        let asset = AVURLAsset(url: playlistURL, options: options)
        // The project uses MainActor as its default isolation. AVFoundation must
        // therefore invoke this Objective-C delegate on the main queue; using a
        // private queue triggers Swift 6's executor precondition before the
        // FairPlay request can be handled.
        asset.resourceLoader.setDelegate(self, queue: .main)
        return AVPlayerItem(asset: asset)
    }

    func resourceLoader(
        _ resourceLoader: AVAssetResourceLoader,
        shouldWaitForLoadingOfRequestedResource loadingRequest: AVAssetResourceLoadingRequest
    ) -> Bool {
        guard loadingRequest.request.url?.scheme?.lowercased() == "skd" else { return false }
        logger.info("AVFoundation requested a FairPlay content key.")

        Task { [weak self] in
            guard let self else { return }
            do {
                try await fulfill(loadingRequest)
            } catch {
                logger.error("FairPlay failed: \(error.localizedDescription, privacy: .public)")
                loadingRequest.finishLoading(with: error)
            }
        }
        return true
    }

    private func fulfill(_ loadingRequest: AVAssetResourceLoadingRequest) async throws {
        guard let skdURL = loadingRequest.request.url,
              let contentIdentifier = contentIdentifier(from: skdURL) else {
            throw BunnyPlaybackError.invalidContentIdentifier
        }

        let certificate = try await fetchCertificate()
        logger.info("FairPlay certificate loaded; generating SPC.")
        let spc = try loadingRequest.streamingContentKeyRequestData(
            forApp: certificate,
            contentIdentifier: contentIdentifier
        )
        let ckc = try await fetchLicense(spc: spc)
        logger.info("FairPlay CKC received; completing the content-key request.")
        loadingRequest.dataRequest?.respond(with: ckc)
        loadingRequest.finishLoading()
    }

    private func fetchCertificate() async throws -> Data {
        var components = fairPlayComponents(path: "/FairPlay/\(reference.libraryID)/certificate")
        components.queryItems = reference.authenticationQueryItems
        guard let url = components.url else { throw BunnyPlaybackError.invalidCertificate }

        var request = authorizedRequest(url: url)
        request.httpMethod = "GET"
        let (data, response) = try await session.data(for: request)
        try validate(response, stage: "certificat")
        logger.info("FairPlay certificate endpoint accepted the request.")
        guard !data.isEmpty else { throw BunnyPlaybackError.invalidCertificate }
        return data
    }

    private func fetchLicense(spc: Data) async throws -> Data {
        var components = fairPlayComponents(path: "/FairPlay/\(reference.libraryID)/license/")
        components.queryItems = [URLQueryItem(name: "videoId", value: reference.videoID)]
            + reference.authenticationQueryItems
        guard let url = components.url else { throw BunnyPlaybackError.invalidLicense }

        var request = authorizedRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONEncoder().encode(LicenseRequest(spc: spc.base64EncodedString()))

        let (data, response) = try await session.data(for: request)
        try validate(response, stage: "licență")
        logger.info("FairPlay license endpoint accepted the SPC.")

        if let payload = try? JSONDecoder().decode(LicenseResponse.self, from: data),
           let ckc = Data(base64Encoded: payload.ckc), !ckc.isEmpty {
            return ckc
        }
        if let string = String(data: data, encoding: .utf8),
           let ckc = Data(base64Encoded: string.trimmingCharacters(in: .whitespacesAndNewlines)),
           !ckc.isEmpty {
            return ckc
        }
        guard !data.isEmpty else { throw BunnyPlaybackError.invalidLicense }
        return data
    }

    private func authorizedRequest(url: URL) -> URLRequest {
        var request = URLRequest(url: url)
        request.timeoutInterval = 30
        service.requestHeaders.forEach { request.setValue($1, forHTTPHeaderField: $0) }
        return request
    }

    private func validate(_ response: URLResponse, stage: String) throws {
        guard let response = response as? HTTPURLResponse else {
            throw BunnyPlaybackError.invalidResponse
        }
        guard (200..<300).contains(response.statusCode) else {
            logger.error("FairPlay \(stage, privacy: .public) returned HTTP \(response.statusCode, privacy: .public).")
            throw BunnyPlaybackError.http(stage: stage, status: response.statusCode)
        }
    }

    private func fairPlayComponents(path: String) -> URLComponents {
        var components = URLComponents()
        components.scheme = "https"
        components.host = "video.bunnycdn.com"
        components.path = path
        return components
    }

    private func contentIdentifier(from url: URL) -> Data? {
        let encodedValue = (url.host ?? url.absoluteString.replacingOccurrences(of: "skd://", with: ""))
            .removingPercentEncoding ?? ""
        guard !encodedValue.isEmpty else { return nil }
        return Data(base64Encoded: encodedValue) ?? encodedValue.data(using: .utf8)
    }
}

private extension BunnyVideoReference {
    var authenticationQueryItems: [URLQueryItem] {
        var items: [URLQueryItem] = []
        if let token, !token.isEmpty { items.append(.init(name: "token", value: token)) }
        if let expires { items.append(.init(name: "expires", value: String(expires))) }
        return items
    }
}
