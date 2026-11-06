import AppKit

// Usage: compose.swift <width> <height> <input.png> <output.png> <title> <subtitle>
let a = CommandLine.arguments
let W = CGFloat(Double(a[1])!), H = CGFloat(Double(a[2])!)
let input = NSImage(contentsOfFile: a[3])!.cgImage(forProposedRect: nil, context: nil, hints: nil)!
let out = a[4], title = a[5], subtitle = a[6]
let space = CGColorSpaceCreateDeviceRGB()

let bitmap = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: Int(W), pixelsHigh: Int(H), bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false, colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0)!
NSGraphicsContext.saveGraphicsState()
NSGraphicsContext.current = NSGraphicsContext(bitmapImageRep: bitmap)
let ctx = NSGraphicsContext.current!.cgContext

// Background: the app's near-black with a red light at the top.
NSColor(red: 0.025, green: 0.027, blue: 0.035, alpha: 1).setFill()
NSRect(x: 0, y: 0, width: W, height: H).fill()
let glow = CGGradient(colorsSpace: space, colors: [
    NSColor(red: 0.90, green: 0.14, blue: 0.20, alpha: 0.75).cgColor,
    NSColor(red: 0.62, green: 0.05, blue: 0.12, alpha: 0.25).cgColor,
    NSColor(red: 0.025, green: 0.027, blue: 0.035, alpha: 0).cgColor,
] as CFArray, locations: [0, 0.5, 1])!
ctx.drawRadialGradient(glow, startCenter: CGPoint(x: W / 2, y: H), startRadius: 0, endCenter: CGPoint(x: W / 2, y: H), endRadius: H * 0.62, options: [])

func font(_ size: CGFloat, _ weight: NSFont.Weight) -> NSFont {
    let base = NSFont.systemFont(ofSize: size, weight: weight)
    return NSFont(descriptor: base.fontDescriptor.withDesign(.rounded) ?? base.fontDescriptor, size: size) ?? base
}
func drawCentered(_ text: String, _ f: NSFont, _ color: NSColor, top: CGFloat, maxWidth: CGFloat) -> CGFloat {
    let style = NSMutableParagraphStyle(); style.alignment = .center; style.lineSpacing = f.pointSize * 0.06
    let s = NSAttributedString(string: text, attributes: [.font: f, .foregroundColor: color, .paragraphStyle: style])
    let bounds = s.boundingRect(with: NSSize(width: maxWidth, height: 2000), options: [.usesLineFragmentOrigin])
    s.draw(with: NSRect(x: (W - maxWidth) / 2, y: H - top - bounds.height, width: maxWidth, height: bounds.height), options: [.usesLineFragmentOrigin])
    return bounds.height
}

let scale = W / 1320
var y = 150 * scale
y += drawCentered(title, font(96 * scale, .heavy), .white, top: y, maxWidth: W - 140 * scale) + 28 * scale
y += drawCentered(subtitle, font(46 * scale, .medium), NSColor(white: 1, alpha: 0.72), top: y, maxWidth: W - 200 * scale)

// The app screenshot in a rounded "device" frame, running off the bottom edge.
let shotTop = y + 90 * scale
let shotWidth = W * 0.80
let shotHeight = shotWidth * CGFloat(input.height) / CGFloat(input.width)
let rect = CGRect(x: (W - shotWidth) / 2, y: H - shotTop - shotHeight, width: shotWidth, height: shotHeight)
let radius = 70 * scale
let frame = rect.insetBy(dx: -14 * scale, dy: -14 * scale)
ctx.saveGState()
ctx.setShadow(offset: CGSize(width: 0, height: -20 * scale), blur: 80 * scale, color: NSColor(red: 0.9, green: 0.1, blue: 0.2, alpha: 0.35).cgColor)
NSColor(red: 0.12, green: 0.12, blue: 0.14, alpha: 1).setFill()
NSBezierPath(roundedRect: frame, xRadius: radius + 14 * scale, yRadius: radius + 14 * scale).fill()
ctx.restoreGState()
ctx.saveGState()
NSBezierPath(roundedRect: rect, xRadius: radius, yRadius: radius).addClip()
ctx.draw(input, in: rect)
ctx.restoreGState()

NSGraphicsContext.restoreGraphicsState()
let flat = CGContext(data: nil, width: Int(W), height: Int(H), bitsPerComponent: 8, bytesPerRow: 0, space: space, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)!
flat.draw(bitmap.cgImage!, in: CGRect(x: 0, y: 0, width: W, height: H))
try! NSBitmapImageRep(cgImage: flat.makeImage()!).representation(using: .png, properties: [:])!.write(to: URL(fileURLWithPath: out))
