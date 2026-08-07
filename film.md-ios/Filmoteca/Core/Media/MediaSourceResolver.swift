import Foundation

enum MediaPlaybackSource: Equatable {
    case native(URL)
    case embedded(embedURL: URL, originalURL: URL)
    case bunny(BunnyVideoReference)

    var originalURL: URL {
        switch self {
        case .native(let url): url
        case .embedded(_, let originalURL): originalURL
        case .bunny(let reference): reference.originalURL
        }
    }
}

struct BunnyVideoReference: Equatable {
    let libraryID: Int
    let videoID: String
    let token: String?
    let expires: Int64?
    let embedURL: URL
    let originalURL: URL
}

enum MediaSourceResolver {
    static func trailer(_ url: URL) -> MediaPlaybackSource {
        if isDirectMediaURL(url) { return .native(url) }
        if let embedURL = youtubeEmbedURL(from: url) ?? bunnyEmbedURL(from: url) {
            return .embedded(embedURL: embedURL, originalURL: url)
        }
        return .embedded(embedURL: url, originalURL: url)
    }

    static func playback(
        _ url: URL,
        explicitEmbedURL: URL?,
        bunnyToken: String? = nil,
        bunnyExpires: Int64? = nil
    ) -> MediaPlaybackSource {
        if let reference = bunnyReference(
            from: explicitEmbedURL ?? url,
            originalURL: url,
            suppliedToken: bunnyToken,
            suppliedExpires: bunnyExpires
        ) {
            return .bunny(reference)
        }
        if let explicitEmbedURL {
            return .embedded(embedURL: explicitEmbedURL, originalURL: url)
        }
        if let embedURL = youtubeEmbedURL(from: url) ?? bunnyEmbedURL(from: url) {
            return .embedded(embedURL: embedURL, originalURL: url)
        }
        return .native(url)
    }

    private static func isDirectMediaURL(_ url: URL) -> Bool {
        ["m3u8", "mpd", "mp4", "webm", "ogg", "mov"].contains(url.pathExtension.lowercased())
    }

    private static func youtubeEmbedURL(from url: URL) -> URL? {
        guard let host = url.host?.lowercased().replacingOccurrences(of: "www.", with: "") else { return nil }
        let parts = url.pathComponents.filter { $0 != "/" }
        let videoID: String?

        switch host {
        case "youtu.be":
            videoID = parts.first
        case "youtube.com", "m.youtube.com", "youtube-nocookie.com":
            if url.path == "/watch" {
                videoID = URLComponents(url: url, resolvingAgainstBaseURL: false)?
                    .queryItems?.first(where: { $0.name == "v" })?.value
            } else if let first = parts.first, ["embed", "shorts", "live"].contains(first) {
                videoID = parts.dropFirst().first
            } else {
                videoID = nil
            }
        default:
            videoID = nil
        }

        guard let videoID, !videoID.isEmpty else { return nil }
        var components = URLComponents()
        components.scheme = "https"
        components.host = "www.youtube-nocookie.com"
        components.path = "/embed/\(videoID)"
        components.queryItems = [
            URLQueryItem(name: "autoplay", value: "1"),
            URLQueryItem(name: "playsinline", value: "1"),
            URLQueryItem(name: "rel", value: "0"),
            URLQueryItem(name: "modestbranding", value: "1"),
        ]
        return components.url
    }

    private static func bunnyEmbedURL(from url: URL) -> URL? {
        if url.host?.lowercased() == "iframe.mediadelivery.net", url.path.contains("/embed/") {
            return url
        }
        guard url.host?.lowercased() == "video.bunnycdn.com" else { return nil }
        let parts = url.pathComponents.filter { $0 != "/" }
        guard parts.count >= 2, Int(parts[0]) != nil else { return nil }

        var components = URLComponents()
        components.scheme = "https"
        components.host = "iframe.mediadelivery.net"
        components.path = "/embed/\(parts[0])/\(parts[1])"
        components.queryItems = [
            URLQueryItem(name: "autoplay", value: "true"),
            URLQueryItem(name: "responsive", value: "true"),
        ]
        return components.url
    }

    private static func bunnyReference(
        from url: URL,
        originalURL: URL,
        suppliedToken: String? = nil,
        suppliedExpires: Int64? = nil
    ) -> BunnyVideoReference? {
        guard url.host?.lowercased() == "iframe.mediadelivery.net" else { return nil }
        let parts = url.pathComponents.filter { $0 != "/" }
        guard let embedIndex = parts.firstIndex(of: "embed"),
              parts.indices.contains(embedIndex + 2),
              let libraryID = Int(parts[embedIndex + 1]) else { return nil }

        let videoID = parts[embedIndex + 2]
        guard !videoID.isEmpty else { return nil }
        let embedQueryItems = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems ?? []
        let streamQueryItems = URLComponents(url: originalURL, resolvingAgainstBaseURL: false)?.queryItems ?? []
        let queryItems = embedQueryItems + streamQueryItems
        let token = suppliedToken ?? queryItems.first(where: { $0.name == "token" })?.value
        let expires = suppliedExpires
            ?? queryItems.first(where: { $0.name == "expires" })?.value.flatMap(Int64.init)
        let authenticatedEmbedURL = addingAuthentication(token: token, expires: expires, to: url)

        return BunnyVideoReference(
            libraryID: libraryID,
            videoID: videoID,
            token: token,
            expires: expires,
            embedURL: authenticatedEmbedURL,
            originalURL: originalURL
        )
    }

    private static func addingAuthentication(token: String?, expires: Int64?, to url: URL) -> URL {
        guard let token, !token.isEmpty, let expires,
              var components = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return url }
        var queryItems = components.queryItems ?? []
        queryItems.removeAll { $0.name == "token" || $0.name == "expires" }
        queryItems.append(URLQueryItem(name: "token", value: token))
        queryItems.append(URLQueryItem(name: "expires", value: String(expires)))
        components.queryItems = queryItems
        return components.url ?? url
    }
}
