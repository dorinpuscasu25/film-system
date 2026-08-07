import SwiftUI

struct RootView: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var selection = 0
    @State private var isLaunching = true

    var body: some View {
        Group {
            if isLaunching { CinematicLaunchScreen().transition(.opacity) }
            else { applicationContent.transition(.opacity) }
        }
        .animation(.easeInOut(duration: reduceMotion ? 0.12 : 0.38), value: isLaunching)
        .task {
            try? await Task.sleep(for: .milliseconds(reduceMotion ? 180 : 1350))
            isLaunching = false
        }
        .sheet(isPresented: Bindable(app).authPresented) { AuthView(container: app.container).presentationDetents([.large]).presentationCornerRadius(28) }
        .fullScreenCover(isPresented: Bindable(app).profilePickerPresented) { ProfilePickerView(container: app.container) }
        .alert("FILMOTECA", isPresented: Binding(get: { app.globalError != nil }, set: { if !$0 { app.globalError = nil } })) { Button("OK") { app.globalError = nil } } message: { Text(app.globalError ?? "") }
    }

    @ViewBuilder private var applicationContent: some View {
        switch app.session {
        case .loading:
            LoadingScreen()
        case .guest, .authenticated:
            TabView(selection: $selection) {
                NavigationStack { HomeView(container: app.container).navigationDestination(for: Content.self) { ContentDetailView(seed: $0, container: app.container) } }
                    .tabItem { Label(app.t("home"), systemImage: selection == 0 ? "house.fill" : "house") }.tag(0)
                NavigationStack { SearchView(container: app.container).navigationDestination(for: Content.self) { ContentDetailView(seed: $0, container: app.container) } }
                    .tabItem { Label(app.t("search"), systemImage: "magnifyingglass") }.tag(1)
                NavigationStack { LibraryView(container: app.container).navigationDestination(for: Content.self) { ContentDetailView(seed: $0, container: app.container) } }
                    .tabItem { Label(app.t("library"), systemImage: selection == 2 ? "play.square.stack.fill" : "play.square.stack") }.tag(2)
                NavigationStack { AccountView() }
                    .tabItem { Label(app.t("account"), systemImage: selection == 3 ? "person.crop.circle.fill" : "person.crop.circle") }.tag(3)
            }
            .toolbarBackground(FilmotecaTheme.background.opacity(0.96), for: .tabBar)
            .toolbarBackground(.visible, for: .tabBar)
        }
    }
}
