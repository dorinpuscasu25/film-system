import Foundation
import Observation

@MainActor @Observable
final class HomeViewModel {
    private let catalog: any CatalogRepositoryProtocol
    private let playback: any PlaybackRepositoryProtocol

    var state: LoadableState = .idle
    var response: HomeResponse?
    var continueItems: [ContinueItem] = []
    var heroIndex = 0

    init(container: AppContainer) {
        catalog = container.catalogRepository
        playback = container.playbackRepository
    }

    func load(locale: LocaleCode, authenticated: Bool, profileID: String?) async {
        state = .loading
        do {
            response = try await catalog.home(locale: locale)
            continueItems = authenticated ? (try? await playback.continueWatching(locale: locale, profileID: profileID)) ?? [] : []
            heroIndex = 0
            state = .loaded
        } catch {
            state = .failed(message: error.localizedDescription)
        }
    }
}
