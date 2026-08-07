import Foundation
import Observation

@MainActor @Observable
final class SearchViewModel {
    private let catalog: any CatalogRepositoryProtocol
    var state: LoadableState = .idle
    var query = ""
    var selectedType: String?
    var selectedGenre: String?
    var selectedYear: String?
    var selectedCountry: String?
    var selectedAccess: String?
    var minRating = 0.0
    var items: [Content] = []
    var filters: CatalogFilters?
    var total = 0
    private var serverTotal = 0
    private var pageSize = 30
    var page = 1
    var isLoadingMore = false

    init(container: AppContainer) { catalog = container.catalogRepository }

    var activeFilterCount: Int {
        [selectedType, selectedGenre, selectedYear, selectedCountry, selectedAccess].compactMap { $0 }.count + (minRating > 0 ? 1 : 0)
    }
    var hasActiveSearchOrFilters: Bool {
        activeFilterCount > 0 || !query.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    var canLoadMore: Bool { page * pageSize < serverTotal && !isLoadingMore }

    var searchID: String {
        [query, selectedType, selectedGenre, selectedYear, selectedCountry, selectedAccess, minRating > 0 ? String(minRating) : nil]
            .map { $0 ?? "" }
            .joined(separator: "|")
    }

    func search(locale: LocaleCode, profile: Profile?) async {
        state = .loading
        do {
            try await Task.sleep(for: .milliseconds(query.isEmpty ? 50 : 350))
            let result = try await catalog.catalog(
                locale: locale,
                search: query.trimmingCharacters(in: .whitespacesAndNewlines),
                type: selectedType,
                genre: selectedGenre,
                year: selectedYear,
                country: selectedCountry,
                access: selectedAccess,
                minRating: minRating > 0 ? minRating : nil,
                page: 1
            )
            try Task.checkCancellation()
            items = result.items.filter { profile?.allows(ageRating: $0.ageRating) ?? true }
            filters = mergedFilters(current: filters, incoming: result.filters)
            serverTotal = result.total
            pageSize = max(result.pageSize, 1)
            total = profile?.isKids == true ? items.count : result.total
            page = 1
            state = .loaded
        } catch is CancellationError {
        } catch {
            state = .failed(message: error.localizedDescription)
        }
    }

    func loadMore(locale: LocaleCode, profile: Profile?) async {
        guard canLoadMore else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }

        do {
            let nextPage = page + 1
            let result = try await catalog.catalog(
                locale: locale,
                search: query.trimmingCharacters(in: .whitespacesAndNewlines),
                type: selectedType,
                genre: selectedGenre,
                year: selectedYear,
                country: selectedCountry,
                access: selectedAccess,
                minRating: minRating > 0 ? minRating : nil,
                page: nextPage
            )
            let visibleItems = result.items.filter { profile?.allows(ageRating: $0.ageRating) ?? true }
            items.append(contentsOf: visibleItems.filter { newItem in !items.contains(where: { $0.id == newItem.id }) })
            serverTotal = result.total
            pageSize = max(result.pageSize, 1)
            total = profile?.isKids == true ? items.count : result.total
            page = nextPage
        } catch {
            state = .failed(message: error.localizedDescription)
        }
    }

    func clearFilters() {
        query = ""
        selectedType = nil
        selectedGenre = nil
        selectedYear = nil
        selectedCountry = nil
        selectedAccess = nil
        minRating = 0
        items = []
        total = 0
        page = 1
    }

    private func mergedFilters(current: CatalogFilters?, incoming: CatalogFilters?) -> CatalogFilters? {
        guard let incoming else { return current }
        return CatalogFilters(
            genres: mergeOptions(current?.genres, incoming.genres),
            years: mergeOptions(current?.years, incoming.years),
            countries: mergeOptions(current?.countries, incoming.countries),
            types: mergeOptions(current?.types, incoming.types),
            access: mergeOptions(current?.access, incoming.access)
        )
    }

    private func mergeOptions(_ current: [FilterOption]?, _ incoming: [FilterOption]?) -> [FilterOption]? {
        guard let incoming else { return current }
        guard let current, !current.isEmpty else { return incoming }

        let freshValues = Set(incoming.map(\.value))
        return incoming + current.filter { !freshValues.contains($0.value) }
    }
}
