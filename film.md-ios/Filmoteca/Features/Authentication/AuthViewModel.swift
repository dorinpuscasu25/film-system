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

    // Password reset
    var resetEmail = ""
    var resetCode = ""
    var resetPassword = ""
    var resetCodeSent = false

    var canRequestReset: Bool { resetEmail.contains("@") && resetEmail.count >= 5 }
    var canConfirmReset: Bool { resetCode.count == 6 && resetPassword.count >= 8 }

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

    // MARK: - Password reset

    /// Requests a 6-digit reset code.
    ///
    /// The API answers the same way whether or not the address exists, so the
    /// screen advances regardless — revealing which emails are registered would
    /// be an account-enumeration leak.
    func requestPasswordReset() async -> Bool {
        state = .loading
        do {
            try await session.forgotPassword(email: resetEmail.trimmingCharacters(in: .whitespacesAndNewlines))
            resetCodeSent = true
            state = .loaded
            return true
        } catch {
            state = .failed(message: error.localizedDescription)
            return false
        }
    }

    func confirmPasswordReset() async -> Bool {
        state = .loading
        do {
            try await session.resetPassword(
                email: resetEmail.trimmingCharacters(in: .whitespacesAndNewlines),
                code: resetCode,
                password: resetPassword
            )
            state = .loaded
            return true
        } catch {
            state = .failed(message: error.localizedDescription)
            return false
        }
    }

    func sanitizeResetCode() { resetCode = String(resetCode.filter(\.isNumber).prefix(6)) }

    func preparePasswordReset() {
        resetEmail = email
        resetCode = ""
        resetPassword = ""
        resetCodeSent = false
        state = .idle
    }
}
