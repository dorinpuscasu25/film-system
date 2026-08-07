import Foundation
import Observation

@MainActor @Observable
final class TVPairingViewModel {
    private let devices: any DeviceRepositoryProtocol
    var code = ""
    var device: DeviceLookup?
    var state: LoadableState = .idle
    var successMessage: String?

    init(container: AppContainer) { devices = container.deviceRepository }

    func sanitizeCode() { code = String(code.uppercased().filter { $0.isLetter || $0.isNumber || $0 == "-" }.prefix(12)) }

    func lookup() async {
        state = .loading
        do { device = try await devices.lookup(code: code); state = .loaded }
        catch { state = .failed(message: error.localizedDescription) }
    }

    func authorize(approve: Bool) async -> Bool {
        state = .loading
        do { successMessage = try await devices.authorize(code: code, approve: approve); state = .loaded; return approve }
        catch { state = .failed(message: error.localizedDescription); return false }
    }
}
