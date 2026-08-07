import Foundation

struct AppConfiguration: Sendable {
    let apiBaseURL: URL
    let webBaseURL: URL

    static let production = AppConfiguration(
        apiBaseURL: URL(string: "https://filmmd-api.veezify.com/api/v1")!,
        webBaseURL: URL(string: "https://filmoteca.md")!
    )

    var walletTopUpURL: URL { webBaseURL.appending(path: "dashboard").appending(queryItems: [.init(name: "tab", value: "wallet")]) }
}
