import Foundation
import OSLog

/// A selectable linear ad returned by our VMAP endpoint.
struct AdCreative: Equatable {
    let mediaURL: URL
    let durationSeconds: Double
    /// Seconds before a skip button may appear; `nil` means unskippable.
    let skipOffsetSeconds: Double?
    let clickThroughURL: URL?
    let adTitle: String?
    /// VAST event name -> beacons to ping.
    let tracking: [String: [URL]]
}

/// One scheduled ad break within a playback.
struct AdBreak: Equatable, Identifiable {
    enum Placement: String {
        case preRoll = "pre-roll"
        case midRoll = "mid-roll"
        case postRoll = "post-roll"
    }

    let id = UUID()
    let placement: Placement
    /// Seconds into the content; 0 for pre-roll, `.infinity` for post-roll.
    let timeOffsetSeconds: Double
    let creative: AdCreative

    static func == (lhs: AdBreak, rhs: AdBreak) -> Bool { lhs.id == rhs.id }
}

/// Fetches and parses the VMAP playlist, and fires VAST tracking beacons.
///
/// `AVPlayer` has no VAST support, so breaks are scheduled by the player view
/// model and the creatives are played as separate player items. The web client
/// does the same against the same endpoint, which keeps one ad pipeline and one
/// set of campaign numbers across platforms.
actor AdService {
    private let baseURL: URL
    private let session: URLSession
    private let logger = Logger(subsystem: "md.filmoteca.ios", category: "Ads")

    init(baseURL: URL, session: URLSession = .shared) {
        self.baseURL = baseURL
        self.session = session
    }

    /// Returns the breaks for one playback, or an empty list on any failure —
    /// a broken ad server must never stop a paid film from playing.
    func breaks(
        contentID: String,
        sessionToken: String?,
        accountProfileID: String?,
        authToken: String?
    ) async -> [AdBreak] {
        var components = URLComponents(url: baseURL.appending(path: "playback/breaks"), resolvingAgainstBaseURL: false)
        var query = [
            URLQueryItem(name: "content", value: contentID),
            URLQueryItem(name: "platform", value: "ios"),
            URLQueryItem(name: "group", value: "movies"),
        ]
        if let sessionToken { query.append(.init(name: "session", value: sessionToken)) }
        if let accountProfileID { query.append(.init(name: "account_profile_id", value: accountProfileID)) }
        components?.queryItems = query

        guard let url = components?.url else { return [] }

        var request = URLRequest(url: url)
        request.timeoutInterval = 10
        request.setValue("application/xml", forHTTPHeaderField: "Accept")
        // Targeting on viewer maturity needs the caller identified.
        if let authToken { request.setValue("Bearer \(authToken)", forHTTPHeaderField: "Authorization") }

        do {
            let (data, response) = try await session.data(for: request)
            guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode), !data.isEmpty else {
                return []
            }
            return VmapParser.parse(data)
        } catch {
            logger.info("Ad breaks unavailable: \(error.localizedDescription, privacy: .public)")
            return []
        }
    }

    /// Best-effort beacons; failures are never surfaced to the viewer.
    nonisolated func fireTracking(_ creative: AdCreative, event: String) {
        guard let urls = creative.tracking[event], !urls.isEmpty else { return }

        for url in urls {
            var request = URLRequest(url: url)
            request.httpMethod = "GET"
            request.timeoutInterval = 5
            URLSession.shared.dataTask(with: request).resume()
        }
    }
}

/// SAX parser for VMAP documents with inline VAST.
///
/// `XMLDocument` is macOS-only, so this walks the tree with `XMLParser` and
/// collects just the fields the player needs.
private nonisolated final class VmapParser: NSObject, XMLParserDelegate {
    static func parse(_ data: Data) -> [AdBreak] {
        let parser = VmapParser()
        let xml = XMLParser(data: data)
        xml.delegate = parser
        xml.shouldProcessNamespaces = true
        guard xml.parse() else { return [] }
        return parser.breaks
    }

    private var breaks: [AdBreak] = []

    // Current AdBreak being assembled.
    private var placement: AdBreak.Placement?
    private var timeOffsetRaw: String?

    // Current creative being assembled.
    private var mediaURL: String?
    private var duration: String?
    private var skipOffset: String?
    private var clickThrough: String?
    private var adTitle: String?
    private var tracking: [String: [URL]] = [:]

    private var currentElement: String?
    private var currentTrackingEvent: String?
    private var buffer = ""

    func parser(
        _ parser: XMLParser,
        didStartElement elementName: String,
        namespaceURI: String?,
        qualifiedName qName: String?,
        attributes attributeDict: [String: String] = [:]
    ) {
        currentElement = elementName
        buffer = ""

        switch elementName {
        case "AdBreak":
            placement = AdBreak.Placement(rawValue: attributeDict["breakId"] ?? "") ?? .preRoll
            timeOffsetRaw = attributeDict["timeOffset"]
            resetCreative()
        case "Linear":
            skipOffset = attributeDict["skipoffset"]
        case "Tracking":
            currentTrackingEvent = attributeDict["event"]
        default:
            break
        }
    }

    func parser(_ parser: XMLParser, foundCharacters string: String) {
        buffer += string
    }

    func parser(
        _ parser: XMLParser,
        didEndElement elementName: String,
        namespaceURI: String?,
        qualifiedName qName: String?
    ) {
        let value = buffer.trimmingCharacters(in: .whitespacesAndNewlines)
        buffer = ""

        switch elementName {
        case "MediaFile":
            // Keep the first entry; the server emits a single progressive file.
            if mediaURL == nil, !value.isEmpty { mediaURL = value }
        case "Duration":
            if duration == nil { duration = value }
        case "AdTitle":
            if adTitle == nil { adTitle = value }
        case "ClickThrough":
            if clickThrough == nil { clickThrough = value }
        case "Impression":
            append(event: "impression", url: value)
        case "ClickTracking":
            append(event: "click", url: value)
        case "Tracking":
            if let event = currentTrackingEvent { append(event: event, url: value) }
            currentTrackingEvent = nil
        case "AdBreak":
            finishBreak()
        default:
            break
        }

        currentElement = nil
    }

    private func append(event: String, url: String) {
        guard let parsed = URL(string: url), !url.isEmpty else { return }
        tracking[event, default: []].append(parsed)
    }

    private func resetCreative() {
        mediaURL = nil
        duration = nil
        skipOffset = nil
        clickThrough = nil
        adTitle = nil
        tracking = [:]
    }

    private func finishBreak() {
        defer {
            placement = nil
            timeOffsetRaw = nil
            resetCreative()
        }

        guard let placement,
              let mediaURL,
              let url = URL(string: mediaURL) else { return }

        let creative = AdCreative(
            mediaURL: url,
            durationSeconds: Self.seconds(from: duration) ?? 0,
            skipOffsetSeconds: Self.seconds(from: skipOffset),
            clickThroughURL: clickThrough.flatMap(URL.init(string:)),
            adTitle: adTitle,
            tracking: tracking
        )

        breaks.append(
            AdBreak(
                placement: placement,
                timeOffsetSeconds: Self.offset(from: timeOffsetRaw, placement: placement),
                creative: creative
            )
        )
    }

    /// Parses `HH:MM:SS(.mmm)`; also accepts a bare percentage for skipoffset.
    private static func seconds(from value: String?) -> Double? {
        guard let value, !value.isEmpty else { return nil }

        if value.hasSuffix("%"), let percent = Double(value.dropLast()) {
            return percent / 100
        }

        let parts = value.split(separator: ":").map(String.init)
        guard parts.count == 3,
              let hours = Double(parts[0]),
              let minutes = Double(parts[1]),
              let secs = Double(parts[2]) else { return nil }

        return hours * 3600 + minutes * 60 + secs
    }

    private static func offset(from raw: String?, placement: AdBreak.Placement) -> Double {
        switch placement {
        case .preRoll: return 0
        case .postRoll: return .infinity
        case .midRoll: return seconds(from: raw) ?? 0
        }
    }
}
