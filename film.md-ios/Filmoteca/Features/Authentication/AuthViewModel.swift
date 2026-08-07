import Foundation
import Observation

@MainActor @Observable
final class AuthViewModel {
    private let session: any SessionRepositoryProtocol
    var mode = 0
    var name = ""
    var email = ""
    var password = ""
    var code = ""
    var pendingEmail: String?
    var state: LoadableState = .idle

    init(container: AppContainer) { session = container.sessionRepository }

    var canSubmitCredentials: Bool { !email.isEmpty && password.count >= 6 && (mode == 0 || !name.trimmingCharacters(in: .whitespaces).isEmpty) }
    var canVerify: Bool { code.count == 6 }

    func sanitizeCode() { code = String(code.filter(\.isNumber).prefix(6)) }

    func submit(locale: LocaleCode) async -> AuthResponse? {
        state = .loading
        do {
            if mode == 0 {
                let response = try await session.login(email: email, password: password)
                state = .loaded
                return response
            }
            let response = try await session.register(name: name, email: email, password: password, locale: locale)
            pendingEmail = response.email
            state = .loaded
            return nil
        } catch {
            state = .failed(message: error.localizedDescription)
            return nil
        }
    }

    func verify() async -> AuthResponse? {
        guard let pendingEmail else { return nil }
        state = .loading
        do { let response = try await session.verify(email: pendingEmail, code: code); state = .loaded; return response }
        catch { state = .failed(message: error.localizedDescription); return nil }
    }

    func resend() async {
        guard let pendingEmail else { return }
        do { try await session.resend(email: pendingEmail) }
        catch { state = .failed(message: error.localizedDescription) }
    }
}
