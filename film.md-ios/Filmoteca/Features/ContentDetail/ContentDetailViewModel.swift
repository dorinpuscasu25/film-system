import Foundation
import Observation

@MainActor @Observable
final class ContentDetailViewModel {
    private let catalog: any CatalogRepositoryProtocol
    private let playback: any PlaybackRepositoryProtocol
    private let session: any SessionRepositoryProtocol
    let seed: Content

    var state: LoadableState = .idle
    var content: Content?
    var reviews: ReviewsResponse?
    var playerRequest: PlayerRequest?
    var isPurchasePresented = false
    var isReviewPresented = false
    var selectedSeason = 0

    init(seed: Content, container: AppContainer) {
        self.seed = seed
        catalog = container.catalogRepository
        playback = container.playbackRepository
        session = container.sessionRepository
    }

    func load(app: FilmotecaModel) async {
        state = .loading
        do {
            let loadedContent = try await catalog.content(slug: seed.slug, locale: app.locale)
            guard app.allows(loadedContent) else {
                throw APIError(status: 403, message: "Acest titlu nu este disponibil pentru profilul KID.")
            }
            content = loadedContent
            if app.isAuthenticated { await app.refreshAccount() }
            await loadReviews()
            state = .loaded
        } catch { state = .failed(message: error.localizedDescription) }
    }

    func loadReviews() async { reviews = try? await catalog.reviews(slug: seed.slug) }

    func hasAccess(app: FilmotecaModel) -> Bool {
        guard let content else { return false }
        return content.isFree == true || app.account?.library.contains(where: { $0.contentSlug == content.slug && $0.isActive }) == true
    }

    func watch(app: FilmotecaModel, episodeID: String? = nil) async {
        guard content != nil else { return }
        guard app.isAuthenticated else { app.authPresented = true; return }
        if !hasAccess(app: app) { isPurchasePresented = true; return }
        await startPlayback(app: app, episodeID: episodeID)
    }

    func startPlayback(app: FilmotecaModel, episodeID: String? = nil) async {
        guard let content else { return }
        do {
            let context = try await playback.playback(slug: content.slug, episodeID: episodeID, profileID: app.activeProfile?.id, locale: app.locale)
            playerRequest = PlayerRequest(
                title: context.title,
                source: MediaSourceResolver.playback(
                    context.url,
                    explicitEmbedURL: context.embedURL,
                    bunnyToken: context.bunnyToken,
                    bunnyExpires: context.bunnyExpires
                ),
                startPosition: context.startPosition,
                contentSlug: content.slug,
                tracking: context.tracking
            )
        } catch { app.globalError = error.localizedDescription }
    }

    func playTrailer() {
        guard let content, let url = content.preferredTrailerURL else { return }
        playerRequest = PlayerRequest(
            title: "Trailer • \(content.title)",
            source: MediaSourceResolver.trailer(url),
            startPosition: 0,
            contentSlug: nil
        )
    }

    func purchase(offer: Offer, app: FilmotecaModel) async throws {
        guard app.isAuthenticated else {
            isPurchasePresented = false
            app.authPresented = true
            throw APIError(status: 401, message: "Autentifică-te pentru a continua.")
        }
        try await playback.purchase(offerID: offer.id, profileID: app.activeProfile?.id)
        await app.refreshAccount()
        isPurchasePresented = false
        await startPlayback(app: app)
    }

    func submitReview(rating: Int, comment: String) async throws {
        try await catalog.submitReview(slug: seed.slug, rating: rating, comment: comment.trimmingCharacters(in: .whitespacesAndNewlines))
        await loadReviews()
    }

    func deleteReview(_ review: Review) async throws {
        try await catalog.deleteReview(slug: seed.slug, reviewID: review.id)
        await loadReviews()
    }
}
