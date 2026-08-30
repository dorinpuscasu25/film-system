import Foundation

@propertyWrapper
struct FlexibleArray<Value: Codable & Hashable>: Codable, Hashable {
    var wrappedValue: [Value]

    init(wrappedValue: [Value]) { self.wrappedValue = wrappedValue }

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let array = try? container.decode([Value].self) {
            wrappedValue = array
            return
        }
        if let dictionary = try? container.decode([String: Value].self) {
            wrappedValue = dictionary.sorted { left, right in
                if let lhs = Int(left.key), let rhs = Int(right.key) { return lhs < rhs }
                return left.key.localizedStandardCompare(right.key) == .orderedAscending
            }.map(\.value)
            return
        }
        wrappedValue = []
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(wrappedValue)
    }
}

@propertyWrapper
struct FlexibleOptionalArray<Value: Codable & Hashable>: Codable, Hashable {
    var wrappedValue: [Value]?
    init(wrappedValue: [Value]?) { self.wrappedValue = wrappedValue }

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() { wrappedValue = nil }
        else if let array = try? container.decode([Value].self) { wrappedValue = array }
        else if let dictionary = try? container.decode([String: Value].self) {
            wrappedValue = dictionary.sorted { $0.key.localizedStandardCompare($1.key) == .orderedAscending }.map(\.value)
        } else { wrappedValue = [] }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(wrappedValue)
    }
}

extension KeyedDecodingContainer {
    func decode<Value>(_ type: FlexibleOptionalArray<Value>.Type, forKey key: Key) throws -> FlexibleOptionalArray<Value> where Value: Codable & Hashable {
        try decodeIfPresent(type, forKey: key) ?? FlexibleOptionalArray(wrappedValue: nil)
    }
}

enum LocaleCode: String, Codable, CaseIterable, Identifiable {
    case ro, ru, en
    var id: String { rawValue }
    var title: String { switch self { case .ro: "Română"; case .ru: "Русский"; case .en: "English" } }
}

struct BadgeDTO: Codable, Hashable { let id: String?; let slug: String?; let label: String?; let color: String? }

struct Offer: Codable, Identifiable, Hashable {
    let idValue: StringOrInt
    let name: String
    let offerType: String
    let quality: String?
    let currency: String
    let priceAmount: Double
    let rentalDays: Int?
    var id: String { idValue.stringValue }
    var durationLabel: String {
        switch offerType.lowercased() {
        case "rental", "rent":
            return rentalDays.map { "Pentru \($0) zile" } ?? "Acces temporar"
        case "lifetime", "purchase", "buy":
            return "Acces permanent"
        case "free":
            return "Gratuit"
        default:
            let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
            return trimmed.isEmpty ? "Acces" : trimmed
        }
    }
    var qualityLabel: String {
        let trimmed = (quality ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.isEmpty { return trimmed }
        let trimmedName = name.trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmedName.isEmpty ? "Acces" : trimmedName
    }
    enum CodingKeys: String, CodingKey { case idValue = "id", name, quality, currency; case offerType = "offer_type"; case priceAmount = "price_amount"; case rentalDays = "rental_days" }
}

struct Person: Codable, Identifiable, Hashable {
    let idValue: StringOrInt
    let name: String
    let role: String?
    let job: String?
    let avatarURL: String?
    var id: String { idValue.stringValue }
    enum CodingKeys: String, CodingKey { case idValue = "id", name, role, job; case avatarURL = "avatar_url" }
}

struct VideoAsset: Codable, Identifiable, Hashable {
    let idValue: StringOrInt
    let type: String
    let title: String
    let description: String?
    let videoURL: String
    let thumbnailURL: String?
    let durationSeconds: Int?
    let isPrimary: Bool?
    var id: String { idValue.stringValue }
    enum CodingKeys: String, CodingKey { case idValue = "id", type, title, description; case videoURL = "video_url"; case thumbnailURL = "thumbnail_url"; case durationSeconds = "duration_seconds"; case isPrimary = "is_primary" }
}

struct Episode: Codable, Identifiable, Hashable {
    let idValue: StringOrInt
    let episodeNumber: Int
    let title: String
    let description: String?
    let runtimeMinutes: Int?
    let thumbnailURL: String?
    let backdropURL: String?
    let videoURL: String?
    var id: String { idValue.stringValue }
    enum CodingKeys: String, CodingKey { case idValue = "id", title, description; case episodeNumber = "episode_number"; case runtimeMinutes = "runtime_minutes"; case thumbnailURL = "thumbnail_url"; case backdropURL = "backdrop_url"; case videoURL = "video_url" }
}

struct Season: Codable, Identifiable, Hashable {
    let idValue: StringOrInt
    let seasonNumber: Int
    let title: String?
    let description: String?
    let posterURL: String?
    @FlexibleArray var episodes: [Episode]
    var id: String { idValue.stringValue }
    enum CodingKeys: String, CodingKey { case idValue = "id", title, description, episodes; case seasonNumber = "season_number"; case posterURL = "poster_url" }
}

struct Premiere: Codable, Hashable {
    let id: StringOrInt
    let title: String
    let startsAt: String
    let endsAt: String?
    enum CodingKeys: String, CodingKey { case id, title; case startsAt = "starts_at"; case endsAt = "ends_at" }
}

enum StringOrInt: Codable, Hashable {
    case string(String), int(Int)
    init(from decoder: Decoder) throws { let box = try decoder.singleValueContainer(); if let value = try? box.decode(Int.self) { self = .int(value) } else { self = .string(try box.decode(String.self)) } }
    func encode(to encoder: Encoder) throws { var box = encoder.singleValueContainer(); switch self { case .string(let value): try box.encode(value); case .int(let value): try box.encode(value) } }
    var stringValue: String { switch self { case .string(let value): value; case .int(let value): String(value) } }
}

struct Content: Codable, Identifiable, Hashable {
    let numericID: StringOrInt?
    let slug: String
    let type: String
    let typeLabel: String?
    let title: String
    let originalTitle: String?
    let shortDescription: String?
    let tagline: String?
    let description: String?
    let releaseYear: Int?
    let countryName: String?
    @FlexibleOptionalArray var countryNames: [String]?
    let imdbRating: Double?
    let platformRating: Double?
    let runtimeMinutes: Int?
    let ageRating: String?
    @FlexibleOptionalArray var audioLocales: [String]?
    @FlexibleOptionalArray var subtitleLocales: [String]?
    @FlexibleArray var genres: [String]
    @FlexibleOptionalArray var badges: [BadgeDTO]?
    let isFeatured: Bool?
    let isTrending: Bool?
    let isFree: Bool?
    let posterURL: String
    let backdropURL: String
    let heroDesktopURL: String?
    let heroMobileURL: String?
    let trailerURL: String?
    @FlexibleOptionalArray var previewImages: [String]?
    let premiereEvent: Premiere?
    let lowestPrice: Double?
    let currency: String?
    @FlexibleOptionalArray var cast: [Person]?
    @FlexibleOptionalArray var crew: [Person]?
    @FlexibleOptionalArray var videos: [VideoAsset]?
    @FlexibleOptionalArray var seasons: [Season]?
    @FlexibleOptionalArray var offers: [Offer]?

    var id: String { slug }
    var heroURL: URL? { URL(string: heroMobileURL ?? heroDesktopURL ?? backdropURL) }
    var poster: URL? { URL(string: posterURL) }
    var price: Double { offers?.map(\.priceAmount).min() ?? lowestPrice ?? 0 }
    var purchaseOffers: [Offer] {
        var seen = Set<String>()
        return (offers ?? []).filter { offer in
            let key = [
                offer.offerType.lowercased(),
                offer.quality?.lowercased() ?? "",
                String(offer.priceAmount),
                offer.currency.uppercased(),
                offer.rentalDays.map(String.init) ?? "",
            ].joined(separator: "|")
            return seen.insert(key).inserted
        }
    }
    var isNew: Bool { badges?.contains(where: { $0.slug == "new" }) == true }
    var preferredTrailerURL: URL? {
        let primaryVideo = videos?.first(where: { $0.isPrimary == true }) ?? videos?.first
        return [trailerURL, primaryVideo?.videoURL]
            .compactMap { $0 }
            .first(where: { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty })
            .flatMap(URL.init(string:))
    }

    enum CodingKeys: String, CodingKey {
        case slug, type, title, description, genres, badges, cast, crew, videos, seasons, offers, currency
        case numericID = "id"; case typeLabel = "type_label"; case originalTitle = "original_title"; case shortDescription = "short_description"; case tagline
        case releaseYear = "release_year"; case countryName = "country_name"; case countryNames = "country_names"; case imdbRating = "imdb_rating"; case platformRating = "platform_rating"
        case runtimeMinutes = "runtime_minutes"; case ageRating = "age_rating"; case audioLocales = "audio_locales"; case subtitleLocales = "subtitle_locales"
        case isFeatured = "is_featured"; case isTrending = "is_trending"; case isFree = "is_free"; case posterURL = "poster_url"; case backdropURL = "backdrop_url"
        case heroDesktopURL = "hero_desktop_url"; case heroMobileURL = "hero_mobile_url"; case trailerURL = "trailer_url"; case previewImages = "preview_images"; case premiereEvent = "premiere_event"; case lowestPrice = "lowest_price"
    }
}

struct HeroSlide: Codable, Identifiable, Hashable {
    let id: String
    let desktopImageURL: String
    let mobileImageURL: String?
    let eyebrow: String?
    let title: String
    let description: String?
    let primaryCTALabel: String?
    let secondaryCTALabel: String?
    let content: Content
    enum CodingKeys: String, CodingKey { case id, eyebrow, title, description, content; case desktopImageURL = "desktop_image_url"; case mobileImageURL = "mobile_image_url"; case primaryCTALabel = "primary_cta_label"; case secondaryCTALabel = "secondary_cta_label" }
}

struct HomeSection: Codable, Identifiable, Hashable { let id: String; let name: String; let title: String; let subtitle: String?; @FlexibleArray var items: [Content] }
struct HomeResponse: Codable {
    let hero: Content?
    @FlexibleOptionalArray var heroSlides: [HeroSlide]?
    @FlexibleOptionalArray var sections: [HomeSection]?
    @FlexibleArray var featured: [Content]
    @FlexibleArray var freeToWatch: [Content]
    @FlexibleArray var latest: [Content]
    @FlexibleArray var movies: [Content]
    @FlexibleArray var series: [Content]
    enum CodingKeys: String, CodingKey { case hero, sections, featured, latest, movies, series; case heroSlides = "hero_slides"; case freeToWatch = "free_to_watch" }
}

struct FilterOption: Codable, Identifiable, Hashable { let value: String; let label: String; let count: Int; var id: String { value } }
struct CatalogFilters: Codable { let genres: [FilterOption]?; let years: [FilterOption]?; let countries: [FilterOption]?; let types: [FilterOption]?; let access: [FilterOption]? }
struct CatalogResponse: Codable { @FlexibleArray var items: [Content]; let page: Int; let pageSize: Int; let total: Int; let filters: CatalogFilters?; enum CodingKeys: String, CodingKey { case items, page, total, filters; case pageSize = "page_size" } }

struct PublicMenu: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let slug: String
    let location: String
}

struct PublicMenuItem: Codable, Identifiable, Hashable {
    let id: Int
    let menuID: Int
    let parentID: Int?
    let label: String
    let resolvedURL: String
    let target: String
    let sortOrder: Int

    enum CodingKeys: String, CodingKey {
        case id, label, target
        case menuID = "menu_id"
        case parentID = "parent_id"
        case resolvedURL = "resolved_url"
        case sortOrder = "sort_order"
    }
}

struct PublicMenuResponse: Codable {
    let menu: PublicMenu?
    let menus: [PublicMenu]?
    @FlexibleArray var items: [PublicMenuItem]
}

/// Legal and informational pages served by the CMS. Rendered natively so the
/// privacy policy and terms stay reachable in-app (App Store requirement)
/// without depending on the website being up.
struct CmsPage: Codable, Identifiable, Hashable {
    let id: Int
    let title: String
    let slug: String
    let excerpt: String?
    let content: String
    let updatedAt: String?

    enum CodingKeys: String, CodingKey {
        case id, title, slug, excerpt, content
        case updatedAt = "updated_at"
    }
}

struct Profile: Codable, Identifiable, Hashable {
    let idValue: StringOrInt
    var name: String
    var avatarLabel: String?
    var avatarColor: String?
    var isKids: Bool?
    var isDefault: Bool?
    var maxAgeRating: String?
    var favoriteSlugs: [String]?
    var id: String { switch idValue { case .string(let value): value; case .int(let value): String(value) } }
    enum CodingKeys: String, CodingKey { case idValue = "id", name; case avatarLabel = "avatar_label"; case avatarColor = "avatar_color"; case isKids = "is_kids"; case isDefault = "is_default"; case maxAgeRating = "max_age_rating"; case favoriteSlugs = "favorite_slugs" }
}

extension Profile {
    func allows(ageRating: String?) -> Bool {
        guard isKids == true || maxAgeRating != nil else { return true }
        let ceiling = isKids == true ? "A.P.-12" : (maxAgeRating ?? "A.P.-12")
        return AgeRating.index(ageRating) <= AgeRating.index(ceiling)
    }
}

private enum AgeRating {
    static let ordered = ["AG", "A.P.-12", "N-15", "I.M.-18", "I.M.-18-XXX", "I.C."]
    static let aliases = [
        "0+": "AG", "ALL": "AG", "6+": "AG",
        "12": "A.P.-12", "12+": "A.P.-12",
        "15": "N-15", "15+": "N-15", "16": "N-15", "16+": "N-15",
        "18": "I.M.-18", "18+": "I.M.-18",
    ]

    static func index(_ value: String?) -> Int {
        let normalized = (value ?? "AG").trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let canonical = aliases[normalized] ?? normalized
        return ordered.firstIndex(of: canonical) ?? 0
    }
}

struct Wallet: Codable, Hashable { let id: StringOrInt; let currency: String; let balanceAmount: Double; enum CodingKeys: String, CodingKey { case id, currency; case balanceAmount = "balance_amount" } }
struct User: Codable, Identifiable { let idValue: StringOrInt; let name: String; let email: String; let preferredLocale: LocaleCode?; let wallet: Wallet?; let profiles: [Profile]?; var id: String { switch idValue { case .string(let value): value; case .int(let value): String(value) } }; enum CodingKeys: String, CodingKey { case idValue = "id", name, email, wallet, profiles; case preferredLocale = "preferred_locale" } }
struct AuthResponse: Codable { let token: String; let user: User }
struct UserResponse: Codable { let user: User }
struct RegistrationResponse: Codable { let message: String; let email: String; let expiresAt: String?; enum CodingKeys: String, CodingKey { case message, email; case expiresAt = "expires_at" } }

struct BillingAddress: Codable, Hashable {
    let id: StringOrInt?
    var fullName: String
    var countryCode: String
    var administrativeArea: String?
    var city: String
    var postalCode: String
    var addressLine1: String
    var addressLine2: String?

    enum CodingKeys: String, CodingKey {
        case id, city
        case fullName = "full_name"
        case countryCode = "country_code"
        case administrativeArea = "administrative_area"
        case postalCode = "postal_code"
        case addressLine1 = "address_line1"
        case addressLine2 = "address_line2"
    }
}

struct LibraryItem: Codable, Identifiable {
    let idValue: StringOrInt; let contentSlug: String; let contentTitle: String; let contentType: String; let posterURL: String?; let backdropURL: String?; let ageRating: String?; let accessType: String; let quality: String?; let isActive: Bool; let currency: String; let priceAmount: Double; let grantedAt: String?; let expiresAt: String?
    var id: String { contentSlug }
    enum CodingKeys: String, CodingKey { case idValue = "id"; case contentSlug = "content_slug"; case contentTitle = "content_title"; case contentType = "content_type"; case posterURL = "poster_url"; case backdropURL = "backdrop_url"; case ageRating = "age_rating"; case accessType = "access_type"; case quality; case isActive = "is_active"; case currency; case priceAmount = "price_amount"; case grantedAt = "granted_at"; case expiresAt = "expires_at" }
}

struct Transaction: Codable, Identifiable { let idValue: StringOrInt; let type: String; let amount: Double; let balanceAfter: Double?; let currency: String; let description: String?; let status: String?; let createdAt: String?; var id: String { String(describing: idValue) }; enum CodingKeys: String, CodingKey { case idValue = "id", type, amount, currency, description, status; case balanceAfter = "balance_after"; case createdAt = "created_at" } }
struct AccountResponse: Codable {
    let user: User
    let wallet: Wallet
    let paymentPhone: String?
    let billingAddress: BillingAddress?
    let transactions: [Transaction]
    let library: [LibraryItem]
    let favoritesByProfile: [String: [String]]
    enum CodingKeys: String, CodingKey {
        case user, wallet, transactions, library
        case paymentPhone = "payment_phone"
        case billingAddress = "billing_address"
        case favoritesByProfile = "favorites_by_profile"
    }
}

struct ContinueItem: Codable, Identifiable { let contentSlug: String; let title: String?; let posterURL: String?; let positionSeconds: Double; let durationSeconds: Double; let progressPercent: Double; var id: String { contentSlug }; enum CodingKeys: String, CodingKey { case contentSlug = "content_slug", title; case posterURL = "poster_url"; case positionSeconds = "position_seconds"; case durationSeconds = "duration_seconds"; case progressPercent = "progress_percent" } }
struct ContinueResponse: Codable { let items: [ContinueItem] }

struct PlaybackResponse: Codable {
    struct Playback: Codable { let url: String; let embedURL: String?; let quality: String?; let expiresAt: String?; let sessionToken: String?; let contentFormatID: Int?; let bunnyToken: String?; let bunnyExpires: Int64?; enum CodingKeys: String, CodingKey { case url, quality; case embedURL = "embed_url"; case expiresAt = "expires_at"; case sessionToken = "session_token"; case contentFormatID = "content_format_id"; case bunnyToken = "bunny_token"; case bunnyExpires = "bunny_expires" } }
    let content: ContentReference
    let playback: Playback
    let continueWatching: ContinuePosition?
    enum CodingKeys: String, CodingKey { case content, playback; case continueWatching = "continue_watching" }
}
struct ContentReference: Codable { let idValue: StringOrInt; let slug: String; let title: String; let type: String; let posterURL: String?; let backdropURL: String?; var id: String { idValue.stringValue }; enum CodingKeys: String, CodingKey { case idValue = "id", slug, title, type; case posterURL = "poster_url"; case backdropURL = "backdrop_url" } }
struct ContinuePosition: Codable { let positionSeconds: Double; let durationSeconds: Double; enum CodingKeys: String, CodingKey { case positionSeconds = "position_seconds"; case durationSeconds = "duration_seconds" } }
struct PlaybackSessionResponse: Codable { struct Session: Codable { let id: Int; let token: String; let status: String }; let session: Session }
struct TrackingResponse: Codable { struct Session: Codable { let id: Int; let status: String }; let session: Session }

struct Review: Codable, Identifiable {
    let idValue: StringOrInt
    let userID: StringOrInt?
    let userName: String
    let userAvatar: String
    let rating: Int
    let comment: String
    let createdAt: String
    var id: String { idValue.stringValue }
    enum CodingKeys: String, CodingKey {
        case idValue = "id"
        case userID = "user_id"
        case userName = "user_name"
        case userAvatar = "user_avatar"
        case rating, comment
        case createdAt = "created_at"
    }
}
struct ReviewsResponse: Codable { struct Summary: Codable { let count: Int; let averageRating: Double; enum CodingKeys: String, CodingKey { case count; case averageRating = "average_rating" } }; let items: [Review]; let summary: Summary }
struct ReviewSubmissionResponse: Codable { let review: Review; let summary: ReviewsResponse.Summary }
struct ReviewDeletionResponse: Codable { let message: String; let summary: ReviewsResponse.Summary }
struct ProfileMutationResponse: Codable { let profile: Profile?; let profiles: [Profile] }
struct AccountSettingsResponse: Codable { let user: User }
struct FavoriteResponse: Codable { let favoritesByProfile: [String: [String]]; enum CodingKeys: String, CodingKey { case favoritesByProfile = "favorites_by_profile" } }
struct PurchaseResponse: Codable { let message: String; let alreadyOwned: Bool; let wallet: Wallet; let libraryItem: LibraryItem; enum CodingKeys: String, CodingKey { case message, wallet; case alreadyOwned = "already_owned"; case libraryItem = "library_item" } }
struct DeviceLookup: Codable { let userCode: String; let deviceName: String?; let expiresAt: String?; enum CodingKeys: String, CodingKey { case userCode = "user_code"; case deviceName = "device_name"; case expiresAt = "expires_at" } }
struct MessageResponse: Codable { let message: String }
struct AccountDeletionResponse: Codable { let message: String; let deletedAt: String?; let forfeitedBalance: Double?; let currency: String?; enum CodingKeys: String, CodingKey { case message, currency; case deletedAt = "deleted_at"; case forfeitedBalance = "forfeited_balance" } }
struct WalletTopUp: Codable, Identifiable {
    let id: String
    let amount: Double
    let currency: String
    let status: String
    let paymentURL: String?
    enum CodingKeys: String, CodingKey { case id, amount, currency, status; case paymentURL = "payment_url" }
}
struct WalletTopUpResponse: Codable { let topUp: WalletTopUp; enum CodingKeys: String, CodingKey { case topUp = "top_up" } }
