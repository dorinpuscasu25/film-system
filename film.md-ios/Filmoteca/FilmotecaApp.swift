import SwiftUI

@main
struct FilmotecaApp: App {
    @State private var app: FilmotecaModel

    init() {
        let container = AppContainer.live()
        _app = State(initialValue: FilmotecaModel(container: container))
        let appearance = UITabBarAppearance()
        appearance.configureWithOpaqueBackground()
        appearance.backgroundColor = UIColor(FilmotecaTheme.background.opacity(0.96))
        appearance.shadowColor = UIColor.white.withAlphaComponent(0.08)
        UITabBar.appearance().standardAppearance = appearance
        UITabBar.appearance().scrollEdgeAppearance = appearance
        UINavigationBar.appearance().largeTitleTextAttributes = [.foregroundColor: UIColor.white]
        UINavigationBar.appearance().titleTextAttributes = [.foregroundColor: UIColor.white]
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(app)
                .preferredColorScheme(.dark)
                .tint(FilmotecaTheme.accent)
        }
    }
}
