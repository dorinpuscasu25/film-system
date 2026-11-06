import CoreImage
import SwiftUI
import UIKit

/// Finds the dominant colour of a poster or backdrop so a screen can glow with it.
///
/// The image normally comes straight from `URLCache` (the screen already loaded it), it is
/// averaged on a tiny downscaled copy, and the result is cached per URL — cheap enough to
/// call on every hero slide change.
actor ArtworkColorSampler {
    static let shared = ArtworkColorSampler()

    private var cache: [URL: Color] = [:]
    private let context = CIContext(options: [.workingColorSpace: NSNull()])

    func color(for url: URL?) async -> Color? {
        guard let url else { return nil }
        if let cached = cache[url] { return cached }

        var request = URLRequest(url: url)
        request.cachePolicy = .returnCacheDataElseLoad
        guard let (data, _) = try? await URLSession.shared.data(for: request),
              let image = CIImage(data: data, options: [.applyOrientationProperty: true]) else { return nil }

        // Ignore the bottom third: titles and credits there drag the average to grey.
        let extent = image.extent
        let region = CGRect(x: extent.minX, y: extent.minY + extent.height / 3, width: extent.width, height: extent.height * 2 / 3)
        guard let filter = CIFilter(name: "CIAreaAverage", parameters: [kCIInputImageKey: image, kCIInputExtentKey: CIVector(cgRect: region)]),
              let output = filter.outputImage else { return nil }

        var pixel = [UInt8](repeating: 0, count: 4)
        context.render(output, toBitmap: &pixel, rowBytes: 4, bounds: CGRect(x: 0, y: 0, width: 1, height: 1), format: .RGBA8, colorSpace: nil)

        let color = Self.vivid(red: CGFloat(pixel[0]) / 255, green: CGFloat(pixel[1]) / 255, blue: CGFloat(pixel[2]) / 255)
        cache[url] = color
        return color
    }

    /// An average is always muddy; push it to a saturated, mid-bright tone that reads as light.
    private static func vivid(red: CGFloat, green: CGFloat, blue: CGFloat) -> Color {
        var hue: CGFloat = 0, saturation: CGFloat = 0, brightness: CGFloat = 0, alpha: CGFloat = 0
        UIColor(red: red, green: green, blue: blue, alpha: 1).getHue(&hue, saturation: &saturation, brightness: &brightness, alpha: &alpha)
        // Near-grey artwork has no real hue; fall back to the brand red instead of a random one.
        if saturation < 0.08 { return Color(red: 0.90, green: 0.14, blue: 0.20) }
        return Color(hue: Double(hue), saturation: Double(min(1, max(saturation * 1.6, 0.55))), brightness: Double(min(0.85, max(brightness * 1.3, 0.55))))
    }
}
