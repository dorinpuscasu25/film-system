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
    /// Genres that actually have titles, biggest first — feeds the genre chips.
    var genres: [FilterOption] = []

    init(container: AppContainer) {
        catalog = container.catalogRepository
        playback = container.playbackRepository
    }

    func load(locale: LocaleCode, authenticated: Bool, profileID: String?, prefetched: HomeResponse? = nil) async {
        state = .loading
        do {
            if let prefetched { response = prefetched } else { response = try await catalog.home(locale: locale) }
            let genreTask = Task { [catalog] in try? await catalog.catalog(locale: locale, search: "", type: nil, genre: nil, year: nil, country: nil, access: nil, minRating: nil, page: 1).filters?.genres }
            continueItems = authenticated ? (try? await playback.continueWatching(locale: locale, profileID: profileID)) ?? [] : []
            genres = ((await genreTask.value) ?? []).filter { $0.count > 0 }.sorted { $0.count > $1.count }
            heroIndex = 0
            state = .loaded
        } catch {
            state = .failed(message: error.localizedDescription)
        }
    }

    /// Trending titles first, topped up from the featured shelf, ten at most and no repeats.
    func topTen(allowing allows: (Content) -> Bool) -> [Content] {
        guard let response else { return [] }
        let pool = (response.sections ?? []).flatMap(\.items) + response.featured + response.latest
        var seen = Set<String>()
        let unique = pool.filter { allows($0) && seen.insert($0.slug).inserted }
        let trending = unique.filter { $0.isTrending == true }
        let rest = unique.filter { $0.isTrending != true }.sorted { ($0.imdbRating ?? 0) > ($1.imdbRating ?? 0) }
        return Array((trending + rest).prefix(10))
    }
}
