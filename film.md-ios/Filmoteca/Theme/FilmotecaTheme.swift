import SwiftUI

enum FilmotecaTheme {
    static let background = Color(red: 0.025, green: 0.027, blue: 0.035)
    static let surface = Color(red: 0.075, green: 0.078, blue: 0.095)
    static let elevated = Color(red: 0.11, green: 0.115, blue: 0.135)
    static let accent = Color(red: 0.90, green: 0.14, blue: 0.20)
    static let gold = Color(red: 0.98, green: 0.73, blue: 0.23)
    /// Deep cinema red the brand red falls into; the palette stays in one red family.
    static let crimson = Color(red: 0.62, green: 0.05, blue: 0.12)
    static let wine = Color(red: 0.36, green: 0.03, blue: 0.08)
    static let mint = Color(red: 0.20, green: 0.75, blue: 0.50)
    /// The brand's signature sweep — bright red into crimson — for primary actions and highlights.
    static let brandGradient = LinearGradient(colors: [Color(red: 0.96, green: 0.20, blue: 0.24), crimson], startPoint: .topLeading, endPoint: .bottomTrailing)
    static let goldGradient = LinearGradient(colors: [gold, Color(red: 0.86, green: 0.60, blue: 0.14)], startPoint: .top, endPoint: .bottom)
    /// Shades for genre tiles the admin hasn't given a colour.
    static let redShades: [Color] = [
        accent,
        Color(red: 0.70, green: 0.07, blue: 0.15),
        Color(red: 0.52, green: 0.05, blue: 0.13),
        Color(red: 0.84, green: 0.22, blue: 0.22),
        Color(red: 0.42, green: 0.06, blue: 0.10),
    ]
    static let muted = Color.white.opacity(0.62)
    static let hairline = Color.white.opacity(0.10)
    static let webBaseURL = URL(string: "https://filmoteca.md")!

    static func titleFont(_ style: Font.TextStyle = .title3, weight: Font.Weight = .bold) -> Font {
        .system(style, design: .serif).weight(weight)
    }
}

extension View {
    func filmotecaTitle(_ style: Font.TextStyle = .title3, weight: Font.Weight = .bold) -> some View {
        font(FilmotecaTheme.titleFont(style, weight: weight))
    }
}

struct FilmotecaWordmark: View {
    var compact = false

    var body: some View {
        HStack(spacing: 0) {
            Text(compact ? "F" : "FILMOTECA")
                .font(.system(size: compact ? 22 : 17, weight: .black, design: .rounded))
                .tracking(compact ? -1 : 1.8)
            Text(".").foregroundStyle(FilmotecaTheme.accent)
            if !compact { Text("md").fontWeight(.bold) }
        }
        .foregroundStyle(.white)
        .accessibilityLabel("Filmoteca punct md")
    }
}

struct GlassButtonStyle: ButtonStyle {
    var prominent = false

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.subheadline.weight(.bold))
            .foregroundStyle(.white)
            // A capsule label must never break mid-word ("Autentifi-care").
            .lineLimit(1)
            .minimumScaleFactor(0.8)
            .fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 18)
            .frame(minHeight: 48)
            .background {
                if prominent {
                    Capsule().fill(FilmotecaTheme.brandGradient)
                        .shadow(color: FilmotecaTheme.accent.opacity(configuration.isPressed ? 0.15 : 0.45), radius: 14, y: 6)
                } else {
                    Capsule().fill(.ultraThinMaterial).overlay(Capsule().fill(Color.white.opacity(0.06)))
                }
            }
            .overlay(Capsule().stroke(Color.white.opacity(prominent ? 0.22 : 0.16)))
            .scaleEffect(configuration.isPressed ? 0.96 : 1)
            .opacity(configuration.isPressed ? 0.82 : 1)
            .animation(.spring(response: 0.28, dampingFraction: 0.72), value: configuration.isPressed)
    }
}

extension View {
    func filmotecaBackground() -> some View { background(FilmotecaTheme.background.ignoresSafeArea()) }
    func filmotecaReveal(delay: Double = 0) -> some View {
        modifier(FilmotecaRevealModifier(delay: delay))
    }
    func shimmer() -> some View {
        overlay {
            LinearGradient(colors: [.clear, .white.opacity(0.10), .clear], startPoint: .leading, endPoint: .trailing)
        }
    }
}

private struct FilmotecaRevealModifier: ViewModifier {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isVisible = false
    let delay: Double

    func body(content: Self.Content) -> some View {
        content
            .opacity(isVisible ? 1 : 0)
            .scaleEffect(isVisible || reduceMotion ? 1 : 0.975)
            .offset(y: isVisible || reduceMotion ? 0 : 14)
            .onAppear {
                guard !isVisible else { return }
                withAnimation(reduceMotion ? .linear(duration: 0.15) : .spring(response: 0.48, dampingFraction: 0.84).delay(delay)) {
                    isVisible = true
                }
            }
    }
}

/// Poster and tile buttons: a soft press-in with a light tap, so the catalog feels touchable.
struct PressableCardStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.95 : 1)
            .brightness(configuration.isPressed ? -0.06 : 0)
            .animation(.spring(response: 0.3, dampingFraction: 0.65), value: configuration.isPressed)
            .sensoryFeedback(.impact(weight: .light), trigger: configuration.isPressed) { _, pressed in pressed }
    }
}

/// A rounded square with a coloured fill behind an SF Symbol, like the Settings app.
struct IconTile: View {
    let systemName: String
    var color: Color = FilmotecaTheme.accent
    var size: CGFloat = 30

    var body: some View {
        Image(systemName: systemName)
            .font(.system(size: size * 0.5, weight: .semibold))
            .foregroundStyle(.white)
            .frame(width: size, height: size)
            .background(
                LinearGradient(colors: [color, color.mix(with: .black, by: 0.25)], startPoint: .top, endPoint: .bottom),
                in: RoundedRectangle(cornerRadius: size * 0.28, style: .continuous)
            )
    }
}

/// A slow wash of colour behind a screen, taken from whatever artwork leads it.
struct AmbientBackdrop: View {
    var color: Color?

    var body: some View {
        let tint = color ?? FilmotecaTheme.accent.opacity(0.6)
        ZStack {
            FilmotecaTheme.background
            RadialGradient(colors: [tint.opacity(0.55), tint.opacity(0.18), .clear], center: .top, startRadius: 10, endRadius: 620)
            RadialGradient(colors: [FilmotecaTheme.crimson.opacity(0.14), .clear], center: .bottomTrailing, startRadius: 0, endRadius: 420)
        }
        .animation(.easeInOut(duration: 0.9), value: color)
        .ignoresSafeArea()
        .allowsHitTesting(false)
    }
}

extension Color {
    /// Blends toward another colour (iOS 18 has this built in; the app supports 17).
    func mix(with other: Color, by amount: Double) -> Color {
        let a = UIColor(self), b = UIColor(other)
        var (r1, g1, b1, a1): (CGFloat, CGFloat, CGFloat, CGFloat) = (0, 0, 0, 0)
        var (r2, g2, b2, a2): (CGFloat, CGFloat, CGFloat, CGFloat) = (0, 0, 0, 0)
        a.getRed(&r1, green: &g1, blue: &b1, alpha: &a1)
        b.getRed(&r2, green: &g2, blue: &b2, alpha: &a2)
        let t = CGFloat(max(0, min(1, amount)))
        return Color(red: r1 + (r2 - r1) * t, green: g1 + (g2 - g1) * t, blue: b1 + (b2 - b1) * t, opacity: a1 + (a2 - a1) * t)
    }
}

/// How a genre tile looks. The colour comes from the admin (Taxonomies → colour); genres
/// without one get a shade of the brand red, so new genres never need an app update.
enum GenreStyle {
    static func gradient(hex: String?, index: Int) -> LinearGradient {
        let base = hex.flatMap(Color.init(hex:)) ?? FilmotecaTheme.redShades[abs(index) % FilmotecaTheme.redShades.count]
        return LinearGradient(colors: [base, base.mix(with: .black, by: 0.45)], startPoint: .topLeading, endPoint: .bottomTrailing)
    }

    /// For places that only know the genre's name (the title page): a stable red shade.
    static func gradient(forName name: String) -> LinearGradient {
        gradient(hex: nil, index: name.unicodeScalars.reduce(0) { ($0 &* 31 &+ Int($1.value)) & 0xFFFF })
    }
}

extension Color {
    /// `#RRGGBB`, as stored by the admin.
    init?(hex: String) {
        let digits = hex.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: "#", with: "")
        guard digits.count == 6, let value = UInt32(digits, radix: 16) else { return nil }
        self.init(red: Double((value >> 16) & 0xFF) / 255, green: Double((value >> 8) & 0xFF) / 255, blue: Double(value & 0xFF) / 255)
    }
}
