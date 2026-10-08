import SwiftUI

@main
struct FilmotecaApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @State private var app: FilmotecaModel
    /// App-level, not per window: the intro plays once per process (cold start only).
    @State private var intro = IntroController()

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
                .environment(intro)
                .onOpenURL { _ in intro.cancelForExternalLaunch() }
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { _ in intro.cancelForExternalLaunch() }
                .preferredColorScheme(.dark)
                .tint(FilmotecaTheme.accent)
                .task { app.container.storeKitService.start() }
        }
    }
}

final class AppDelegate: NSObject, UIApplicationDelegate {
    func application(_ application: UIApplication, supportedInterfaceOrientationsFor window: UIWindow?) -> UIInterfaceOrientationMask {
        OrientationLock.supported(for: UIDevice.current.userInterfaceIdiom)
    }
}
