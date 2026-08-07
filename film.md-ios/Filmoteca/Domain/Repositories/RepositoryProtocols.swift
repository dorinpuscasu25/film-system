import Foundation

@MainActor
protocol CatalogRepositoryProtocol {
    func home(locale: LocaleCode) async throws -> HomeResponse
    func catalog(
        locale: LocaleCode,
        search: String,
        type: String?,
        genre: String?,
        year: String?,
        country: String?,
        access: String?,
        minRating: Double?,
        page: Int
    ) async throws -> CatalogResponse
    func footerMenu(locale: LocaleCode) async throws -> PublicMenuResponse
    func content(slug: String, locale: LocaleCode) async throws -> Content
    func reviews(slug: String) async throws -> ReviewsResponse
    func submitReview(slug: String, rating: Int, comment: String) async throws
    func deleteReview(slug: String, reviewID: String) async throws
}

@MainActor
protocol SessionRepositoryProtocol {
    var hasStoredSession: Bool { get }
    func login(email: String, password: String) async throws -> AuthResponse
    func register(name: String, email: String, password: String, locale: LocaleCode) async throws -> RegistrationResponse
    func verify(email: String, code: String) async throws -> AuthResponse
    func resend(email: String) async throws
    func currentUser() async throws -> User
    func account(locale: LocaleCode) async throws -> AccountResponse
    func updateAccount(name: String, email: String, locale: LocaleCode) async throws -> User
    func updatePassword(currentPassword: String, password: String) async throws
    func topUp(amount: Double, currency: String, phone: String, billingAddress: BillingAddress, locale: LocaleCode) async throws -> WalletTopUp
    func store(token: String)
    func favorite(profileID: String, slug: String, add: Bool) async throws
    func createProfile(name: String, color: String, isKids: Bool) async throws -> ProfileMutationResponse
    func updateProfile(id: String, name: String, color: String, isKids: Bool) async throws -> ProfileMutationResponse
    func deleteProfile(id: String) async throws -> ProfileMutationResponse
    func logout() async
}

@MainActor
protocol PlaybackRepositoryProtocol {
    func continueWatching(locale: LocaleCode, profileID: String?) async throws -> [ContinueItem]
    func purchase(offerID: String, profileID: String?) async throws
    func playback(slug: String, episodeID: String?, profileID: String?, locale: LocaleCode) async throws -> PlaybackContext
    func track(_ context: PlayerTrackingContext, position: Double, duration: Double, event: String) async
}

@MainActor
protocol DeviceRepositoryProtocol {
    func lookup(code: String) async throws -> DeviceLookup
    func authorize(code: String, approve: Bool) async throws -> String
}

struct PlaybackContext {
    let title: String
    let url: URL
    let embedURL: URL?
    let bunnyToken: String?
    let bunnyExpires: Int64?
    let startPosition: Double
    let tracking: PlayerTrackingContext?
}

struct PlayerTrackingContext: Sendable {
    let sessionToken: String
    let contentID: String
    let contentFormatID: Int?
    let episodeID: String?
}
