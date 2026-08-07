import Foundation

@MainActor
final class LiveCatalogRepository: CatalogRepositoryProtocol {
    private let api: APIClient
    init(api: APIClient) { self.api = api }

    func home(locale: LocaleCode) async throws -> HomeResponse { try await api.home(locale: locale) }
    func catalog(
        locale: LocaleCode,
        search: String = "",
        type: String? = nil,
        genre: String? = nil,
        year: String? = nil,
        country: String? = nil,
        access: String? = nil,
        minRating: Double? = nil,
        page: Int = 1
    ) async throws -> CatalogResponse {
        try await api.catalog(locale: locale, search: search, type: type, genre: genre, year: year, country: country, access: access, minRating: minRating, page: page)
    }
    func footerMenu(locale: LocaleCode) async throws -> PublicMenuResponse { try await api.footerMenu(locale: locale) }
    func content(slug: String, locale: LocaleCode) async throws -> Content { try await api.content(slug: slug, locale: locale) }
    func reviews(slug: String) async throws -> ReviewsResponse { try await api.reviews(slug: slug) }
    func submitReview(slug: String, rating: Int, comment: String) async throws { _ = try await api.submitReview(slug: slug, rating: rating, comment: comment) }
    func deleteReview(slug: String, reviewID: String) async throws { _ = try await api.deleteReview(slug: slug, reviewID: reviewID) }
}

@MainActor
final class LiveSessionRepository: SessionRepositoryProtocol {
    private let api: APIClient
    init(api: APIClient) { self.api = api }
    var hasStoredSession: Bool { KeychainStore.readToken() != nil }

    func login(email: String, password: String) async throws -> AuthResponse { try await api.login(email: email, password: password) }
    func register(name: String, email: String, password: String, locale: LocaleCode) async throws -> RegistrationResponse { try await api.register(name: name, email: email, password: password, locale: locale) }
    func verify(email: String, code: String) async throws -> AuthResponse { try await api.verify(email: email, code: code) }
    func resend(email: String) async throws { _ = try await api.resend(email: email) }
    func currentUser() async throws -> User { try await api.me().user }
    func account(locale: LocaleCode) async throws -> AccountResponse { try await api.account(locale: locale) }
    func updateAccount(name: String, email: String, locale: LocaleCode) async throws -> User { try await api.updateAccount(name: name, email: email, locale: locale) }
    func updatePassword(currentPassword: String, password: String) async throws { _ = try await api.updatePassword(currentPassword: currentPassword, password: password) }
    func topUp(amount: Double, currency: String, phone: String, billingAddress: BillingAddress, locale: LocaleCode) async throws -> WalletTopUp {
        try await api.topUp(amount: amount, currency: currency, phone: phone, billingAddress: billingAddress, locale: locale).topUp
    }
    func store(token: String) { KeychainStore.saveToken(token) }
    func favorite(profileID: String, slug: String, add: Bool) async throws { _ = try await api.favorite(profileID: profileID, slug: slug, add: add) }
    func createProfile(name: String, color: String, isKids: Bool) async throws -> ProfileMutationResponse { try await api.createProfile(name: name, color: color, isKids: isKids) }
    func updateProfile(id: String, name: String, color: String, isKids: Bool) async throws -> ProfileMutationResponse { try await api.updateProfile(id: id, name: name, color: color, isKids: isKids) }
    func deleteProfile(id: String) async throws -> ProfileMutationResponse { try await api.deleteProfile(id: id) }
    func logout() async { _ = try? await api.logout(); KeychainStore.deleteToken() }
}

@MainActor
final class LivePlaybackRepository: PlaybackRepositoryProtocol {
    private let api: APIClient
    init(api: APIClient) { self.api = api }

    func continueWatching(locale: LocaleCode, profileID: String?) async throws -> [ContinueItem] {
        try await api.continueWatching(locale: locale, profileID: profileID).items
    }
    func purchase(offerID: String, profileID: String?) async throws { _ = try await api.purchase(offerID: offerID, profileID: profileID) }

    func playback(slug: String, episodeID: String?, profileID: String?, locale: LocaleCode) async throws -> PlaybackContext {
        let response = try await api.playback(slug: slug, episodeID: episodeID, profileID: profileID, locale: locale)
        guard let url = URL(string: response.playback.url) else { throw APIError(status: 0, message: "Adresa video nu este validă.") }
        let embedURL = response.playback.embedURL.flatMap(URL.init(string:))
        var token = response.playback.sessionToken
        if token == nil { token = try await api.startPlaybackSession(slug: slug, contentFormatID: response.playback.contentFormatID, profileID: profileID).session.token }
        let tracking = token.map { PlayerTrackingContext(sessionToken: $0, contentID: response.content.id, contentFormatID: response.playback.contentFormatID, episodeID: episodeID) }
        return PlaybackContext(
            title: response.content.title,
            url: url,
            embedURL: embedURL,
            bunnyToken: response.playback.bunnyToken,
            bunnyExpires: response.playback.bunnyExpires,
            startPosition: response.continueWatching?.positionSeconds ?? 0,
            tracking: tracking
        )
    }

    func track(_ context: PlayerTrackingContext, position: Double, duration: Double, event: String) async {
        _ = try? await api.track(sessionToken: context.sessionToken, contentID: context.contentID, contentFormatID: context.contentFormatID, episodeID: context.episodeID, position: position, duration: duration, event: event)
    }
}

@MainActor
final class LiveDeviceRepository: DeviceRepositoryProtocol {
    private let api: APIClient
    init(api: APIClient) { self.api = api }
    func lookup(code: String) async throws -> DeviceLookup { try await api.lookupDevice(code: code) }
    func authorize(code: String, approve: Bool) async throws -> String { try await api.authorizeDevice(code: code, approve: approve).message }
}
