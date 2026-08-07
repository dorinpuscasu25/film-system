import AVKit
import SwiftUI
import WebKit

struct PlayerRequest: Identifiable {
    let id = UUID()
    let title: String
    let source: MediaPlaybackSource
    let startPosition: Double
    let contentSlug: String?
    var tracking: PlayerTrackingContext? = nil
}

struct PlayerView: View {
    @Environment(\.dismiss) private var dismiss
    @State private var viewModel: PlayerViewModel
    @State private var bunnyWebFallbackURL: URL?

    init(request: PlayerRequest, container: AppContainer) {
        _viewModel = State(initialValue: PlayerViewModel(request: request, container: container))
    }

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            switch viewModel.request.source {
            case .native:
                VideoPlayer(player: viewModel.player).ignoresSafeArea()
            case .embedded(let embedURL, _):
                EmbeddedVideoPlayer(url: embedURL).ignoresSafeArea()
            case .bunny(let reference):
                if let bunnyWebFallbackURL {
                    EmbeddedVideoPlayer(url: bunnyWebFallbackURL).ignoresSafeArea()
                } else {
                    bunnyPlayer
                        .onChange(of: viewModel.loadingState) { _, state in
                            guard case .failed = state else { return }
                            viewModel.stop()
                            withAnimation(.easeInOut(duration: 0.25)) {
                                bunnyWebFallbackURL = reference.embedURL
                            }
                        }
                }
            }

            VStack {
                HStack(spacing: 13) {
                    Button { dismiss() } label: {
                        Image(systemName: "xmark")
                            .font(.headline.weight(.bold))
                            .frame(width: 44, height: 44)
                            .background(.black.opacity(0.7), in: Circle())
                            .overlay(Circle().stroke(.white.opacity(0.18)))
                    }
                    .accessibilityLabel("Închide playerul")

                    Text(viewModel.request.title)
                        .font(.headline)
                        .lineLimit(1)
                        .shadow(radius: 5)
                    Spacer()

                    if case .embedded(_, let originalURL) = viewModel.request.source {
                        Link(destination: originalURL) {
                            Image(systemName: "arrow.up.right.square")
                                .font(.headline)
                                .frame(width: 44, height: 44)
                                .background(.black.opacity(0.7), in: Circle())
                        }
                        .accessibilityLabel("Deschide sursa video")
                    } else if case .native = viewModel.request.source {
                        AirPlayButton().frame(width: 38, height: 38)
                    }
                }
                .padding(.horizontal, 16)
                .padding(.top, 8)
                Spacer()
            }
            .zIndex(10)
        }
        .statusBarHidden()
        .onAppear { viewModel.start() }
        .onDisappear { viewModel.stop() }
    }

    @ViewBuilder
    private var bunnyPlayer: some View {
        switch viewModel.loadingState {
        case .failed(let message):
            VStack(spacing: 18) {
                Image(systemName: "exclamationmark.triangle.fill")
                    .font(.system(size: 34))
                    .foregroundStyle(FilmotecaTheme.accent)
                Text("Video-ul nu a putut porni")
                    .font(.headline)
                Text(message)
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .frame(maxWidth: 320)
            }
            .padding(24)
        default:
            ZStack {
                if let player = viewModel.player {
                    VideoPlayer(player: player).ignoresSafeArea()
                }
                if viewModel.loadingState != .ready {
                    VStack(spacing: 18) {
                        ProgressView()
                            .tint(.white)
                        Text("Se pregătește redarea securizată…")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                    .padding(24)
                }
            }
        }
    }
}

private struct EmbeddedVideoPlayer: UIViewRepresentable {
    let url: URL

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.allowsInlineMediaPlayback = true
        configuration.mediaTypesRequiringUserActionForPlayback = []

        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.isOpaque = false
        webView.backgroundColor = .black
        webView.scrollView.backgroundColor = .black
        webView.scrollView.isScrollEnabled = false
        load(url, in: webView)
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {}

    static func dismantleUIView(_ webView: WKWebView, coordinator: Void) {
        webView.stopLoading()
        webView.loadHTMLString("", baseURL: nil)
    }

    private func load(_ url: URL, in webView: WKWebView) {
        let escapedURL = url.absoluteString
            .replacingOccurrences(of: "&", with: "&amp;")
            .replacingOccurrences(of: "\"", with: "&quot;")
        let html = """
        <!doctype html>
        <html><head>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <style>html,body,iframe{margin:0;width:100%;height:100%;background:#000;border:0;overflow:hidden}</style>
        </head><body>
        <iframe src="\(escapedURL)" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
        </body></html>
        """
        webView.loadHTMLString(html, baseURL: FilmotecaTheme.webBaseURL)
    }
}

private struct AirPlayButton: UIViewRepresentable {
    func makeUIView(context: Context) -> AVRoutePickerView { let view = AVRoutePickerView(); view.tintColor = .white; view.activeTintColor = UIColor(FilmotecaTheme.accent); return view }
    func updateUIView(_ uiView: AVRoutePickerView, context: Context) {}
}
