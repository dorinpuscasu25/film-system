// Builds the iOS app icons from the brand's 3D render (red "F" on black, logo-3d.webp).
// Usage (from film.md-ios/): swift AppStore/app-icon/make-app-icon.swift
//
// The render's background is pure black, so the canvas is padded with black to give the
// mark breathing room inside the iOS icon mask.
import AppKit
import CoreGraphics

let here = URL(fileURLWithPath: #filePath).deletingLastPathComponent()
let source = here.appending(path: "logo-3d.webp")
let output = here.deletingLastPathComponent().deletingLastPathComponent()
    .appending(path: "Filmoteca/Assets.xcassets/AppIcon.appiconset")

let size = 1024
/// Logo body in the render (pixels): centre and height, measured on logo-3d.webp.
let logoCenter = CGPoint(x: 720, y: 565)
let logoHeight: CGFloat = 870
/// Share of the icon height the logo body takes.
let logoShare: CGFloat = 0.70

guard let image = NSImage(contentsOf: source),
      let cgImage = image.cgImage(forProposedRect: nil, context: nil, hints: nil) else {
    fatalError("Can't read \(source.path)")
}

func render(grayscale: Bool) -> CGImage {
    let space = grayscale ? CGColorSpaceCreateDeviceGray() : CGColorSpace(name: CGColorSpace.sRGB)!
    let info = grayscale ? CGImageAlphaInfo.none.rawValue : CGImageAlphaInfo.noneSkipLast.rawValue
    let ctx = CGContext(data: nil, width: size, height: size, bitsPerComponent: 8, bytesPerRow: 0, space: space, bitmapInfo: info)!
    ctx.interpolationQuality = .high
    ctx.setFillColor(CGColor(gray: 0, alpha: 1))
    ctx.fill(CGRect(x: 0, y: 0, width: size, height: size))

    let scale = CGFloat(size) * logoShare / logoHeight
    let width = CGFloat(cgImage.width) * scale
    let height = CGFloat(cgImage.height) * scale
    // CoreGraphics is y-up; the measurements are y-down.
    let x = CGFloat(size) / 2 - logoCenter.x * scale
    let yTop = CGFloat(size) / 2 - logoCenter.y * scale
    ctx.draw(cgImage, in: CGRect(x: x, y: CGFloat(size) - yTop - height, width: width, height: height))
    return ctx.makeImage()!
}

func write(_ image: CGImage, _ name: String) {
    let data = NSBitmapImageRep(cgImage: image).representation(using: .png, properties: [:])!
    try! data.write(to: output.appending(path: name))
    print("wrote \(name)")
}

let color = render(grayscale: false)
write(color, "AppIcon.png")
write(color, "AppIcon-Dark.png")          // already dark: same artwork
write(render(grayscale: true), "AppIcon-Tinted.png")
