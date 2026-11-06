import SwiftUI

struct RootView: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(IntroController.self) private var intro
    @State private var selection = 0

    var body: some View {
        // The app loads underneath the intro, so the intro covers loading time instead of adding to it.
        ZStack {
            applicationContent
            if intro.isVisible { IntroView(controller: intro).transition(.opacity).zIndex(1) }
        }
        .animation(.easeInOut(duration: IntroConfiguration.exitFadeDuration), value: intro.isVisible)
        .statusBarHidden(intro.isVisible)
        .persistentSystemOverlays(intro.isVisible ? .hidden : .automatic)
        .onChange(of: app.isInitialRouteReady, initial: true) { _, ready in intro.isRouteReady = ready }
        .sheet(isPresented: Bindable(app).authPresented) { AuthView(container: app.container).presentationDetents([.large]).presentationCornerRadius(28) }
        .fullScreenCover(isPresented: Bindable(app).profilePickerPresented) { ProfilePickerView(container: app.container) }
        .alert("FILMOTECA", isPresented: Binding(get: { app.globalError != nil && !intro.isVisible }, set: { if !$0 { app.globalError = nil } })) { Button("OK") { app.globalError = nil } } message: { Text(app.globalError ?? "") }
    }

    @ViewBuilder private var applicationContent: some View {
        switch app.session {
        case .loading:
            LoadingScreen()
        case .guest, .authenticated:
            TabView(selection: $selection) {
                NavigationStack { HomeView(container: app.container).filmotecaDestinations(app.container) }
                    .tabItem { Label(app.t("home"), systemImage: selection == 0 ? "house.fill" : "house") }.tag(0)
                NavigationStack { SearchView(container: app.container).filmotecaDestinations(app.container) }
                    .tabItem { Label(app.t("search"), systemImage: "magnifyingglass") }.tag(1)
                NavigationStack { LibraryView(container: app.container).filmotecaDestinations(app.container) }
                    .tabItem { Label(app.t("library"), systemImage: selection == 2 ? "play.square.stack.fill" : "play.square.stack") }.tag(2)
                NavigationStack { AccountView() }
                    .tabItem { Label(app.t("account"), systemImage: selection == 3 ? "person.crop.circle.fill" : "person.crop.circle") }.tag(3)
            }
            .tint(FilmotecaTheme.accent)
            .sensoryFeedback(.selection, trigger: selection)
            .toolbarBackground(FilmotecaTheme.background.opacity(0.96), for: .tabBar)
            .toolbarBackground(.visible, for: .tabBar)
        }
    }
}

extension View {
    /// The screens any tab's stack can push: titles, the player route and the browse grids.
    func filmotecaDestinations(_ container: AppContainer) -> some View {
        navigationDestination(for: Content.self) { ContentDetailView(seed: $0, container: container) }
            .navigationDestination(for: WatchRoute.self) { ContentDetailView(seed: $0.content, container: container, autoplay: true) }
            .navigationDestination(for: SectionRoute.self) { SectionGridView(route: $0) }
            .navigationDestination(for: GenreRoute.self) { GenreGridView(route: $0) }
    }
}
