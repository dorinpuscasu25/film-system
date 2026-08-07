import SwiftUI

enum FilmotecaTheme {
    static let background = Color(red: 0.025, green: 0.027, blue: 0.035)
    static let surface = Color(red: 0.075, green: 0.078, blue: 0.095)
    static let elevated = Color(red: 0.11, green: 0.115, blue: 0.135)
    static let accent = Color(red: 0.90, green: 0.14, blue: 0.20)
    static let gold = Color(red: 0.98, green: 0.73, blue: 0.23)
    static let muted = Color.white.opacity(0.62)
    static let hairline = Color.white.opacity(0.10)
    static let webBaseURL = URL(string: "https://filmoteca.md")!
    static let topUpURL = URL(string: "https://filmoteca.md/dashboard?tab=wallet")!

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
            .padding(.horizontal, 18)
            .frame(minHeight: 48)
            .background(prominent ? FilmotecaTheme.accent : Color.white.opacity(0.12), in: Capsule())
            .overlay(Capsule().stroke(Color.white.opacity(prominent ? 0 : 0.16)))
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
