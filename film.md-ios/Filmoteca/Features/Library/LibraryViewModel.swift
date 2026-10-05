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
            // Requests run concurrently (the tasks suspend on the network), results keep order.
            let locale = app.locale
            let requests = app.favorites.sorted().map { slug in
                Task { try? await catalog.content(slug: slug, locale: locale) }
            }
            var resolved: [Content] = []
            for request in requests {
                if let content = await request.value, app.allows(content) { resolved.append(content) }
            }
            favoriteContent = resolved
        }
        state = .loaded
    }
}
