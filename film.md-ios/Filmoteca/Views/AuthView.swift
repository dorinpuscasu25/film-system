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
                    VStack(spacing: 8) { Text(viewModel.pendingEmail == nil ? (viewModel.mode == 0 ? app.tr("Bine ai revenit") : app.tr("Creează un cont")) : app.tr("Confirmă emailul")).font(.largeTitle.bold()); Text(subtitle).foregroundStyle(FilmotecaTheme.muted).multilineTextAlignment(.center) }
                    if viewModel.pendingEmail != nil { verificationForm } else { credentialsForm }
                    if let error = viewModel.state.errorMessage { Text(error).font(.footnote).foregroundStyle(.red).padding(12).frame(maxWidth: .infinity).background(.red.opacity(0.1), in: RoundedRectangle(cornerRadius: 10)) }
                    if viewModel.pendingEmail == nil {
                        Button(viewModel.mode == 0 ? app.tr("Nu ai cont? Înregistrează-te") : app.tr("Ai deja cont? Autentifică-te")) { withAnimation { viewModel.mode = viewModel.mode == 0 ? 1 : 0; viewModel.state = .idle } }.foregroundStyle(.white.opacity(0.8))
                    }
                    Text(app.tr("Prin continuare accepți Termenii și Politica de confidențialitate FILMOTECA.md.")).font(.caption2).foregroundStyle(.tertiary).multilineTextAlignment(.center)
                }.padding(22)
            }.background(FilmotecaTheme.background)
            .toolbar { ToolbarItem(placement: .topBarTrailing) { Button(app.t("close")) { dismiss() }.foregroundStyle(.white) } }
            .sheet(isPresented: $passwordResetPresented) {
                PasswordResetSheet(viewModel: viewModel)
                    .presentationDetents([.medium])
            }
        }
    }

    private var subtitle: String { viewModel.pendingEmail != nil ? app.tr("Am trimis un cod din 6 cifre la {0}.", viewModel.pendingEmail ?? "") : app.tr("Filmele tale, pe orice ecran, cu progres sincronizat.") }

    private var credentialsForm: some View {
        VStack(spacing: 14) {
            Picker(app.tr("Mod"), selection: Binding(get: { viewModel.mode }, set: { viewModel.mode = $0 })) { Text(app.tr("Autentificare")).tag(0); Text(app.tr("Cont nou")).tag(1) }.pickerStyle(.segmented)
            if viewModel.mode == 1 { field(app.tr("Nume"), text: Binding(get: { viewModel.name }, set: { viewModel.name = $0 }), icon: "person") }
            field(app.tr("Email"), text: Binding(get: { viewModel.email }, set: { viewModel.email = $0 }), icon: "envelope", keyboard: .emailAddress)
            PasswordField(
                placeholder: app.tr("Parolă"),
                text: Binding(get: { viewModel.password }, set: { viewModel.password = $0 }),
                contentType: viewModel.mode == 0 ? .password : .newPassword,
                showLabel: app.tr("Arată parola"),
                hideLabel: app.tr("Ascunde parola")
            )
            if viewModel.mode == 1 {
                Text(app.t("password_min_length")).font(.caption).foregroundStyle(PasswordPolicy.isValid(viewModel.password) ? .green : FilmotecaTheme.muted).frame(maxWidth: .infinity, alignment: .leading)
            }
            Button { Task { if let response = await viewModel.submit(locale: app.locale) { await app.authenticate(response) } } } label: { Group { if viewModel.state.isLoading { ProgressView().tint(.white) } else { Text(viewModel.mode == 0 ? app.tr("Intră în cont") : app.tr("Continuă")) } }.frame(maxWidth: .infinity) }.buttonStyle(GlassButtonStyle(prominent: true)).disabled(viewModel.state.isLoading || !viewModel.canSubmitCredentials).opacity(viewModel.canSubmitCredentials ? 1 : 0.5).padding(.top, 4)
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
            Button { Task { if let response = await viewModel.verify() { await app.authenticate(response) } } } label: { if viewModel.state.isLoading { ProgressView().tint(.white) } else { Text(app.tr("Confirmă contul")) } }.buttonStyle(GlassButtonStyle(prominent: true)).disabled(!viewModel.canVerify)
            Button(app.tr("Retrimite codul")) { Task { await viewModel.resend() } }.foregroundStyle(.white.opacity(0.75))
        }
    }

    private func field(_ placeholder: String, text: Binding<String>, icon: String, keyboard: UIKeyboardType = .default) -> some View {
        IconTextField(placeholder: placeholder, text: text, icon: icon, keyboard: keyboard)
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
                        RevealableSecureField(
                            placeholder: app.t("password_new"),
                            text: Binding(get: { viewModel.resetPassword }, set: { viewModel.resetPassword = $0 }),
                            contentType: .newPassword,
                            showLabel: app.tr("Arată parola"),
                            hideLabel: app.tr("Ascunde parola")
                        )
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
                    Section(app.tr("Email")) {
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


/// Shared chrome for the sign-in inputs: a fixed-width leading icon so every
/// field lines up, and an accent border while focused.
private struct AuthInputField<Content: View>: View {
    let icon: String
    var isFocused = false
    @ViewBuilder let content: Content

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: icon)
                .font(.body)
                .foregroundStyle(isFocused ? FilmotecaTheme.accent : .secondary)
                .frame(width: 22)
            content
        }
        .padding(.horizontal, 15)
        .frame(minHeight: 52)
        .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 13))
        .overlay(
            RoundedRectangle(cornerRadius: 13)
                .stroke(isFocused ? FilmotecaTheme.accent.opacity(0.8) : FilmotecaTheme.hairline, lineWidth: isFocused ? 1.5 : 1)
        )
        .animation(.easeOut(duration: 0.15), value: isFocused)
    }
}

/// Plain text input (email, name) with the same chrome and focus border as the password field.
private struct IconTextField: View {
    let placeholder: String
    @Binding var text: String
    let icon: String
    let keyboard: UIKeyboardType
    @FocusState private var focused: Bool

    var body: some View {
        AuthInputField(icon: icon, isFocused: focused) {
            TextField(placeholder, text: $text)
                .textInputAutocapitalization(keyboard == .emailAddress ? .never : .words)
                .autocorrectionDisabled(keyboard == .emailAddress)
                .textContentType(keyboard == .emailAddress ? .emailAddress : .name)
                .keyboardType(keyboard)
                .focused($focused)
        }
    }
}

/// Password input with a lock icon and a show / hide toggle.
private struct PasswordField: View {
    let placeholder: String
    @Binding var text: String
    let contentType: UITextContentType
    let showLabel: String
    let hideLabel: String
    @State private var focused = false

    var body: some View {
        AuthInputField(icon: "lock", isFocused: focused) {
            RevealableSecureField(
                placeholder: placeholder,
                text: $text,
                contentType: contentType,
                showLabel: showLabel,
                hideLabel: hideLabel,
                onFocusChange: { focused = $0 }
            )
        }
    }
}

/// A secure field whose content can be revealed. Both inputs stay mounted and
/// focus moves between them on toggle, so the keyboard stays up and nothing
/// typed is lost. Focus is bound to the inputs only, never to the toggle.
private struct RevealableSecureField: View {
    let placeholder: String
    @Binding var text: String
    let contentType: UITextContentType
    let showLabel: String
    let hideLabel: String
    var onFocusChange: ((Bool) -> Void)? = nil
    @State private var revealed = false

    private enum Input { case secure, plain }
    @FocusState private var focusedInput: Input?

    var body: some View {
        HStack(spacing: 8) {
            ZStack {
                TextField(placeholder, text: $text)
                    .textContentType(contentType)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .focused($focusedInput, equals: .plain)
                    .opacity(revealed ? 1 : 0)
                    .allowsHitTesting(revealed)
                    .accessibilityHidden(!revealed)
                SecureField(placeholder, text: $text)
                    .textContentType(contentType)
                    .focused($focusedInput, equals: .secure)
                    .opacity(revealed ? 0 : 1)
                    .allowsHitTesting(!revealed)
                    .accessibilityHidden(revealed)
            }

            Button {
                let hadFocus = focusedInput != nil
                revealed.toggle()
                if hadFocus { focusedInput = revealed ? .plain : .secure }
            } label: {
                Image(systemName: revealed ? "eye.slash" : "eye")
                    .font(.body)
                    .foregroundStyle(.secondary)
                    .frame(width: 36, height: 36)
                    .contentShape(Rectangle())
                    .contentTransition(.symbolEffect(.replace))
            }
            .buttonStyle(.borderless)
            .tint(.secondary)
            .accessibilityLabel(revealed ? hideLabel : showLabel)
        }
        .onChange(of: focusedInput) { _, value in onFocusChange?(value != nil) }
    }
}
