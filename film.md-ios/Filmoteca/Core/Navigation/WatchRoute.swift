import Foundation

/// Navigation value for "Watch" buttons outside the detail page: opens the detail page and
/// immediately starts playback there (which handles sign-in, purchase and profile rules).
/// Plain `Content` values keep opening the detail page without autoplay.
struct WatchRoute: Hashable {
    let content: Content
}
