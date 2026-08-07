import SwiftUI

struct HomeView: View {
    @Environment(FilmotecaModel.self) private var app
    @State private var viewModel: HomeViewModel

    init(container: AppContainer) {
        _viewModel = State(initialValue: HomeViewModel(container: container))
    }

    var body: some View {
        Group {
            if let response = viewModel.response {
                ScrollView {
                    LazyVStack(spacing: 30) {
                        hero(response).filmotecaReveal()
                        if !viewModel.continueItems.isEmpty { continueRow.filmotecaReveal(delay: 0.08) }
                        ForEach(Array(visibleSections(response).enumerated()), id: \.element.id) { index, section in
                            MediaRow(title: section.title, subtitle: section.subtitle, items: section.items.filter(app.allows))
                                .filmotecaReveal(delay: min(0.10 + Double(index) * 0.05, 0.30))
                        }
                        if visibleSections(response).isEmpty {
                            MediaRow(title: app.t("new"), items: response.latest.filter(app.allows)).filmotecaReveal(delay: 0.10)
                            MediaRow(title: app.t("free"), items: response.freeToWatch.filter(app.allows)).filmotecaReveal(delay: 0.15)
                            MediaRow(title: app.t("movies"), items: response.movies.filter(app.allows)).filmotecaReveal(delay: 0.20)
                            MediaRow(title: app.t("series"), items: response.series.filter(app.allows)).filmotecaReveal(delay: 0.25)
                        }
                    }.padding(.bottom, 35)
                }
                .ignoresSafeArea(edges: .top)
                .refreshable { await viewModel.load(locale: app.locale, authenticated: app.isAuthenticated, profileID: app.activeProfile?.id) }
                .background(FilmotecaTheme.background)
            } else if let error = viewModel.state.errorMessage { ErrorState(message: error) { Task { await viewModel.load(locale: app.locale, authenticated: app.isAuthenticated, profileID: app.activeProfile?.id) } } }
            else { LoadingScreen() }
        }
        .task(id: "\(app.locale.rawValue)-\(app.activeProfile?.id ?? "")-\(app.refreshID)") {
            await viewModel.load(locale: app.locale, authenticated: app.isAuthenticated, profileID: app.activeProfile?.id)
        }
        .toolbar(.hidden, for: .navigationBar)
    }

    @ViewBuilder private func hero(_ home: HomeResponse) -> some View {
        let slides = (home.heroSlides ?? []).filter { app.allows($0.content) }
        let slide = slides.indices.contains(viewModel.heroIndex) ? slides[viewModel.heroIndex] : nil
        let content = slide?.content ?? home.hero.flatMap { app.allows($0) ? $0 : nil } ?? home.featured.first(where: app.allows)
        if let content {
            ZStack(alignment: .bottomLeading) {
                TabView(selection: Binding(get: { viewModel.heroIndex }, set: { viewModel.heroIndex = $0 })) {
                    if slides.isEmpty { heroImage(content.heroURL).tag(0) }
                    else { ForEach(Array(slides.enumerated()), id: \.element.id) { index, value in heroImage(URL(string: value.mobileImageURL ?? value.desktopImageURL)).tag(index) } }
                }.tabViewStyle(.page(indexDisplayMode: .never)).frame(height: 650)

                LinearGradient(colors: [.clear, FilmotecaTheme.background.opacity(0.12), FilmotecaTheme.background], startPoint: .top, endPoint: .bottom).allowsHitTesting(false)
                LinearGradient(colors: [FilmotecaTheme.background.opacity(0.72), .clear], startPoint: .leading, endPoint: .trailing).allowsHitTesting(false)

                VStack(alignment: .leading, spacing: 14) {
                    HStack { FilmotecaWordmark(); Spacer(); profileButton }
                    Spacer()
                    if let eyebrow = slide?.eyebrow, !eyebrow.isEmpty { Text(eyebrow.uppercased()).font(.caption.weight(.black)).tracking(2).foregroundStyle(FilmotecaTheme.accent) }
                    Text(slide?.title ?? content.title).font(.system(size: 39, weight: .black, design: .serif)).lineLimit(2).shadow(radius: 8)
                    Text(content.metadata).font(.caption.weight(.semibold)).foregroundStyle(.white.opacity(0.82))
                    Text(slide?.description ?? content.shortDescription ?? "").font(.subheadline).foregroundStyle(.white.opacity(0.8)).lineLimit(3)
                    HStack(spacing: 10) {
                        NavigationLink(value: content) { Label(app.t("watch"), systemImage: "play.fill") }.buttonStyle(GlassButtonStyle(prominent: true))
                        NavigationLink(value: content) { Label(app.t("details"), systemImage: "info.circle") }.buttonStyle(GlassButtonStyle())
                    }
                    if slides.count > 1 { HStack(spacing: 5) { ForEach(slides.indices, id: \.self) { i in Capsule().fill(i == viewModel.heroIndex ? FilmotecaTheme.accent : .white.opacity(0.35)).frame(width: i == viewModel.heroIndex ? 22 : 6, height: 5) } }.animation(.spring, value: viewModel.heroIndex) }
                }.padding(.horizontal, 18).padding(.top, 58).padding(.bottom, 24)
            }
        }
    }

    private func heroImage(_ url: URL?) -> some View { RemoteImage(url: url).frame(maxWidth: .infinity).frame(height: 650) }

    private var profileButton: some View {
        Button { app.isAuthenticated ? (app.profilePickerPresented = true) : (app.authPresented = true) } label: {
            ZStack { Circle().fill(LinearGradient(colors: [FilmotecaTheme.accent, .purple], startPoint: .topLeading, endPoint: .bottomTrailing)); Text(app.activeProfile?.avatarLabel ?? app.user?.name.prefix(1).uppercased() ?? "?").font(.headline.weight(.black)) }.frame(width: 38, height: 38).overlay(Circle().stroke(.white.opacity(0.4)))
        }
    }

    private var continueRow: some View {
        VStack(alignment: .leading, spacing: 13) {
            Text(app.t("continue")).filmotecaTitle(.title3).padding(.horizontal, 18)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 13) { ForEach(viewModel.continueItems) { item in
                    NavigationLink(value: Content(numericID: nil, slug: item.contentSlug, type: "movie", typeLabel: nil, title: item.title ?? item.contentSlug, originalTitle: nil, shortDescription: nil, tagline: nil, description: nil, releaseYear: nil, countryName: nil, countryNames: nil, imdbRating: nil, platformRating: nil, runtimeMinutes: nil, ageRating: nil, audioLocales: nil, subtitleLocales: nil, genres: [], badges: nil, isFeatured: nil, isTrending: nil, isFree: nil, posterURL: item.posterURL ?? "", backdropURL: item.posterURL ?? "", heroDesktopURL: nil, heroMobileURL: nil, trailerURL: nil, previewImages: nil, premiereEvent: nil, lowestPrice: nil, currency: nil, cast: nil, crew: nil, videos: nil, seasons: nil, offers: nil)) {
                        VStack(alignment: .leading, spacing: 7) {
                            RemoteImage(url: URL(string: item.posterURL ?? "")).frame(width: 210, height: 118).clipShape(RoundedRectangle(cornerRadius: 12))
                            GeometryReader { geo in ZStack(alignment: .leading) { Capsule().fill(.white.opacity(0.18)); Capsule().fill(FilmotecaTheme.accent).frame(width: geo.size.width * min(item.progressPercent / 100, 1)) } }.frame(height: 3)
                            Text(item.title ?? item.contentSlug).font(.caption.weight(.semibold)).lineLimit(1)
                        }.frame(width: 210)
                    }.buttonStyle(.plain)
                }}.padding(.horizontal, 18)
            }
        }
    }

    private func visibleSections(_ home: HomeResponse) -> [HomeSection] {
        (home.sections ?? []).filter { section in section.items.contains(where: app.allows) }
    }

}
