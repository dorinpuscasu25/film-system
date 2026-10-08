import SwiftUI
import UIKit

/// Full-screen brand intro: no controls, no loop, nothing on top except — after a long wait —
/// a discreet spinner under the logo. Tap anywhere to skip.
struct IntroView: View {
    let controller: IntroController

    var body: some View {
        GeometryReader { proxy in
            ZStack {
                IntroLayerHost(controller: controller)
                if controller.showsLoadingIndicator {
                    ProgressView()
                        .tint(.white.opacity(0.7))
                        .position(x: proxy.size.width / 2, y: proxy.size.height / 2 + max(proxy.size.height, proxy.size.width) * 0.09)
                        .transition(.opacity)
                }
            }
            .animation(.easeIn(duration: 0.4), value: controller.showsLoadingIndicator)
        }
        .background(Color.black)
        .ignoresSafeArea()
        .contentShape(Rectangle())
        .onTapGesture { controller.skip() }
        .onAppear { controller.start() }
        .accessibilityElement()
        .accessibilityLabel("filmoteca.md")
        .accessibilityAddTraits(.isButton)
        .accessibilityAction { controller.skip() }
    }
}

private struct IntroLayerHost: UIViewRepresentable {
    let controller: IntroController

    func makeUIView(context: Context) -> IntroLayerView {
        IntroLayerView(playerLayer: controller.playerLayer, finalFrameLayer: controller.finalFrameLayer)
    }

    func updateUIView(_ uiView: IntroLayerView, context: Context) {}
}

/// Hosts the player and the final-frame PNG with identical aspect-fill geometry, so the
/// handover between them is pixel-exact.
final class IntroLayerView: UIView {
    private let sublayers: [CALayer]

    init(playerLayer: CALayer, finalFrameLayer: CALayer) {
        sublayers = [playerLayer, finalFrameLayer]
        super.init(frame: .zero)
        backgroundColor = .black
        isUserInteractionEnabled = false
        sublayers.forEach(layer.addSublayer)
    }

    required init?(coder: NSCoder) { fatalError("init(coder:) has not been implemented") }

    override func layoutSubviews() {
        super.layoutSubviews()
        CATransaction.begin()
        CATransaction.setDisableActions(true)
        sublayers.forEach { $0.frame = bounds }
        CATransaction.commit()
    }
}
