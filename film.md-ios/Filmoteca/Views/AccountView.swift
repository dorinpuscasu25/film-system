import SwiftUI

/// Identifies a CMS page opened from the footer menu.
private struct CmsPageRoute: Identifiable, Hashable {
    let slug: String
    let title: String
    var id: String { slug }
}

struct AccountView: View {
    private enum AccountSection: String, CaseIterable, Identifiable {
        case films = "Filmele mele"
        case favorites = "Favorite"
        case wallet = "Portofel"
        case settings = "Setări"
        var id: String { rawValue }
    }

    @Environment(FilmotecaModel.self) private var app
    @State private var tvPairingPresented = false
    @State private var topUpPresented = false
    @State private var inAppURL: URL?
    @State private var footerMenu: PublicMenuResponse?
    @State private var isFooterLoading = true
    @State private var selectedSection: AccountSection = .films
    @State private var continueItems: [ContinueItem] = []
    @State private var favoriteContents: [Content] = []
    @State private var selectedContent: Content?
    @State private var accountSettingsPresented = false
    @State private var passwordSettingsPresented = false
    @State private var deleteAccountPresented = false
    @State private var cmsPage: CmsPageRoute?
    @State private var isDashboardLoading = false

    var body: some View {
        ScrollView {
            VStack(spacing: 22) {
                FilmotecaWordmark().frame(maxWidth: .infinity, alignment: .leading)
                Group {
                    if app.isAuthenticated { authenticatedContent } else { guestContent }
                }
                .filmotecaReveal(delay: 0.04)
                footerMenuContent
                Text("FILMOTECA iOS • 1.0").font(.caption2).foregroundStyle(.tertiary).padding(.top, 8)
            }.padding(18).padding(.bottom, 25)
        }
        .background(FilmotecaTheme.background)
        .navigationTitle(app.t("account"))
        .navigationBarTitleDisplayMode(.inline)
        .sheet(isPresented: $tvPairingPresented) { TVPairingView(container: app.container) }
        .sheet(isPresented: $topUpPresented, onDismiss: { Task { await app.refreshAccount() } }) {
            CreditPackSheet()
        }
        .sheet(isPresented: Binding(get: { inAppURL != nil }, set: { if !$0 { inAppURL = nil } })) {
            if let inAppURL { InAppBrowser(url: inAppURL).ignoresSafeArea() }
        }
        .sheet(item: $selectedContent) { content in
            NavigationStack { ContentDetailView(seed: content, container: app.container) }
        }
        .sheet(isPresented: $accountSettingsPresented) { AccountSettingsSheet() }
        .sheet(isPresented: $passwordSettingsPresented) { PasswordSettingsSheet() }
        .sheet(isPresented: $deleteAccountPresented) { DeleteAccountSheet() }
        .sheet(item: $cmsPage) { route in CmsPageView(slug: route.slug, fallbackTitle: route.title) }
        .task(id: app.locale) { await loadFooterMenu() }
        .task(id: "\(app.activeProfile?.id ?? "guest")-\(app.locale.rawValue)") {
            if app.isAuthenticated { await loadDashboard() }
        }
    }

    private var guestContent: some View {
        VStack(spacing: 20) {
            ZStack { Circle().fill(FilmotecaTheme.surface); Image(systemName: "person.crop.circle").font(.system(size: 64)).foregroundStyle(.white.opacity(0.5)) }.frame(width: 110, height: 110)
            Text(app.tr("Intră în universul FILMOTECA")).filmotecaTitle(.title2)
            Text(app.tr("Sincronizează progresul, creează profiluri și păstrează filmele preferate.")).foregroundStyle(FilmotecaTheme.muted).multilineTextAlignment(.center)
            Button(app.t("login")) { app.authPresented = true }.buttonStyle(GlassButtonStyle(prominent: true))
        }.padding(.vertical, 50)
    }

    private var authenticatedContent: some View {
        VStack(spacing: 20) {
            Button { app.profilePickerPresented = true } label: {
                HStack(spacing: 14) {
                    ZStack { RoundedRectangle(cornerRadius: 17).fill(LinearGradient(colors: [FilmotecaTheme.accent, .purple], startPoint: .topLeading, endPoint: .bottomTrailing)); Text(app.activeProfile?.avatarLabel ?? String(app.user?.name.prefix(1) ?? "U")).font(.title.bold()) }.frame(width: 62, height: 62)
                    VStack(alignment: .leading, spacing: 4) { Text(app.activeProfile?.name ?? app.user?.name ?? "").filmotecaTitle(.title3); Text(app.user?.email ?? "").font(.caption).foregroundStyle(FilmotecaTheme.muted) }
                    Spacer(); Image(systemName: "chevron.up.chevron.down").font(.caption).foregroundStyle(.secondary)
                }.padding(14).background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 20))
            }.buttonStyle(.plain)

            Picker(app.tr("Secțiune cont"), selection: $selectedSection) {
                ForEach(AccountSection.allCases) { section in Text(app.tr(section.rawValue)).tag(section) }
            }
            .pickerStyle(.segmented)

            Group {
                switch selectedSection {
                case .films: myFilmsContent
                case .favorites: favoritesContent
                case .wallet: walletContent
                case .settings: settingsContent
                }
            }
            .filmotecaReveal(delay: 0.04)
        }
    }

    private var myFilmsContent: some View {
        VStack(alignment: .leading, spacing: 18) {
            if isDashboardLoading && continueItems.isEmpty && (app.account?.library.isEmpty != false) {
                ProgressView().tint(FilmotecaTheme.accent).frame(maxWidth: .infinity).padding(35)
            }

            if !continueItems.isEmpty {
                accountSectionTitle(app.tr("Continuă vizionarea"), icon: "play.circle")
                ForEach(continueItems) { item in
                    contentAccountRow(
                        title: item.title ?? item.contentSlug,
                        subtitle: app.tr("{0}% vizionat", "\(Int(item.progressPercent.rounded()))"),
                        imageURL: item.posterURL,
                        badge: nil,
                        progress: item.progressPercent / 100
                    ) { Task { await openContent(item.contentSlug) } }
                }
            }

            accountSectionTitle(app.tr("Biblioteca mea"), icon: "film.stack")
            if app.account?.library.isEmpty != false {
                emptyAccountState(app.tr("Nu ai încă filme cumpărate."), icon: "film")
            } else {
                ForEach(app.account?.library ?? []) { item in
                    contentAccountRow(
                        title: item.contentTitle,
                        subtitle: librarySubtitle(item),
                        imageURL: item.posterURL,
                        badge: item.isActive ? app.tr("ACTIV") : app.tr("EXPIRAT"),
                        progress: nil
                    ) { Task { await openContent(item.contentSlug) } }
                }
            }
        }
    }

    private var favoritesContent: some View {
        VStack(alignment: .leading, spacing: 14) {
            accountSectionTitle(app.tr("Favoritele profilului"), icon: "heart.fill")
            if isDashboardLoading && favoriteContents.isEmpty {
                ProgressView().tint(FilmotecaTheme.accent).frame(maxWidth: .infinity).padding(35)
            } else if favoriteContents.isEmpty {
                emptyAccountState(app.tr("Adaugă titluri în lista ta pentru a le găsi aici."), icon: "heart")
            } else {
                LazyVGrid(columns: [GridItem(.adaptive(minimum: 135), spacing: 14)], spacing: 18) {
                    ForEach(favoriteContents) { content in
                        Button { selectedContent = content } label: {
                            VStack(alignment: .leading, spacing: 8) {
                                RemoteImage(url: content.poster)
                                    .aspectRatio(2 / 3, contentMode: .fill)
                                    .clipShape(RoundedRectangle(cornerRadius: 13))
                                Text(content.title).font(.subheadline.bold()).lineLimit(2).foregroundStyle(.white)
                            }
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private var walletContent: some View {
        VStack(alignment: .leading, spacing: 18) {
            VStack(alignment: .leading, spacing: 14) {
                Text(app.tr("SOLD DISPONIBIL")).font(.caption2.weight(.black)).tracking(1.4).foregroundStyle(FilmotecaTheme.muted)
                HStack(alignment: .firstTextBaseline, spacing: 7) {
                    Text(app.balance, format: .number.precision(.fractionLength(2))).font(.system(size: 38, weight: .black, design: .rounded))
                    Text(app.currency).font(.headline).foregroundStyle(FilmotecaTheme.muted)
                }
                Button { topUpPresented = true } label: { Label(app.t("topup"), systemImage: "creditcard") }
                    .buttonStyle(GlassButtonStyle(prominent: true))
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(20)
            .background(LinearGradient(colors: [FilmotecaTheme.elevated, FilmotecaTheme.surface], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 22))
            .overlay(RoundedRectangle(cornerRadius: 22).stroke(FilmotecaTheme.hairline))

            accountSectionTitle(app.tr("Istoricul tranzacțiilor"), icon: "clock.arrow.circlepath")
            if app.account?.transactions.isEmpty != false {
                emptyAccountState(app.tr("Nu există tranzacții."), icon: "list.bullet.rectangle")
            } else {
                VStack(spacing: 0) {
                    ForEach(Array((app.account?.transactions ?? []).enumerated()), id: \.element.id) { index, transaction in
                        HStack(spacing: 12) {
                            Image(systemName: transaction.amount >= 0 ? "arrow.down.left.circle.fill" : "arrow.up.right.circle.fill")
                                .font(.title3)
                                .foregroundStyle(transaction.amount >= 0 ? .green : FilmotecaTheme.accent)
                            VStack(alignment: .leading, spacing: 3) {
                                Text(transaction.description ?? transaction.type.capitalized).font(.subheadline.bold())
                                Text([transaction.status?.uppercased(), transaction.createdAt].compactMap { $0 }.joined(separator: " • "))
                                    .font(.caption2).foregroundStyle(FilmotecaTheme.muted).lineLimit(1)
                            }
                            Spacer()
                            Text("\(transaction.amount >= 0 ? "+" : "")\(transaction.amount.formatted(.number.precision(.fractionLength(2)))) \(transaction.currency)")
                                .font(.subheadline.bold()).monospacedDigit()
                        }
                        .padding(14)
                        if index < (app.account?.transactions.count ?? 0) - 1 {
                            Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                        }
                    }
                }
                .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18))
                .clipShape(RoundedRectangle(cornerRadius: 18))
            }
        }
    }

    private var settingsContent: some View {
        VStack(alignment: .leading, spacing: 18) {
            accountSectionTitle(app.tr("Cont și securitate"), icon: "person.crop.circle")
            VStack(spacing: 0) {
                accountRow(app.tr("Datele contului"), icon: "person.text.rectangle", action: { accountSettingsPresented = true })
                Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                accountRow(app.tr("Schimbă parola"), icon: "lock.rotation", action: { passwordSettingsPresented = true })
                Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                accountRow(app.tr("Gestionează profilurile"), icon: "person.2", action: { app.profilePickerPresented = true })
                Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                accountRow(app.tr("Conectează televizorul"), icon: "tv", action: { tvPairingPresented = true })
                Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                Menu {
                    ForEach(LocaleCode.allCases) { locale in
                        Button {
                            app.locale = locale
                            Task { await app.refreshAccount(); await loadDashboard() }
                            app.refreshID = UUID()
                        } label: {
                            Label(locale.title, systemImage: app.locale == locale ? "checkmark" : "globe")
                        }
                    }
                } label: {
                    accountRowLabel(app.tr("Limba"), icon: "globe", value: app.locale.title)
                }
                Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                Button(role: .destructive) { app.logout() } label: {
                    accountRowLabel(app.tr("Ieși din cont"), icon: "rectangle.portrait.and.arrow.right", value: nil).foregroundStyle(.red)
                }
            }
            .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18))
            .clipShape(RoundedRectangle(cornerRadius: 18))

            accountSectionTitle(app.tr("Zonă periculoasă"), icon: "exclamationmark.triangle")
            VStack(spacing: 0) {
                Button(role: .destructive) { deleteAccountPresented = true } label: {
                    accountRowLabel(app.tr("Șterge contul"), icon: "trash", value: nil).foregroundStyle(.red)
                }
            }
            .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18))
            .clipShape(RoundedRectangle(cornerRadius: 18))

            accountSectionTitle(app.tr("Rezumat"), icon: "chart.bar")
            HStack(spacing: 10) {
                summaryCard("\(app.user?.profiles?.count ?? 0)", label: app.tr("Profiluri"))
                summaryCard("\(app.account?.library.count ?? 0)", label: app.tr("Titluri"))
                summaryCard("\(app.favorites.count)", label: app.tr("Favorite"))
            }
        }
    }

    @ViewBuilder private var footerMenuContent: some View {
        if isFooterLoading && footerMenu == nil {
            HStack(spacing: 12) {
                ProgressView().tint(FilmotecaTheme.accent)
                Text(app.t("loading_legal"))
                    .font(.caption)
                    .foregroundStyle(FilmotecaTheme.muted)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(18)
            .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18))
        } else {
            ForEach(footerMenus) { menu in
                let items = footerItems(for: menu)
                if !items.isEmpty {
                    VStack(alignment: .leading, spacing: 0) {
                        HStack {
                            Text(menu.name.uppercased())
                                .font(.caption2.weight(.black))
                                .tracking(1.3)
                                .foregroundStyle(FilmotecaTheme.muted)
                            Spacer()
                            Image(systemName: "checkmark.shield")
                                .font(.caption)
                                .foregroundStyle(FilmotecaTheme.accent)
                        }
                        .padding(16)

                        Divider().overlay(FilmotecaTheme.hairline)

                        ForEach(Array(items.enumerated()), id: \.element.id) { index, item in
                            Button { openFooterItem(item) } label: {
                                HStack(spacing: 13) {
                                    Image(systemName: icon(for: item.label))
                                        .frame(width: 24)
                                        .foregroundStyle(FilmotecaTheme.accent)
                                    Text(item.label)
                                        .font(.subheadline)
                                        .multilineTextAlignment(.leading)
                                    Spacer()
                                    Image(systemName: item.target == "_blank" ? "arrow.up.right" : "chevron.right")
                                        .font(.caption)
                                        .foregroundStyle(.tertiary)
                                }
                                .padding(.horizontal, 16)
                                .padding(.vertical, 14)
                                .contentShape(Rectangle())
                                .foregroundStyle(.white)
                            }
                            .buttonStyle(.plain)

                            if index < items.count - 1 {
                                Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 52)
                            }
                        }
                    }
                    .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .filmotecaReveal(delay: 0.12)
                }
            }
        }
    }

    private var footerMenus: [PublicMenu] {
        if let menus = footerMenu?.menus, !menus.isEmpty { return menus }
        if let menu = footerMenu?.menu { return [menu] }
        return []
    }

    private func footerItems(for menu: PublicMenu) -> [PublicMenuItem] {
        let languageLabels = Set(["english", "romana", "română", "русский"])
        return (footerMenu?.items ?? [])
            .filter { $0.menuID == menu.id && !languageLabels.contains($0.label.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()) }
            .sorted { lhs, rhs in
                if lhs.sortOrder == rhs.sortOrder { return lhs.id < rhs.id }
                return lhs.sortOrder < rhs.sortOrder
            }
    }

    private func openFooterItem(_ item: PublicMenuItem) {
        let rawURL = item.resolvedURL.trimmingCharacters(in: .whitespacesAndNewlines)

        // CMS pages are rendered natively from the API so the legal pages stay
        // reachable in-app even when the website is unavailable. Anything the
        // app cannot render itself still falls back to the browser.
        if let slug = cmsSlug(from: rawURL) {
            cmsPage = CmsPageRoute(slug: slug, title: item.label)
            return
        }

        if let url = URL(string: rawURL), url.scheme != nil {
            inAppURL = url
            return
        }

        let path = rawURL.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        inAppURL = app.container.configuration.webBaseURL.appending(path: path)
    }

    /// Maps a footer link to a CMS slug the app can render natively.
    ///
    /// Handles the `/page/{slug}` route plus the standalone web routes that map
    /// one-to-one onto CMS pages. External hosts are never treated as CMS pages.
    private func cmsSlug(from rawURL: String) -> String? {
        var path = rawURL

        if let url = URL(string: rawURL), url.scheme != nil {
            guard let host = url.host(), host == app.container.configuration.webBaseURL.host() else { return nil }
            path = url.path
        }

        let segments = path
            .split(separator: "/", omittingEmptySubsequences: true)
            .map(String.init)

        if segments.count == 2, segments[0] == "page" { return segments[1] }
        if segments.count == 1, Self.standaloneCmsRoutes.contains(segments[0]) { return segments[0] }

        return nil
    }

    /// Web routes that render a CMS page without the `/page/` prefix.
    private static let standaloneCmsRoutes: Set<String> = ["contacte", "politica-de-preturi"]

    private func icon(for label: String) -> String {
        let normalized = label.folding(options: [.diacriticInsensitive, .caseInsensitive], locale: nil)
        if normalized.contains("cookie") { return "app.badge.checkmark" }
        if normalized.contains("pret") || normalized.contains("price") { return "banknote" }
        if normalized.contains("confident") || normalized.contains("privacy") { return "lock.shield" }
        if normalized.contains("autor") || normalized.contains("copyright") { return "c.circle" }
        if normalized.contains("despre") || normalized.contains("about") { return "info.circle" }
        return "doc.text"
    }

    private func loadFooterMenu() async {
        isFooterLoading = true
        defer { isFooterLoading = false }
        footerMenu = try? await app.container.catalogRepository.footerMenu(locale: app.locale)
    }

    private func loadDashboard() async {
        isDashboardLoading = true
        defer { isDashboardLoading = false }

        await app.refreshAccount()
        continueItems = (try? await app.container.playbackRepository.continueWatching(
            locale: app.locale,
            profileID: app.activeProfile?.id
        )) ?? []

        var resolvedFavorites: [Content] = []
        for slug in app.favorites {
            if let content = try? await app.container.catalogRepository.content(slug: slug, locale: app.locale),
               app.allows(content) {
                resolvedFavorites.append(content)
            }
        }
        favoriteContents = resolvedFavorites.sorted { $0.title.localizedCaseInsensitiveCompare($1.title) == .orderedAscending }
    }

    private func openContent(_ slug: String) async {
        do {
            selectedContent = try await app.container.catalogRepository.content(slug: slug, locale: app.locale)
        } catch {
            app.globalError = error.localizedDescription
        }
    }

    private func librarySubtitle(_ item: LibraryItem) -> String {
        let quality = item.quality?.trimmingCharacters(in: .whitespacesAndNewlines)
        let access: String
        if item.accessType.lowercased() == "lifetime" {
            access = app.tr("Acces permanent")
        } else if let expiresAt = item.expiresAt, !expiresAt.isEmpty {
            access = item.isActive ? app.tr("Disponibil până la {0}", expiresAt) : app.tr("Acces expirat")
        } else {
            access = item.isActive ? app.tr("Acces activ") : app.tr("Acces expirat")
        }
        return [quality, access].compactMap { value in
            guard let value, !value.isEmpty else { return nil }
            return value
        }.joined(separator: " • ")
    }

    private func accountSectionTitle(_ title: String, icon: String) -> some View {
        Label(title, systemImage: icon)
            .font(.headline.bold())
            .foregroundStyle(.white)
    }

    private func emptyAccountState(_ title: String, icon: String) -> some View {
        VStack(spacing: 10) {
            Image(systemName: icon).font(.largeTitle).foregroundStyle(FilmotecaTheme.muted)
            Text(title).font(.subheadline).foregroundStyle(FilmotecaTheme.muted).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(30)
        .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18))
    }

    private func contentAccountRow(
        title: String,
        subtitle: String,
        imageURL: String?,
        badge: String?,
        progress: Double?,
        action: @escaping () -> Void
    ) -> some View {
        Button(action: action) {
            HStack(spacing: 13) {
                RemoteImage(url: URL(string: imageURL ?? ""))
                    .frame(width: 70, height: 98)
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                VStack(alignment: .leading, spacing: 7) {
                    HStack {
                        Text(title).font(.subheadline.bold()).lineLimit(2)
                        Spacer()
                        if let badge {
                            Text(badge)
                                .font(.system(size: 9, weight: .black))
                                .foregroundStyle(badge == app.tr("ACTIV") ? .green : FilmotecaTheme.muted)
                        }
                    }
                    Text(subtitle).font(.caption).foregroundStyle(FilmotecaTheme.muted).lineLimit(2)
                    if let progress {
                        ProgressView(value: max(0, min(progress, 1))).tint(FilmotecaTheme.accent)
                    }
                }
                Image(systemName: "chevron.right").font(.caption).foregroundStyle(.tertiary)
            }
            .padding(12)
            .foregroundStyle(.white)
            .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 16))
        }
        .buttonStyle(.plain)
    }

    private func summaryCard(_ value: String, label: String) -> some View {
        VStack(spacing: 5) {
            Text(value).font(.title2.bold()).monospacedDigit()
            Text(label).font(.caption2).foregroundStyle(FilmotecaTheme.muted)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 18)
        .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 16))
    }

    private func accountRow(_ title: String, icon: String, action: @escaping () -> Void) -> some View { Button(action: action) { accountRowLabel(title, icon: icon, value: nil) }.buttonStyle(.plain) }
    private func accountRowLabel(_ title: String, icon: String, value: String?) -> some View { HStack(spacing: 13) { Image(systemName: icon).frame(width: 24).foregroundStyle(FilmotecaTheme.accent); Text(title); Spacer(); if let value { Text(value).foregroundStyle(FilmotecaTheme.muted) }; Image(systemName: "chevron.right").font(.caption).foregroundStyle(.tertiary) }.padding(16).contentShape(Rectangle()).foregroundStyle(.white) }
}

private struct AccountSettingsSheet: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var email = ""
    @State private var locale: LocaleCode = .ro
    @State private var isSaving = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section(app.tr("Date personale")) {
                    TextField(app.tr("Nume"), text: $name).textContentType(.name)
                    TextField(app.tr("Email"), text: $email)
                        .textContentType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .disabled(true)
                    Picker(app.tr("Limba preferată"), selection: $locale) {
                        ForEach(LocaleCode.allCases) { option in Text(option.title).tag(option) }
                    }
                }
                if let error { Section { Text(error).font(.footnote).foregroundStyle(.red) } }
            }
            .scrollContentBackground(.hidden)
            .background(FilmotecaTheme.background)
            .navigationTitle(app.tr("Datele contului"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button(app.tr("Anulează")) { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button(isSaving ? app.tr("Se salvează…") : app.tr("Salvează")) { Task { await save() } }
                        .disabled(isSaving || name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
            .onAppear {
                name = app.user?.name ?? ""
                email = app.user?.email ?? ""
                locale = app.user?.preferredLocale ?? app.locale
            }
        }
    }

    private func save() async {
        isSaving = true
        defer { isSaving = false }
        do {
            let user = try await app.container.sessionRepository.updateAccount(
                name: name.trimmingCharacters(in: .whitespacesAndNewlines),
                email: email,
                locale: locale
            )
            app.locale = locale
            app.applyUser(user)
            await app.refreshAccount()
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}

/// Permanent account deletion, required by App Store Review Guideline 5.1.1(v).
///
/// Deletion is irreversible, so the flow asks for three separate signals before
/// enabling the button: the current password, a typed confirmation word, and an
/// explicit alert. The consequences that cost the user money — the wallet
/// balance and the purchased titles — are stated before anything else.
private struct DeleteAccountSheet: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss

    private var confirmationWord: String {
        switch app.locale {
        case .ro: "ȘTERGE"
        case .ru: "УДАЛИТЬ"
        case .en: "DELETE"
        }
    }

    @State private var password = ""
    @State private var reason = ""
    @State private var typedConfirmation = ""
    @State private var isDeleting = false
    @State private var confirmAlertPresented = false
    @State private var error: String?

    private var ownedTitles: Int { app.account?.library.count ?? 0 }

    private var canSubmit: Bool {
        !isDeleting
            && !password.isEmpty
            && typedConfirmation.trimmingCharacters(in: .whitespacesAndNewlines).uppercased() == confirmationWord
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(alignment: .leading, spacing: 10) {
                        Label(app.tr("Această acțiune este definitivă"), systemImage: "exclamationmark.triangle.fill")
                            .font(.headline)
                            .foregroundStyle(.red)
                        Text(app.tr("Contul nu poate fi recuperat după ștergere."))
                            .font(.footnote)
                            .foregroundStyle(FilmotecaTheme.muted)
                    }
                    .padding(.vertical, 4)
                }

                Section(app.tr("Ce pierzi")) {
                    consequenceRow(
                        icon: "wallet.pass",
                        title: app.tr("Soldul din portofel"),
                        detail: app.tr("{0} se pierd și nu se restituie.", "\(app.balance.formatted(.number.precision(.fractionLength(2)))) \(app.currency)")
                    )
                    consequenceRow(
                        icon: "film.stack",
                        title: ownedTitles == 1 ? app.tr("1 titlu cumpărat") : app.tr("{0} titluri cumpărate", "\(ownedTitles)"),
                        detail: app.tr("Accesul la filmele cumpărate se pierde definitiv.")
                    )
                    consequenceRow(
                        icon: "person.2",
                        title: app.tr("Profiluri, favorite și istoric"),
                        detail: app.tr("Toate profilurile, lista de favorite, progresul de vizionare și recenziile se șterg.")
                    )
                }

                Section(app.tr("Confirmare")) {
                    SecureField(app.tr("Parola actuală"), text: $password)
                        .textContentType(.password)
                    TextField(app.tr("Scrie {0}", confirmationWord), text: $typedConfirmation)
                        .textInputAutocapitalization(.characters)
                        .autocorrectionDisabled()
                }

                Section(app.tr("Motiv (opțional)")) {
                    TextField(app.tr("Ne ajută să ne îmbunătățim"), text: $reason, axis: .vertical)
                        .lineLimit(2...4)
                }

                if let error {
                    Section { Text(error).font(.footnote).foregroundStyle(.red) }
                }

                Section {
                    Button(role: .destructive) {
                        confirmAlertPresented = true
                    } label: {
                        HStack {
                            Spacer()
                            if isDeleting { ProgressView().padding(.trailing, 6) }
                            Text(isDeleting ? app.tr("Se șterge…") : app.tr("Șterge contul definitiv")).fontWeight(.bold)
                            Spacer()
                        }
                    }
                    .disabled(!canSubmit)
                }
            }
            .scrollContentBackground(.hidden)
            .background(FilmotecaTheme.background)
            .navigationTitle(app.tr("Șterge contul"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(app.tr("Anulează")) { dismiss() }.disabled(isDeleting)
                }
            }
            .alert(app.tr("Ștergi contul definitiv?"), isPresented: $confirmAlertPresented) {
                Button(app.tr("Anulează"), role: .cancel) {}
                Button(app.tr("Șterge"), role: .destructive) { Task { await performDeletion() } }
            } message: {
                Text(app.tr("Contul, soldul și accesul la titlurile cumpărate se pierd definitiv. Acțiunea nu poate fi anulată."))
            }
        }
        .interactiveDismissDisabled(isDeleting)
    }

    private func consequenceRow(icon: String, title: String, detail: String) -> some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: icon)
                .frame(width: 24)
                .foregroundStyle(.red)
            VStack(alignment: .leading, spacing: 3) {
                Text(title).font(.subheadline.weight(.semibold))
                Text(detail).font(.caption).foregroundStyle(FilmotecaTheme.muted)
            }
        }
        .padding(.vertical, 2)
    }

    private func performDeletion() async {
        isDeleting = true
        error = nil
        defer { isDeleting = false }

        do {
            _ = try await app.container.sessionRepository.deleteAccount(
                currentPassword: password,
                reason: reason.trimmingCharacters(in: .whitespacesAndNewlines).nilIfBlank
            )
            app.clearSession()
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct PasswordSettingsSheet: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var currentPassword = ""
    @State private var newPassword = ""
    @State private var confirmation = ""
    @State private var isSaving = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section(app.tr("Schimbă parola")) {
                    SecureField(app.tr("Parola actuală"), text: $currentPassword)
                    SecureField(app.tr("Parola nouă"), text: $newPassword)
                    SecureField(app.tr("Confirmă parola nouă"), text: $confirmation)
                    Text(app.t("password_min_length"))
                        .font(.caption)
                        .foregroundStyle(FilmotecaTheme.muted)
                }
                if let error { Section { Text(error).font(.footnote).foregroundStyle(.red) } }
            }
            .scrollContentBackground(.hidden)
            .background(FilmotecaTheme.background)
            .navigationTitle(app.tr("Securitate"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button(app.tr("Anulează")) { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button(isSaving ? app.tr("Se salvează…") : app.tr("Actualizează")) { Task { await save() } }
                        .disabled(isSaving || currentPassword.isEmpty || !PasswordPolicy.isValid(newPassword) || newPassword != confirmation)
                }
            }
        }
    }

    private func save() async {
        guard newPassword == confirmation else {
            error = app.tr("Parolele noi nu coincid.")
            return
        }
        isSaving = true
        defer { isSaving = false }
        do {
            try await app.container.sessionRepository.updatePassword(currentPassword: currentPassword, password: newPassword)
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}


private extension String {
    var nilIfBlank: String? {
        let trimmed = trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? nil : trimmed
    }
}

struct TVPairingView: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var viewModel: TVPairingViewModel

    init(container: AppContainer) {
        _viewModel = State(initialValue: TVPairingViewModel(container: container))
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 24) {
                Image(systemName: "tv.and.mediabox").font(.system(size: 54)).foregroundStyle(FilmotecaTheme.accent)
                Text(app.tr("Conectează televizorul")).filmotecaTitle(.title)
                Text(app.tr("Introdu codul afișat în aplicația FILMOTECA de pe TV.")).foregroundStyle(FilmotecaTheme.muted).multilineTextAlignment(.center)
                TextField("ABCD-EFGH", text: Binding(get: { viewModel.code }, set: { viewModel.code = $0 })).textInputAutocapitalization(.characters).autocorrectionDisabled().multilineTextAlignment(.center).font(.system(size: 25, weight: .bold, design: .monospaced)).tracking(3).padding().background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 14)).onChange(of: viewModel.code) { _, _ in viewModel.sanitizeCode() }
                if let device = viewModel.device { VStack(spacing: 5) { Text(device.deviceName ?? app.tr("Televizor FILMOTECA")).font(.headline); Text(app.tr("Solicită acces la contul tău")).font(.caption).foregroundStyle(FilmotecaTheme.muted) }.padding() }
                if let error = viewModel.state.errorMessage { Text(error).foregroundStyle(.red).font(.footnote) }
                if let message = viewModel.successMessage { Label(message, systemImage: "checkmark.circle.fill").foregroundStyle(.green) }
                if viewModel.device == nil { Button(app.tr("Verifică codul")) { Task { await viewModel.lookup() } }.buttonStyle(GlassButtonStyle(prominent: true)).disabled(viewModel.code.count < 4) }
                else { HStack { Button(app.tr("Refuză")) { Task { _ = await viewModel.authorize(approve: false) } }.buttonStyle(GlassButtonStyle()); Button(app.tr("Conectează")) { Task { if await viewModel.authorize(approve: true) { try? await Task.sleep(for: .seconds(1)); dismiss() } } }.buttonStyle(GlassButtonStyle(prominent: true)) } }
                Spacer()
            }.padding(24).background(FilmotecaTheme.background)
            .toolbar { ToolbarItem(placement: .topBarTrailing) { Button(app.tr("Închide")) { dismiss() } } }
        }.presentationDetents([.large])
    }

}
