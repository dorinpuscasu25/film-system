import SwiftUI

struct AuthView: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var viewModel: AuthViewModel
    @State private var passwordResetPresented = false

    init(container: AppContainer) {
        _viewModel = State(initialValue: AuthViewModel(container: container))
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 24) {
                    FilmotecaWordmark().padding(.top, 22)
                    VStack(spacing: 8) { Text(viewModel.pendingEmail == nil ? (viewModel.mode == 0 ? "Bine ai revenit" : "Creează un cont") : "Confirmă emailul").font(.largeTitle.bold()); Text(subtitle).foregroundStyle(FilmotecaTheme.muted).multilineTextAlignment(.center) }
                    if viewModel.pendingEmail != nil { verificationForm } else { credentialsForm }
                    if let error = viewModel.state.errorMessage { Text(error).font(.footnote).foregroundStyle(.red).padding(12).frame(maxWidth: .infinity).background(.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 10)) }
                    if viewModel.pendingEmail == nil {
                        Button(viewModel.mode == 0 ? "Nu ai cont? Înregistrează-te" : "Ai deja cont? Autentifică-te") { withAnimation { viewModel.mode = viewModel.mode == 0 ? 1 : 0; viewModel.state = .idle } }.foregroundStyle(.white.opacity(0.8))
                    }
                    Text("Prin continuare accepți Termenii și Politica de confidențialitate FILMOTECA.md.").font(.caption2).foregroundStyle(.tertiary).multilineTextAlignment(.center)
                }.padding(22)
            }.background(FilmotecaTheme.background)
            .toolbar { ToolbarItem(placement: .topBarTrailing) { Button(app.t("close")) { dismiss() }.foregroundStyle(.white) } }
            .sheet(isPresented: $passwordResetPresented) {
                PasswordResetSheet(viewModel: viewModel)
                    .presentationDetents([.medium])
            }
        }
    }

    private var subtitle: String { viewModel.pendingEmail != nil ? "Am trimis un cod din 6 cifre la \(viewModel.pendingEmail ?? "")." : "Filmele tale, pe orice ecran, cu progres sincronizat." }

    private var credentialsForm: some View {
        VStack(spacing: 14) {
            Picker("Mod", selection: Binding(get: { viewModel.mode }, set: { viewModel.mode = $0 })) { Text("Autentificare").tag(0); Text("Cont nou").tag(1) }.pickerStyle(.segmented)
            if viewModel.mode == 1 { field("Nume", text: Binding(get: { viewModel.name }, set: { viewModel.name = $0 }), icon: "person") }
            field("Email", text: Binding(get: { viewModel.email }, set: { viewModel.email = $0 }), icon: "envelope", keyboard: .emailAddress)
            SecureField("Parolă", text: Binding(get: { viewModel.password }, set: { viewModel.password = $0 })).textContentType(viewModel.mode == 0 ? .password : .newPassword).padding(15).background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 13)).overlay(RoundedRectangle(cornerRadius: 13).stroke(FilmotecaTheme.hairline))
            Button { Task { if let response = await viewModel.submit(locale: app.locale) { await app.authenticate(response) } } } label: { if viewModel.state.isLoading { ProgressView().tint(.white) } else { Text(viewModel.mode == 0 ? "Intră în cont" : "Continuă") } }.buttonStyle(GlassButtonStyle(prominent: true)).disabled(viewModel.state.isLoading || !viewModel.canSubmitCredentials).opacity(viewModel.canSubmitCredentials ? 1 : 0.5).frame(maxWidth: .infinity)
            if viewModel.mode == 0 {
                Button(app.t("forgot_password")) {
                    viewModel.preparePasswordReset()
                    passwordResetPresented = true
                }
                .font(.footnote)
                .foregroundStyle(.white.opacity(0.75))
            }
        }
    }

    private var verificationForm: some View {
        VStack(spacing: 16) {
            TextField("000000", text: Binding(get: { viewModel.code }, set: { viewModel.code = $0 })).keyboardType(.numberPad).multilineTextAlignment(.center).font(.system(size: 30, weight: .bold, design: .monospaced)).tracking(8).padding().background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 14)).onChange(of: viewModel.code) { _, _ in viewModel.sanitizeCode() }
            Button { Task { if let response = await viewModel.verify() { await app.authenticate(response) } } } label: { if viewModel.state.isLoading { ProgressView().tint(.white) } else { Text("Confirmă contul") } }.buttonStyle(GlassButtonStyle(prominent: true)).disabled(!viewModel.canVerify)
            Button("Retrimite codul") { Task { await viewModel.resend() } }.foregroundStyle(.white.opacity(0.75))
        }
    }

    private func field(_ placeholder: String, text: Binding<String>, icon: String, keyboard: UIKeyboardType = .default) -> some View {
        HStack { Image(systemName: icon).foregroundStyle(.secondary); TextField(placeholder, text: text).textInputAutocapitalization(keyboard == .emailAddress ? .never : .words).keyboardType(keyboard) }.padding(15).background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 13)).overlay(RoundedRectangle(cornerRadius: 13).stroke(FilmotecaTheme.hairline))
    }

}

/// Two-step password reset: request a 6-digit code by email, then set the new
/// password. Mirrors the registration confirmation flow the app already uses.
private struct PasswordResetSheet: View {
    let viewModel: AuthViewModel

    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss

    @State private var didFinish = false

    var body: some View {
        NavigationStack {
            Form {
                if didFinish {
                    Section {
                        Label(app.t("password_reset_done"), systemImage: "checkmark.seal.fill")
                            .foregroundStyle(.green)
                    }
                } else if viewModel.resetCodeSent {
                    Section {
                        Text(app.t("password_reset_code_sent"))
                            .font(.footnote)
                            .foregroundStyle(FilmotecaTheme.muted)
                    }
                    Section(app.t("password_reset_code")) {
                        TextField("000000", text: Binding(get: { viewModel.resetCode }, set: { viewModel.resetCode = $0 }))
                            .keyboardType(.numberPad)
                            .multilineTextAlignment(.center)
                            .font(.system(size: 26, weight: .bold, design: .monospaced))
                            .tracking(6)
                            .onChange(of: viewModel.resetCode) { _, _ in viewModel.sanitizeResetCode() }
                    }
                    Section(app.t("password_new")) {
                        SecureField(app.t("password_new"), text: Binding(get: { viewModel.resetPassword }, set: { viewModel.resetPassword = $0 }))
                            .textContentType(.newPassword)
                        Text(app.t("password_min_length"))
                            .font(.caption)
                            .foregroundStyle(FilmotecaTheme.muted)
                    }
                } else {
                    Section {
                        Text(app.t("password_reset_intro"))
                            .font(.footnote)
                            .foregroundStyle(FilmotecaTheme.muted)
                    }
                    Section("Email") {
                        TextField("email@exemplu.md", text: Binding(get: { viewModel.resetEmail }, set: { viewModel.resetEmail = $0 }))
                            .keyboardType(.emailAddress)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .textContentType(.emailAddress)
                    }
                }

                if let error = viewModel.state.errorMessage {
                    Section { Text(error).font(.footnote).foregroundStyle(.red) }
                }

                if !didFinish {
                    Section {
                        Button {
                            Task {
                                if viewModel.resetCodeSent {
                                    if await viewModel.confirmPasswordReset() { didFinish = true }
                                } else {
                                    _ = await viewModel.requestPasswordReset()
                                }
                            }
                        } label: {
                            HStack {
                                Spacer()
                                if viewModel.state.isLoading { ProgressView().padding(.trailing, 6) }
                                Text(viewModel.resetCodeSent ? app.t("password_reset_confirm") : app.t("password_reset_send"))
                                    .fontWeight(.bold)
                                Spacer()
                            }
                        }
                        .disabled(
                            viewModel.state.isLoading
                                || (viewModel.resetCodeSent ? !viewModel.canConfirmReset : !viewModel.canRequestReset)
                        )
                    }
                }
            }
            .scrollContentBackground(.hidden)
            .background(FilmotecaTheme.background)
            .navigationTitle(app.t("forgot_password"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(didFinish ? app.t("close") : app.t("cancel")) { dismiss() }
                }
            }
        }
    }
}
