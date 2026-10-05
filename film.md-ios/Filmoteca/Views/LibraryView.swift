import SwiftUI

struct LibraryView: View {
    @Environment(FilmotecaModel.self) private var app
    @State private var viewModel: LibraryViewModel

    init(container: AppContainer) {
        _viewModel = State(initialValue: LibraryViewModel(container: container))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 28) {
                if !app.isAuthenticated {
                    EmptyLibraryCard(icon: "person.crop.circle.badge.plus", title: app.tr("Biblioteca ta te așteaptă"), subtitle: app.tr("Autentifică-te pentru favorite, achiziții și progres sincronizat."))
                    Button(app.t("login")) { app.authPresented = true }.buttonStyle(GlassButtonStyle(prominent: true)).frame(maxWidth: .infinity)
                } else if viewModel.state.isLoading && app.account == nil { ProgressView().frame(maxWidth: .infinity).padding(50) }
                else {
                    HStack {
                        Label(app.tr("Lista mea"), systemImage: "bookmark.fill")
                            .filmotecaTitle(.title3)
                        Spacer()
                        Text("\(viewModel.favoriteContent.count)")
                            .font(.caption.bold())
                            .foregroundStyle(FilmotecaTheme.muted)
                    }
                    .padding(.top, 4)

                    if !viewModel.favoriteContent.isEmpty {
                        MediaRow(title: app.tr("Titluri salvate"), items: viewModel.favoriteContent)
                    } else {
                        EmptyLibraryCard(icon: "bookmark", title: app.tr("Lista mea este goală"), subtitle: app.tr("Apasă „Lista mea” pe pagina unui film ca să-l găsești aici."))
                    }

                    if !viewModel.continueItems.isEmpty {
                        sectionTitle(app.t("continue"))
                        ForEach(viewModel.continueItems) { item in ContinueLibraryRow(item: item) }
                    }
                    if !app.isKidsProfile, let library = app.account?.library.filter(\.isActive), !library.isEmpty {
                        sectionTitle(app.tr("Filmele mele"))
                        ForEach(library) { item in LibraryItemRow(item: item) }
                    }
                }
            }.padding(.horizontal, 16).padding(.bottom, 30)
        }
        .background(FilmotecaTheme.background)
        .navigationTitle(app.t("library"))
        .refreshable { await viewModel.load(app: app) }
        .task(id: "\(app.isAuthenticated)-\(app.activeProfile?.id ?? "")-\(app.locale.rawValue)") { await viewModel.load(app: app) }
    }

    private func sectionTitle(_ title: String) -> some View { Text(title).filmotecaTitle(.title3).padding(.top, 4) }

}

private struct LibraryItemRow: View {
    @Environment(FilmotecaModel.self) private var app
    let item: LibraryItem
    var body: some View {
        HStack(spacing: 14) {
            RemoteImage(url: URL(string: item.posterURL ?? "")).frame(width: 76, height: 106).clipShape(RoundedRectangle(cornerRadius: 10))
            VStack(alignment: .leading, spacing: 7) {
                Text(item.contentTitle).font(.headline)
                Text([item.quality, item.accessType == "lifetime" ? app.tr("Acces permanent") : app.tr("Închiriat")].compactMap { $0 }.joined(separator: " • ")).font(.caption).foregroundStyle(FilmotecaTheme.muted)
                Label(app.tr("Disponibil"), systemImage: "checkmark.circle.fill").font(.caption.weight(.semibold)).foregroundStyle(.green)
            }
            Spacer(); Image(systemName: "chevron.right").foregroundStyle(.tertiary)
        }.padding(12).background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 16))
    }
}

private struct ContinueLibraryRow: View {
    @Environment(FilmotecaModel.self) private var app
    let item: ContinueItem
    var body: some View {
        HStack(spacing: 14) {
            ZStack { RemoteImage(url: URL(string: item.posterURL ?? "")); Image(systemName: "play.fill").padding(12).background(.ultraThinMaterial, in: Circle()) }.frame(width: 120, height: 72).clipShape(RoundedRectangle(cornerRadius: 11))
            VStack(alignment: .leading, spacing: 9) { Text(item.title ?? item.contentSlug).font(.subheadline.bold()); ProgressView(value: item.progressPercent, total: 100).tint(FilmotecaTheme.accent); Text(app.tr("{0}% vizionat", "\(Int(item.progressPercent))")).font(.caption2).foregroundStyle(FilmotecaTheme.muted) }
        }
    }
}
