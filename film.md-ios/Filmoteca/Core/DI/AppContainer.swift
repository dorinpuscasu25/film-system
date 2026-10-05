import Foundation

@MainActor
final class AppContainer {
    let configuration: AppConfiguration
    let catalogRepository: any CatalogRepositoryProtocol
    let sessionRepository: any SessionRepositoryProtocol
    let playbackRepository: any PlaybackRepositoryProtocol
    let deviceRepository: any DeviceRepositoryProtocol
    let storeKitService: StoreKitService
    let adService: AdService

    init(
        configuration: AppConfiguration,
        catalogRepository: any CatalogRepositoryProtocol,
        sessionRepository: any SessionRepositoryProtocol,
        playbackRepository: any PlaybackRepositoryProtocol,
        deviceRepository: any DeviceRepositoryProtocol
    ) {
        self.configuration = configuration
        self.catalogRepository = catalogRepository
        self.sessionRepository = sessionRepository
        self.playbackRepository = playbackRepository
        self.deviceRepository = deviceRepository
        self.storeKitService = StoreKitService(session: sessionRepository)
        self.adService = AdService(baseURL: configuration.apiBaseURL)
    }

    static func live(configuration: AppConfiguration = .current) -> AppContainer {
        let api = APIClient(baseURL: configuration.apiBaseURL)
        return AppContainer(
            configuration: configuration,
            catalogRepository: LiveCatalogRepository(api: api),
            sessionRepository: LiveSessionRepository(api: api),
            playbackRepository: LivePlaybackRepository(api: api),
            deviceRepository: LiveDeviceRepository(api: api)
        )
    }
}
