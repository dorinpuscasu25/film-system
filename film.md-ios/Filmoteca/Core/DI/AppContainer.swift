import Foundation

@MainActor
final class AppContainer {
    let configuration: AppConfiguration
    let catalogRepository: any CatalogRepositoryProtocol
    let sessionRepository: any SessionRepositoryProtocol
    let playbackRepository: any PlaybackRepositoryProtocol
    let deviceRepository: any DeviceRepositoryProtocol

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
    }

    static func live(configuration: AppConfiguration = .production) -> AppContainer {
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
