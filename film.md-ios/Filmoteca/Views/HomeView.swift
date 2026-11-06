import SwiftUI

struct HomeView: View {
    @Environment(FilmotecaModel.self) private var app
    @State private var viewModel: HomeViewModel

    init(container: AppContainer) {
        _viewModel = State(initialValue: HomeViewModel(container: container))
    }

    /// Dominant colour of the hero artwork on screen; the whole page is lit with it.
    @State private var ambient: Color?

    private struct Shelf: Identifiable { let id: String; let title: String; let subtitle: String?; let items: [Content] }

    var body: some View {
        Group {
            if let response = viewModel.response {
                ScrollView {
                    LazyVStack(spacing: 34) {
                        hero(response).filmotecaReveal()
                        if !viewModel.continueItems.isEmpty { continueRow.filmotecaReveal(delay: 0.08) }
                        shelves(response)
                    }.padding(.bottom, 40)
                }
                .ignoresSafeArea(edges: .top)
                .refreshable { await viewModel.load(locale: app.locale, authenticated: app.isAuthenticated, profileID: app.activeProfile?.id) }
                .background { AmbientBackdrop(color: ambient) }
                .task(id: heroArtworkURL(response)) {
                    let color = await ArtworkColorSampler.shared.color(for: heroArtworkURL(response))
                    withAnimation(.easeInOut(duration: 0.9)) { ambient = color }
                }
            } else if let error = viewModel.state.errorMessage { ErrorState(message: error) { Task { await viewModel.load(locale: app.locale, authenticated: app.isAuthenticated, profileID: app.activeProfile?.id) } } }
            else { LoadingScreen() }
        }
        .task(id: "\(app.locale.rawValue)-\(app.activeProfile?.id ?? "")-\(app.refreshID)") {
            let prefetched = await app.takePrefetchedHome(locale: app.locale)
            await viewModel.load(locale: app.locale, authenticated: app.isAuthenticated, profileID: app.activeProfile?.id, prefetched: prefetched)
            app.markInitialRouteReady()
        }
        .toolbar(.hidden, for: .navigationBar)
    }

    /// Poster shelves with the Top 10, genre tiles and spotlight cards woven in, so the
    /// page doesn't read as one endless run of identical rows.
    @ViewBuilder private func shelves(_ response: HomeResponse) -> some View {
        let shelves = shelfList(response)
        let spotlights = spotlightPicks(response)
        let topTen = viewModel.topTen(allowing: app.allows)

        ForEach(Array(shelves.enumerated()), id: \.element.id) { index, shelf in
            if index == 1, !app.isKidsProfile { TopTenRow(title: app.t("top10"), items: topTen).filmotecaReveal(delay: 0.1) }
            if index == 2 { GenreChipsRow(title: app.t("genres_header"), genres: viewModel.genres).filmotecaReveal(delay: 0.1) }
            if index == 3, let first = spotlights.first { SpotlightCard(content: first).filmotecaReveal(delay: 0.1) }
            if index == 6, spotlights.count > 1 { SpotlightCard(content: spotlights[1]).filmotecaReveal(delay: 0.1) }
            MediaRow(title: shelf.title, subtitle: shelf.subtitle, items: shelf.items, showsSeeAll: true)
                .filmotecaReveal(delay: min(0.10 + Double(index) * 0.05, 0.30))
        }
        if shelves.count < 3 { GenreChipsRow(title: app.t("genres_header"), genres: viewModel.genres) }
    }

    private func shelfList(_ response: HomeResponse) -> [Shelf] {
        let sections = visibleSections(response).map { Shelf(id: $0.id, title: $0.title, subtitle: $0.subtitle, items: $0.items.filter(app.allows)) }
        if !sections.isEmpty { return sections }
        return [
            Shelf(id: "new", title: app.t("new"), subtitle: nil, items: response.latest.filter(app.allows)),
            Shelf(id: "free", title: app.t("free"), subtitle: nil, items: response.freeToWatch.filter(app.allows)),
            Shelf(id: "movies", title: app.t("movies"), subtitle: nil, items: response.movies.filter(app.allows)),
            Shelf(id: "series", title: app.t("series"), subtitle: nil, items: response.series.filter(app.allows)),
        ].filter { !$0.items.isEmpty }
    }

    /// Featured titles that aren't already the hero and have wide artwork.
    private func spotlightPicks(_ response: HomeResponse) -> [Content] {
        let heroSlugs = Set((response.heroSlides ?? []).map(\.content.slug) + [response.hero?.slug].compactMap { $0 })
        var seen = Set<String>()
        let pool = response.featured + response.latest + (response.sections ?? []).flatMap(\.items)
        let candidates = pool.filter { app.allows($0) && !heroSlugs.contains($0.slug) && !$0.backdropURL.isEmpty && seen.insert($0.slug).inserted }
        // Best-rated first, so the spotlight is worth the space.
        return candidates.sorted { ($0.imdbRating ?? 0) > ($1.imdbRating ?? 0) }.prefix(2).map { $0 }
    }

    private func heroSlides(_ home: HomeResponse) -> [HeroSlide] { (home.heroSlides ?? []).filter { app.allows($0.content) } }

    private func heroArtworkURL(_ home: HomeResponse) -> URL? {
        let slides = heroSlides(home)
        if slides.indices.contains(viewModel.heroIndex) {
            let slide = slides[viewModel.heroIndex]
            return URL(string: slide.mobileImageURL ?? slide.desktopImageURL)
        }
        return (home.hero ?? home.featured.first)?.heroURL
    }

    @ViewBuilder private func hero(_ home: HomeResponse) -> some View {
        let slides = heroSlides(home)
        let slide = slides.indices.contains(viewModel.heroIndex) ? slides[viewModel.heroIndex] : nil
        let content = slide?.content ?? home.hero.flatMap { app.allows($0) ? $0 : nil } ?? home.featured.first(where: app.allows)
        if let content {
            ZStack(alignment: .bottomLeading) {
                ZStack {
                    TabView(selection: Binding(get: { viewModel.heroIndex }, set: { viewModel.heroIndex = $0 })) {
                        if slides.isEmpty { heroImage(content.heroURL).tag(0) }
                        else { ForEach(Array(slides.enumerated()), id: \.element.id) { index, value in heroImage(URL(string: value.mobileImageURL ?? value.desktopImageURL)).tag(index) } }
                    }.tabViewStyle(.page(indexDisplayMode: .never))
                    LinearGradient(colors: [.black.opacity(0.35), .clear, .clear, .black.opacity(0.55)], startPoint: .top, endPoint: .bottom).allowsHitTesting(false)
                    LinearGradient(colors: [.black.opacity(0.55), .clear], startPoint: .leading, endPoint: .trailing).allowsHitTesting(false)
                }
                .frame(height: 650)
                .mask(LinearGradient(stops: [.init(color: .black, location: 0), .init(color: .black, location: 0.62), .init(color: .clear, location: 1)], startPoint: .top, endPoint: .bottom))
                .visualEffect { view, proxy in
                    let minY = proxy.frame(in: .scrollView).minY
                    // Pull down: the artwork stretches. Scroll up: it drifts slower than the page.
                    return view
                        .scaleEffect(minY > 0 ? 1 + minY / 550 : 1, anchor: .bottom)
                        .offset(y: minY > 0 ? 0 : -minY * 0.45)
                }

                VStack(alignment: .leading, spacing: 14) {
                    HStack { FilmotecaWordmark(); Spacer(); profileButton }
                    Spacer()
                    if let eyebrow = slide?.eyebrow, !eyebrow.isEmpty {
                        Text(eyebrow.uppercased()).font(.caption.weight(.black)).tracking(2).foregroundStyle(.white)
                            .padding(.horizontal, 10).padding(.vertical, 5)
                            .background(FilmotecaTheme.brandGradient, in: Capsule())
                    }
                    Text(slide?.title ?? content.title).font(.system(size: 39, weight: .black, design: .serif)).lineLimit(2).shadow(color: .black.opacity(0.6), radius: 10)
                        .contentTransition(.opacity)
                    HStack(spacing: 8) {
                        if let rating = content.imdbRating, rating > 0 {
                            Label { Text(rating, format: .number.precision(.fractionLength(1))) } icon: { Image(systemName: "star.fill") }
                                .font(.caption.weight(.heavy)).foregroundStyle(.black)
                                .padding(.horizontal, 8).padding(.vertical, 4)
                                .background(FilmotecaTheme.goldGradient, in: Capsule())
                        }
                        Text(content.metadata).font(.caption.weight(.semibold)).foregroundStyle(.white.opacity(0.85))
                    }
                    Text(slide?.description ?? content.shortDescription ?? "").font(.subheadline).foregroundStyle(.white.opacity(0.82)).lineLimit(3)
                    HStack(spacing: 10) {
                        NavigationLink(value: WatchRoute(content: content)) { Label(app.t("watch"), systemImage: "play.fill") }.buttonStyle(GlassButtonStyle(prominent: true))
                        NavigationLink(value: content) { Label(app.t("details"), systemImage: "info.circle") }.buttonStyle(GlassButtonStyle())
                    }
                    if slides.count > 1 {
                        HStack(spacing: 5) {
                            ForEach(slides.indices, id: \.self) { i in
                                Capsule()
                                    .fill(i == viewModel.heroIndex ? AnyShapeStyle(FilmotecaTheme.brandGradient) : AnyShapeStyle(.white.opacity(0.35)))
                                    .frame(width: i == viewModel.heroIndex ? 22 : 6, height: 5)
                            }
                        }.animation(.spring, value: viewModel.heroIndex)
                    }
                }
                .padding(.horizontal, 18).padding(.top, 58).padding(.bottom, 24)
                .animation(.easeInOut(duration: 0.3), value: viewModel.heroIndex)
            }
            .frame(height: 650)
            // Coloured light that the artwork dissolves into, instead of a hard black edge.
            // A background, so its size never widens the hero's layout.
            .background(alignment: .bottom) {
                Ellipse()
                    .fill((ambient ?? FilmotecaTheme.accent).opacity(0.5))
                    .frame(width: 560, height: 300)
                    .blur(radius: 90)
                    .offset(x: -40, y: 60)
                    .allowsHitTesting(false)
            }
            .sensoryFeedback(.selection, trigger: viewModel.heroIndex)
        }
    }

    private func heroImage(_ url: URL?) -> some View { RemoteImage(url: url).frame(maxWidth: .infinity).frame(height: 650) }

    private var profileButton: some View {
        Button { app.isAuthenticated ? (app.profilePickerPresented = true) : (app.authPresented = true) } label: {
            ZStack { Circle().fill(FilmotecaTheme.brandGradient); Text(app.activeProfile?.avatarLabel ?? app.user?.name.prefix(1).uppercased() ?? "?").font(.headline.weight(.black)).foregroundStyle(.white) }.frame(width: 38, height: 38).overlay(Circle().stroke(.white.opacity(0.4)))
        }
    }

    private var continueRow: some View {
        VStack(alignment: .leading, spacing: 13) {
            SectionHeader(title: app.t("continue"), gradient: FilmotecaTheme.brandGradient)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 13) { ForEach(viewModel.continueItems) { item in
                    NavigationLink(value: Content(numericID: nil, slug: item.contentSlug, type: "movie", typeLabel: nil, title: item.title ?? item.contentSlug, originalTitle: nil, shortDescription: nil, tagline: nil, description: nil, releaseYear: nil, countryName: nil, countryNames: nil, imdbRating: nil, platformRating: nil, runtimeMinutes: nil, ageRating: nil, audioLocales: nil, subtitleLocales: nil, genres: [], badges: nil, isFeatured: nil, isTrending: nil, isFree: nil, posterURL: item.posterURL ?? "", backdropURL: item.posterURL ?? "", heroDesktopURL: nil, heroMobileURL: nil, trailerURL: nil, previewImages: nil, premiereEvent: nil, lowestPrice: nil, currency: nil, cast: nil, crew: nil, videos: nil, seasons: nil, offers: nil)) {
                        VStack(alignment: .leading, spacing: 7) {
                            RemoteImage(url: URL(string: item.posterURL ?? "")).frame(width: 210, height: 118).clipShape(RoundedRectangle(cornerRadius: 12)).overlay { Image(systemName: "play.circle.fill").font(.system(size: 34)).symbolRenderingMode(.palette).foregroundStyle(.white, .black.opacity(0.35)) }
                            GeometryReader { geo in ZStack(alignment: .leading) { Capsule().fill(.white.opacity(0.18)); Capsule().fill(FilmotecaTheme.brandGradient).frame(width: geo.size.width * min(item.progressPercent / 100, 1)) } }.frame(height: 3)
                            Text(item.title ?? item.contentSlug).font(.caption.weight(.semibold)).lineLimit(1)
                        }.frame(width: 210)
                    }.buttonStyle(PressableCardStyle())
                }}.padding(.horizontal, 18)
            }
        }
    }

    private func visibleSections(_ home: HomeResponse) -> [HomeSection] {
        (home.sections ?? []).filter { section in section.items.contains(where: app.allows) }
    }

}
