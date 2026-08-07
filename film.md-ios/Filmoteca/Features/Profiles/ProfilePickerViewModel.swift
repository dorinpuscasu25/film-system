import Foundation
import Observation

@MainActor @Observable
final class ProfilePickerViewModel {
    private let session: any SessionRepositoryProtocol
    var name = ""
    var isKids = false
    var isCreatePresented = false
    var isEditPresented = false
    var editingProfile: Profile?
    var state: LoadableState = .idle

    init(container: AppContainer) { session = container.sessionRepository }

    func create(app: FilmotecaModel) async {
        state = .loading
        do {
            let response = try await session.createProfile(name: name, color: "from-red-500 to-purple-600", isKids: isKids)
            if let user = app.user { app.applyUser(User(idValue: user.idValue, name: user.name, email: user.email, preferredLocale: user.preferredLocale, wallet: user.wallet, profiles: response.profiles)) }
            name = ""; isKids = false; isCreatePresented = false; state = .loaded
        } catch { state = .failed(message: error.localizedDescription); app.globalError = error.localizedDescription }
    }

    func beginEditing(_ profile: Profile) {
        editingProfile = profile
        name = profile.name
        isKids = profile.isKids == true
        isEditPresented = true
    }

    func update(app: FilmotecaModel) async {
        guard let editingProfile else { return }
        state = .loading
        do {
            let response = try await session.updateProfile(id: editingProfile.id, name: name, color: editingProfile.avatarColor ?? "from-red-500 to-purple-600", isKids: isKids)
            applyProfiles(response.profiles, app: app)
            self.editingProfile = nil
            name = ""
            isKids = false
            isEditPresented = false
            state = .loaded
        } catch {
            state = .failed(message: error.localizedDescription)
            app.globalError = error.localizedDescription
        }
    }

    func delete(app: FilmotecaModel) async {
        guard let editingProfile else { return }
        state = .loading
        do {
            let response = try await session.deleteProfile(id: editingProfile.id)
            applyProfiles(response.profiles, app: app)
            self.editingProfile = nil
            name = ""
            isKids = false
            isEditPresented = false
            state = .loaded
        } catch {
            state = .failed(message: error.localizedDescription)
            app.globalError = error.localizedDescription
        }
    }

    private func applyProfiles(_ profiles: [Profile], app: FilmotecaModel) {
        guard let user = app.user else { return }
        app.applyUser(User(
            idValue: user.idValue,
            name: user.name,
            email: user.email,
            preferredLocale: user.preferredLocale,
            wallet: user.wallet,
            profiles: profiles
        ))
    }
}
