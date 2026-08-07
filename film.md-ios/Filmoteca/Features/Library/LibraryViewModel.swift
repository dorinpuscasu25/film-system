import Foundation
import Observation

@MainActor @Observable
final class LibraryViewModel {
    private let catalog: any CatalogRepositoryProtocol
    private let playback: any PlaybackRepositoryProtocol
    var state: LoadableState = .idle
    var favoriteContent: [Content] = []
    var continueItems: [ContinueItem] = []

    init(container: AppContainer) {
        catalog = container.catalogRepository
        playback = container.playbackRepository
    }

    func load(app: FilmotecaModel) async {
        guard app.isAuthenticated else { state = .loaded; favoriteContent = []; continueItems = []; return }
        state = .loading
        await app.refreshAccount()
        continueItems = (try? await playback.continueWatching(locale: app.locale, profileID: app.activeProfile?.id)) ?? []
        if app.favorites.isEmpty {
            favoriteContent = []
        } else {
            var resolved: [Content] = []
            for slug in app.favorites.sorted() {
                if let content = try? await catalog.content(slug: slug, locale: app.locale), app.allows(content) {
                    resolved.append(content)
                }
            }
            favoriteContent = resolved
        }
        state = .loaded
    }
}
