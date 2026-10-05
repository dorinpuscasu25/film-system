import Foundation

struct AppConfiguration: Sendable {
    let apiBaseURL: URL
    let webBaseURL: URL

    static let production = AppConfiguration(
        apiBaseURL: URL(string: "https://filmmd-api.veezify.com/api/v1")!,
        webBaseURL: URL(string: "https://filmoteca.md")!
    )

    /// Debug builds can point at a local backend by setting `FILMOTECA_API_BASE_URL` in the
    /// scheme (Run → Arguments → Environment Variables), e.g. `http://localhost:8000/api/v1`.
    /// Needed to test purchases with the local `Filmoteca.storekit` file end to end: the
    /// production backend deliberately refuses Xcode/LocalTesting transactions because they
    /// aren't signed by Apple. Release builds always use production.
    static var current: AppConfiguration {
        #if DEBUG
        if let override = ProcessInfo.processInfo.environment["FILMOTECA_API_BASE_URL"],
           !override.isEmpty,
           let url = URL(string: override) {
            return AppConfiguration(apiBaseURL: url, webBaseURL: production.webBaseURL)
        }
        #endif
        return production
    }
}
