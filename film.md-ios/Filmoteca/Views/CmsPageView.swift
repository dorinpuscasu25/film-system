import SwiftUI
import WebKit

/// Renders a CMS page (terms, privacy policy, pricing policy, contact) natively
/// from the API.
///
/// App Store review requires the privacy policy and terms to be reachable inside
/// the app. Fetching the content from our own API rather than loading the public
/// website means the pages stay available even if the site is down, and they are
/// styled to match the app instead of showing a mobile browser chrome.
struct CmsPageView: View {
    let slug: String
    var fallbackTitle: String?

    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss

    @State private var state: LoadableState = .idle
    @State private var page: CmsPage?

    var body: some View {
        NavigationStack {
            Group {
                if let page, state == .loaded {
                    CmsHTMLView(html: page.content)
                        .ignoresSafeArea(edges: .bottom)
                } else if let message = state.errorMessage {
                    VStack(spacing: 16) {
                        Image(systemName: "doc.questionmark")
                            .font(.system(size: 44))
                            .foregroundStyle(FilmotecaTheme.muted)
                        Text(message)
                            .font(.footnote)
                            .foregroundStyle(FilmotecaTheme.muted)
                            .multilineTextAlignment(.center)
                        Button(app.t("retry")) { Task { await load() } }
                            .buttonStyle(GlassButtonStyle(prominent: true))
                    }
                    .padding(28)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    VStack(spacing: 14) {
                        ProgressView().tint(FilmotecaTheme.accent)
                        Text(app.t("loading_legal"))
                            .font(.caption)
                            .foregroundStyle(FilmotecaTheme.muted)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            .background(FilmotecaTheme.background)
            .navigationTitle(page?.title ?? fallbackTitle ?? "")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(app.t("close")) { dismiss() }
                }
            }
        }
        .task(id: "\(slug)-\(app.locale.rawValue)") { await load() }
    }

    private func load() async {
        state = .loading
        do {
            page = try await app.container.catalogRepository.page(slug: slug, locale: app.locale)
            state = .loaded
        } catch {
            page = nil
            state = .failed(message: error.localizedDescription)
        }
    }
}

/// Displays CMS HTML inside a `WKWebView` with app styling injected.
///
/// The markup comes from our own admin editor and can contain arbitrary
/// formatting, tables and lists, so converting it to `AttributedString` would
/// lose structure. JavaScript is disabled and navigation is confined to the
/// initial document — links open in the system browser instead.
private struct CmsHTMLView: UIViewRepresentable {
    let html: String

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = false

        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = context.coordinator
        webView.isOpaque = false
        webView.backgroundColor = .clear
        webView.scrollView.backgroundColor = .clear
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        guard context.coordinator.renderedHTML != html else { return }
        context.coordinator.renderedHTML = html
        webView.loadHTMLString(Self.document(for: html), baseURL: nil)
    }

    private static func document(for body: String) -> String {
        """
        <!doctype html>
        <html>
        <head>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
        <style>
          :root { color-scheme: dark; }
          body {
            margin: 0; padding: 18px 18px 40px;
            background: transparent;
            color: rgba(255,255,255,0.88);
            font: -apple-system-body;
            font-family: -apple-system, system-ui, sans-serif;
            line-height: 1.6;
            -webkit-text-size-adjust: 100%;
          }
          h1, h2, h3, h4 { color: #fff; line-height: 1.3; margin: 1.4em 0 0.5em; }
          h1 { font-size: 1.5em; } h2 { font-size: 1.28em; } h3 { font-size: 1.12em; }
          p, li { font-size: 1em; }
          ul, ol { padding-left: 1.2em; }
          li { margin-bottom: 0.4em; }
          a { color: #ff4d5a; text-decoration: none; }
          strong, b { color: #fff; }
          hr { border: 0; border-top: 1px solid rgba(255,255,255,0.12); margin: 1.6em 0; }
          table { width: 100%; border-collapse: collapse; display: block; overflow-x: auto; }
          th, td { border: 1px solid rgba(255,255,255,0.12); padding: 8px 10px; text-align: left; }
          th { color: #fff; background: rgba(255,255,255,0.05); }
          img { max-width: 100%; height: auto; border-radius: 10px; }
          blockquote {
            margin: 1.2em 0; padding: 0.6em 1em;
            border-left: 3px solid #ff4d5a;
            background: rgba(255,255,255,0.04);
          }
          code, pre { font-family: ui-monospace, monospace; font-size: 0.92em; }
        </style>
        </head>
        <body>\(body)</body>
        </html>
        """
    }

    final class Coordinator: NSObject, WKNavigationDelegate {
        var renderedHTML: String?

        func webView(
            _ webView: WKWebView,
            decidePolicyFor navigationAction: WKNavigationAction,
            decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
        ) {
            // The initial loadHTMLString has no URL; anything else is a tapped link.
            guard navigationAction.navigationType == .linkActivated,
                  let url = navigationAction.request.url else {
                decisionHandler(.allow)
                return
            }

            decisionHandler(.cancel)
            UIApplication.shared.open(url)
        }
    }
}
