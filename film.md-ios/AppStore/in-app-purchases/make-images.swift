import AppKit

func render(amount: String, out: String) {
    let size = 1024.0
    // Draw straight into an opaque 1024×1024 bitmap (no window server needed).
    let bitmap = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: 1024, pixelsHigh: 1024, bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false, colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0)!
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = NSGraphicsContext(bitmapImageRep: bitmap)
    let ctx = NSGraphicsContext.current!.cgContext
    let space = CGColorSpaceCreateDeviceRGB()

    // Deep background with a red light from the top, like the app.
    NSColor(red: 0.025, green: 0.027, blue: 0.035, alpha: 1).setFill()
    NSRect(x: 0, y: 0, width: size, height: size).fill()
    let glow = CGGradient(colorsSpace: space, colors: [
        NSColor(red: 0.90, green: 0.14, blue: 0.20, alpha: 0.85).cgColor,
        NSColor(red: 0.62, green: 0.05, blue: 0.12, alpha: 0.35).cgColor,
        NSColor(red: 0.025, green: 0.027, blue: 0.035, alpha: 0).cgColor,
    ] as CFArray, locations: [0, 0.45, 1])!
    ctx.drawRadialGradient(glow, startCenter: CGPoint(x: size * 0.5, y: size * 0.78), startRadius: 0,
                           endCenter: CGPoint(x: size * 0.5, y: size * 0.78), endRadius: size * 0.75, options: [])

    // A ticket-shaped card holding the amount.
    let card = NSRect(x: 132, y: 250, width: 760, height: 470)
    let path = NSBezierPath(roundedRect: card, xRadius: 64, yRadius: 64)
    ctx.saveGState()
    path.addClip()
    let cardGradient = CGGradient(colorsSpace: space, colors: [
        NSColor(red: 0.96, green: 0.20, blue: 0.24, alpha: 1).cgColor,
        NSColor(red: 0.62, green: 0.05, blue: 0.12, alpha: 1).cgColor,
    ] as CFArray, locations: [0, 1])!
    ctx.drawLinearGradient(cardGradient, start: CGPoint(x: card.minX, y: card.maxY), end: CGPoint(x: card.maxX, y: card.minY), options: [])
    ctx.restoreGState()
    NSColor(white: 1, alpha: 0.25).setStroke()
    path.lineWidth = 4
    path.stroke()
    // Ticket notches.
    NSColor(red: 0.08, green: 0.03, blue: 0.05, alpha: 1).setFill()
    for x in [card.minX, card.maxX] {
        NSBezierPath(ovalIn: NSRect(x: x - 44, y: card.midY - 44, width: 88, height: 88)).fill()
    }

    func draw(_ text: String, font: NSFont, color: NSColor, centerY: CGFloat, kern: CGFloat = 0) {
        let attrs: [NSAttributedString.Key: Any] = [.font: font, .foregroundColor: color, .kern: kern]
        let s = NSAttributedString(string: text, attributes: attrs)
        let b = s.size()
        s.draw(at: NSPoint(x: (size - b.width) / 2, y: centerY - b.height / 2))
    }
    let rounded = { (size: CGFloat, weight: NSFont.Weight) -> NSFont in
        let base = NSFont.systemFont(ofSize: size, weight: weight)
        return NSFont(descriptor: base.fontDescriptor.withDesign(.rounded) ?? base.fontDescriptor, size: size) ?? base
    }
    draw(amount, font: rounded(300, .black), color: .white, centerY: 525, kern: -8)
    draw("CREDITE", font: rounded(64, .heavy), color: NSColor(white: 1, alpha: 0.85), centerY: 340, kern: 14)
    draw("FILMOTECA.md", font: rounded(56, .black), color: .white, centerY: 140, kern: 6)

    NSGraphicsContext.restoreGraphicsState()
    // Flatten to RGB without alpha — App Store Connect rejects transparent images.
    let cg = bitmap.cgImage!
    let flat = CGContext(data: nil, width: 1024, height: 1024, bitsPerComponent: 8, bytesPerRow: 0, space: space, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)!
    flat.draw(cg, in: CGRect(x: 0, y: 0, width: 1024, height: 1024))
    let out2 = NSBitmapImageRep(cgImage: flat.makeImage()!)
    try! out2.representation(using: .png, properties: [:])!.write(to: URL(fileURLWithPath: out))
}
render(amount: "100", out: "wallet-100.png")
render(amount: "200", out: "wallet-200.png")
