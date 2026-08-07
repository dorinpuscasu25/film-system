import Foundation

struct APIError: LocalizedError {
    let status: Int
    let message: String
    var errorDescription: String? { message }
}

@MainActor
final class APIClient {
    static let shared = APIClient(baseURL: AppConfiguration.production.apiBaseURL)
    private let baseURL: URL
    private let decoder: JSONDecoder = { let value = JSONDecoder(); return value }()
    private let encoder: JSONEncoder = { let value = JSONEncoder(); return value }()

    init(baseURL: URL) {
        self.baseURL = baseURL
    }

    private func request<T: Decodable>(
        _ path: String,
        method: String = "GET",
        query: [URLQueryItem] = [],
        body: (any Encodable)? = nil,
        authenticated: Bool = false
    ) async throws -> T {
        var components = URLComponents(url: baseURL.appending(path: path), resolvingAgainstBaseURL: false)!
        components.queryItems = query.isEmpty ? nil : query
        var request = URLRequest(url: components.url!)
        request.httpMethod = method
        request.timeoutInterval = 30
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("Filmoteca-iOS/1.0", forHTTPHeaderField: "User-Agent")
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try encoder.encode(AnyEncodable(body))
        }
        if authenticated {
            guard let token = KeychainStore.readToken() else { throw APIError(status: 401, message: "Autentifică-te pentru a continua.") }
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw APIError(status: 0, message: "Răspuns invalid de la server.") }
        guard (200..<300).contains(http.statusCode) else {
            let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
            let errors = payload?["errors"] as? [String: [String]]
            let message = errors?.values.first?.first ?? (payload?["message"] as? String) ?? "Cererea nu a putut fi finalizată."
            throw APIError(status: http.statusCode, message: message)
        }
        return try decoder.decode(T.self, from: data)
    }

    func home(locale: LocaleCode) async throws -> HomeResponse {
        try await request("public/home", query: [.init(name: "locale", value: locale.rawValue)])
    }

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
        var query = [URLQueryItem(name: "locale", value: locale.rawValue), .init(name: "page", value: String(page)), .init(name: "page_size", value: "30")]
        if !search.isEmpty { query.append(.init(name: "query", value: search)) }
        if let type { query.append(.init(name: "type", value: type)) }
        if let genre { query.append(.init(name: "genre", value: genre)) }
        if let year { query.append(.init(name: "year", value: year)) }
        if let country { query.append(.init(name: "country", value: country)) }
        if let access { query.append(.init(name: "access", value: access)) }
        if let minRating, minRating > 0 { query.append(.init(name: "min_rating", value: String(minRating))) }
        return try await request("public/catalog", query: query)
    }

    func footerMenu(locale: LocaleCode) async throws -> PublicMenuResponse {
        try await request("public/menus/footer", query: [.init(name: "locale", value: locale.rawValue)])
    }

    func content(slug: String, locale: LocaleCode) async throws -> Content {
        try await request("public/content/\(slug)", query: [.init(name: "locale", value: locale.rawValue)])
    }

    func reviews(slug: String) async throws -> ReviewsResponse { try await request("public/content/\(slug)/reviews") }

    func login(email: String, password: String) async throws -> AuthResponse {
        struct Body: Encodable { let email: String; let password: String; let app = "client" }
        return try await request("auth/login", method: "POST", body: Body(email: email, password: password))
    }

    func register(name: String, email: String, password: String, locale: LocaleCode) async throws -> RegistrationResponse {
        struct Body: Encodable { let name, email, password, password_confirmation, preferred_locale: String }
        return try await request("auth/register", method: "POST", body: Body(name: name, email: email, password: password, password_confirmation: password, preferred_locale: locale.rawValue))
    }

    func verify(email: String, code: String) async throws -> AuthResponse {
        struct Body: Encodable { let email, code: String }
        return try await request("auth/register/verify", method: "POST", body: Body(email: email, code: code))
    }

    func resend(email: String) async throws -> RegistrationResponse {
        struct Body: Encodable { let email: String }
        return try await request("auth/register/resend", method: "POST", body: Body(email: email))
    }

    func me() async throws -> UserResponse { try await request("auth/me", authenticated: true) }
    func account(locale: LocaleCode) async throws -> AccountResponse { try await request("storefront/account", query: [.init(name: "locale", value: locale.rawValue)], authenticated: true) }
    func updateAccount(name: String, email: String, locale: LocaleCode) async throws -> User {
        struct Body: Encodable { let name, email, preferred_locale: String }
        let response: AccountSettingsResponse = try await request(
            "settings/profile",
            method: "PUT",
            body: Body(name: name, email: email, preferred_locale: locale.rawValue),
            authenticated: true
        )
        return response.user
    }
    func updatePassword(currentPassword: String, password: String) async throws -> String {
        struct Body: Encodable { let current_password, password, password_confirmation: String }
        let response: MessageResponse = try await request(
            "settings/password",
            method: "PUT",
            body: Body(current_password: currentPassword, password: password, password_confirmation: password),
            authenticated: true
        )
        return response.message
    }
    func continueWatching(locale: LocaleCode, profileID: String?) async throws -> ContinueResponse {
        var query = [URLQueryItem(name: "locale", value: locale.rawValue)]
        if let profileID { query.append(.init(name: "account_profile_id", value: profileID)) }
        return try await request("storefront/continue-watching", query: query, authenticated: true)
    }

    func topUp(amount: Double, currency: String, phone: String, billingAddress: BillingAddress, locale: LocaleCode) async throws -> WalletTopUpResponse {
        struct Body: Encodable {
            let amount: Double
            let currency: String
            let phone: String
            let billing_address: BillingAddress
            let locale: String
        }
        return try await request(
            "storefront/wallet/top-ups",
            method: "POST",
            body: Body(amount: amount, currency: currency, phone: phone, billing_address: billingAddress, locale: locale.rawValue),
            authenticated: true
        )
    }

    func favorite(profileID: String, slug: String, add: Bool) async throws -> FavoriteResponse {
        try await request("storefront/profiles/\(profileID)/favorites/\(slug)", method: add ? "PUT" : "DELETE", authenticated: true)
    }

    func createProfile(name: String, color: String, isKids: Bool) async throws -> ProfileMutationResponse {
        struct Body: Encodable { let name: String; let avatar_color: String; let avatar_label: String; let is_kids: Bool }
        return try await request("storefront/profiles", method: "POST", body: Body(name: name, avatar_color: color, avatar_label: String(name.prefix(1)).uppercased(), is_kids: isKids), authenticated: true)
    }

    func updateProfile(id: String, name: String, color: String, isKids: Bool) async throws -> ProfileMutationResponse {
        struct Body: Encodable { let name: String; let avatar_color: String; let avatar_label: String; let is_kids: Bool }
        return try await request("storefront/profiles/\(id)", method: "PATCH", body: Body(name: name, avatar_color: color, avatar_label: String(name.prefix(1)).uppercased(), is_kids: isKids), authenticated: true)
    }

    func deleteProfile(id: String) async throws -> ProfileMutationResponse { try await request("storefront/profiles/\(id)", method: "DELETE", authenticated: true) }

    func purchase(offerID: String, profileID: String?) async throws -> PurchaseResponse {
        struct Body: Encodable { let account_profile_id: String? }
        return try await request("storefront/offers/\(offerID)/purchase", method: "POST", body: Body(account_profile_id: profileID), authenticated: true)
    }

    func playback(slug: String, episodeID: String? = nil, profileID: String? = nil, locale: LocaleCode) async throws -> PlaybackResponse {
        var query = [URLQueryItem(name: "locale", value: locale.rawValue)]
        if let episodeID { query.append(.init(name: "episode_id", value: episodeID)) }
        if let profileID { query.append(.init(name: "account_profile_id", value: profileID)) }
        return try await request("storefront/content/\(slug)/playback", query: query, authenticated: true)
    }

    func startPlaybackSession(slug: String, contentFormatID: Int?, profileID: String?) async throws -> PlaybackSessionResponse {
        struct Body: Encodable { let content_format_id: Int?; let account_profile_id: String?; let device_type = "ios" }
        return try await request("storefront/content/\(slug)/playback/session", method: "POST", body: Body(content_format_id: contentFormatID, account_profile_id: profileID), authenticated: true)
    }

    func track(sessionToken: String, contentID: String, contentFormatID: Int?, episodeID: String?, position: Double, duration: Double, event: String) async throws -> TrackingResponse {
        struct Body: Encodable { let session_token, content_id: String; let content_format_id: Int?; let episode_id: String?; let position_seconds, duration_seconds, watch_time_seconds: Double; let event_type: String }
        let body = Body(session_token: sessionToken, content_id: contentID, content_format_id: contentFormatID, episode_id: episodeID, position_seconds: position, duration_seconds: duration, watch_time_seconds: event == "progress" ? 10 : 0, event_type: event)
        return try await request("storefront/tracking/watch-progress", method: "POST", body: body, authenticated: true)
    }

    func submitReview(slug: String, rating: Int, comment: String) async throws -> ReviewSubmissionResponse {
        struct Body: Encodable { let rating: Int; let comment: String }
        return try await request("storefront/content/\(slug)/reviews", method: "POST", body: Body(rating: rating, comment: comment), authenticated: true)
    }
    func deleteReview(slug: String, reviewID: String) async throws -> ReviewDeletionResponse {
        try await request("storefront/content/\(slug)/reviews/\(reviewID)", method: "DELETE", authenticated: true)
    }

    func lookupDevice(code: String) async throws -> DeviceLookup { try await request("device/\(code)", authenticated: true) }
    func authorizeDevice(code: String, approve: Bool) async throws -> MessageResponse {
        struct Body: Encodable { let user_code, action: String }
        return try await request("device/authorize", method: "POST", body: Body(user_code: code, action: approve ? "approve" : "deny"), authenticated: true)
    }

    func logout() async throws -> EmptyResponse { try await request("auth/logout", method: "POST", authenticated: true) }
}

private struct AnyEncodable: Encodable {
    let value: any Encodable
    init(_ value: any Encodable) { self.value = value }
    func encode(to encoder: Encoder) throws { try value.encode(to: encoder) }
}

struct EmptyResponse: Decodable {}
