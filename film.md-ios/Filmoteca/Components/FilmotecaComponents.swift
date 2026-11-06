import SwiftUI
import SafariServices

struct InAppBrowser: UIViewControllerRepresentable {
    let url: URL

    func makeUIViewController(context: Context) -> SFSafariViewController {
        let controller = SFSafariViewController(url: url)
        controller.preferredControlTintColor = UIColor(FilmotecaTheme.accent)
        controller.dismissButtonStyle = .close
        return controller
    }

    func updateUIViewController(_ uiViewController: SFSafariViewController, context: Context) {}
}

struct RemoteImage: View {
    let url: URL?
    var contentMode: ContentMode = .fill

    var body: some View {
        AsyncImage(url: url, transaction: .init(animation: .easeInOut(duration: 0.35))) { phase in
            switch phase {
            case .success(let image): image.resizable().aspectRatio(contentMode: contentMode).transition(.opacity)
            case .failure: ZStack { FilmotecaTheme.elevated; Image(systemName: "film.stack").font(.largeTitle).foregroundStyle(.white.opacity(0.18)) }
            default: FilmotecaTheme.elevated.overlay { ProgressView().tint(.white.opacity(0.35)) }.shimmer()
            }
        }
        .clipped()
    }
}

struct PosterCard: View {
    @Environment(FilmotecaModel.self) private var app
    let content: Content
    var width: CGFloat = 142

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            RemoteImage(url: content.poster)
                .frame(width: width, height: width * 1.48)
                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                .overlay(alignment: .topLeading) {
                    HStack(spacing: 5) {
                        if content.isNew { PosterBadge(title: app.tr("NOU"), fill: AnyShapeStyle(FilmotecaTheme.brandGradient)) }
                        if content.isFree == true { PosterBadge(title: app.tr("GRATUIT"), fill: AnyShapeStyle(FilmotecaTheme.mint.gradient)) }
                    }.padding(8)
                }
                .overlay(alignment: .bottomTrailing) {
                    if let rating = content.imdbRating, rating > 0 {
                        HStack(spacing: 3) {
                            Image(systemName: "star.fill").font(.system(size: 8, weight: .black))
                            Text(rating, format: .number.precision(.fractionLength(1)))
                        }
                        .font(.system(size: 11, weight: .heavy, design: .rounded))
                        .foregroundStyle(.black)
                        .padding(.horizontal, 7).padding(.vertical, 4)
                        .background(FilmotecaTheme.goldGradient, in: Capsule())
                        .padding(7)
                    }
                }
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(LinearGradient(colors: [.white.opacity(0.28), .white.opacity(0.04)], startPoint: .top, endPoint: .bottom)))
                .shadow(color: .black.opacity(0.45), radius: 14, y: 8)
            Text(content.title)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.white)
                .lineLimit(1)
            Text([content.releaseYear.map(String.init), content.genres.first { !$0.contains("\\") }].compactMap { $0 }.joined(separator: " · "))
                .font(.caption2.weight(.medium)).foregroundStyle(FilmotecaTheme.muted).lineLimit(1)
        }
        .frame(width: width, alignment: .leading)
        .contentShape(Rectangle())
    }
}

struct PosterBadge: View {
    let title: String
    let fill: AnyShapeStyle
    var body: some View {
        Text(title).font(.system(size: 9, weight: .black)).tracking(0.6).foregroundStyle(.white)
            .padding(.horizontal, 7).padding(.vertical, 4)
            .background(fill, in: Capsule())
            .shadow(color: .black.opacity(0.35), radius: 4, y: 2)
    }
}

/// Title for a shelf: a short coloured bar, the name, and an optional "See all".
struct SectionHeader<Trailing: View>: View {
    let title: String
    var subtitle: String?
    var gradient: LinearGradient = FilmotecaTheme.brandGradient
    @ViewBuilder var trailing: Trailing

    var body: some View {
        HStack(alignment: .center, spacing: 10) {
            Capsule().fill(gradient).frame(width: 4, height: subtitle?.isEmpty == false ? 34 : 22)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).filmotecaTitle(.title3).foregroundStyle(.white)
                if let subtitle, !subtitle.isEmpty { Text(subtitle).font(.caption).foregroundStyle(FilmotecaTheme.muted) }
            }
            Spacer(minLength: 8)
            trailing
        }
        .padding(.horizontal, 18)
    }
}

extension SectionHeader where Trailing == EmptyView {
    init(title: String, subtitle: String? = nil, gradient: LinearGradient = FilmotecaTheme.brandGradient) {
        self.init(title: title, subtitle: subtitle, gradient: gradient) { EmptyView() }
    }
}

struct SeeAllLabel: View {
    @Environment(FilmotecaModel.self) private var app
    var body: some View {
        HStack(spacing: 3) { Text(app.t("see_all")); Image(systemName: "chevron.right").font(.caption2.weight(.bold)) }
            .font(.footnote.weight(.semibold))
            .foregroundStyle(FilmotecaTheme.accent)
    }
}

struct MediaRow: View {
    let title: String
    var subtitle: String?
    let items: [Content]
    var showsSeeAll = false
    var gradient: LinearGradient = FilmotecaTheme.brandGradient

    var body: some View {
        if !items.isEmpty {
            VStack(alignment: .leading, spacing: 14) {
                SectionHeader(title: title, subtitle: subtitle, gradient: gradient) {
                    if showsSeeAll, items.count > 3 {
                        NavigationLink(value: SectionRoute(title: title, subtitle: subtitle, items: items)) { SeeAllLabel() }
                    }
                }
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(spacing: 13) {
                        ForEach(items) { item in
                            NavigationLink(value: item) { PosterCard(content: item) }.buttonStyle(PressableCardStyle())
                        }
                    }.padding(.horizontal, 18).padding(.vertical, 7)
                }
                .scrollClipDisabled()
            }
        }
    }
}

/// Big outlined rank numbers sitting behind each poster.
struct TopTenRow: View {
    let title: String
    let items: [Content]

    var body: some View {
        if items.count >= 3 {
            VStack(alignment: .leading, spacing: 14) {
                SectionHeader(title: title) {
                    Image(systemName: "crown.fill").foregroundStyle(FilmotecaTheme.gold)
                }
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(spacing: 6) {
                        ForEach(Array(items.enumerated()), id: \.element.id) { index, item in
                            NavigationLink(value: item) {
                                HStack(alignment: .bottom, spacing: -22) {
                                    RankNumber(value: index + 1)
                                    RemoteImage(url: item.poster)
                                        .frame(width: 118, height: 175)
                                        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                                        .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(.white.opacity(0.15)))
                                        .shadow(color: .black.opacity(0.6), radius: 10, x: -6, y: 6)
                                }
                            }
                            .buttonStyle(PressableCardStyle())
                            .accessibilityLabel("\(index + 1). \(item.title)")
                        }
                    }.padding(.leading, 26).padding(.trailing, 18).padding(.vertical, 7)
                }
                .scrollClipDisabled()
            }
        }
    }
}

private struct RankNumber: View {
    let value: Int
    var body: some View {
        let text = Text("\(value)").font(.system(size: 118, weight: .black, design: .rounded)).monospacedDigit()
        ZStack {
            // A cheap outline: the number drawn in white, nudged in eight directions, under a dark fill.
            ForEach(0..<8, id: \.self) { i in
                let angle = Double(i) * .pi / 4
                text.foregroundStyle(LinearGradient(colors: [Color(red: 1, green: 0.42, blue: 0.42), FilmotecaTheme.crimson], startPoint: .top, endPoint: .bottom))
                    .offset(x: cos(angle) * 2, y: sin(angle) * 2)
            }
            text.foregroundStyle(FilmotecaTheme.background)
        }
        .tracking(-6)
        .frame(height: 120, alignment: .bottom)
        .offset(y: 14)
    }
}

/// Colourful genre tiles; each opens that genre's full catalogue.
struct GenreChipsRow: View {
    let title: String
    let genres: [FilterOption]

    var body: some View {
        if !genres.isEmpty {
            VStack(alignment: .leading, spacing: 14) {
                SectionHeader(title: title)
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHGrid(rows: [GridItem(.fixed(62), spacing: 10), GridItem(.fixed(62), spacing: 10)], spacing: 10) {
                        ForEach(Array(genres.enumerated()), id: \.element.id) { index, genre in
                            NavigationLink(value: GenreRoute(value: genre.value, label: genre.label, color: genre.color, index: index)) {
                                ZStack(alignment: .bottomLeading) {
                                    GenreStyle.gradient(hex: genre.color, index: index)
                                    // Light catching the top corner, so flat colours still feel lit.
                                    RadialGradient(colors: [.white.opacity(0.22), .clear], center: .topTrailing, startRadius: 0, endRadius: 120)
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(genre.label).font(.subheadline.weight(.heavy)).foregroundStyle(.white).lineLimit(1)
                                        Text(String(genre.count)).font(.caption2.weight(.bold)).foregroundStyle(.white.opacity(0.7))
                                    }
                                    .padding(.horizontal, 14).padding(.bottom, 10)
                                }
                                .frame(width: 168, height: 62)
                                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(.white.opacity(0.16)))
                            }
                            .buttonStyle(PressableCardStyle())
                        }
                    }.padding(.horizontal, 18)
                }
            }
        }
    }
}

/// A wide backdrop card that breaks up the run of poster shelves.
struct SpotlightCard: View {
    @Environment(FilmotecaModel.self) private var app
    let content: Content
    @State private var glow: Color?

    var body: some View {
        NavigationLink(value: content) {
            ZStack(alignment: .bottomLeading) {
                // The artwork fills a fixed frame; it must never size the card itself.
                Color.clear
                    .frame(maxWidth: .infinity)
                    .frame(height: 210)
                    .overlay { RemoteImage(url: URL(string: content.backdropURL.isEmpty ? content.posterURL : content.backdropURL)) }
                    .clipped()
                LinearGradient(colors: [.clear, (glow ?? .black).mix(with: .black, by: 0.55).opacity(0.95)], startPoint: .center, endPoint: .bottom)
                VStack(alignment: .leading, spacing: 6) {
                    Text(app.t("spotlight")).font(.caption2.weight(.black)).tracking(1.6).foregroundStyle(.white).padding(.horizontal, 8).padding(.vertical, 4).background(FilmotecaTheme.brandGradient, in: Capsule())
                    Text(content.title).font(.system(size: 26, weight: .black, design: .serif)).foregroundStyle(.white).lineLimit(2)
                    HStack(spacing: 8) {
                        Label(app.t("watch"), systemImage: "play.fill")
                            .font(.caption.weight(.bold)).foregroundStyle(.black)
                            .padding(.horizontal, 12).padding(.vertical, 7)
                            .background(.white, in: Capsule())
                        Text(content.metadata).font(.caption.weight(.semibold)).foregroundStyle(.white.opacity(0.8)).lineLimit(1)
                    }
                }
                .padding(18)
            }
            .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 22, style: .continuous).stroke((glow ?? .white).opacity(0.35), lineWidth: 1))
            .shadow(color: (glow ?? FilmotecaTheme.accent).opacity(0.35), radius: 24, y: 10)
            .padding(.horizontal, 18)
        }
        .buttonStyle(PressableCardStyle())
        .task(id: content.id) {
            let color = await ArtworkColorSampler.shared.color(for: URL(string: content.backdropURL.isEmpty ? content.posterURL : content.backdropURL))
            withAnimation(.easeInOut(duration: 0.6)) { glow = color }
        }
    }
}

struct SectionRoute: Hashable { let title: String; let subtitle: String?; let items: [Content] }
struct GenreRoute: Hashable { let value: String; let label: String; var color: String? = nil; var index: Int = 0 }

/// Every title of a home shelf, as a grid.
struct SectionGridView: View {
    let route: SectionRoute
    var body: some View {
        TitleGrid(title: route.title, subtitle: route.subtitle, items: route.items, gradient: FilmotecaTheme.brandGradient)
    }
}

/// A genre's full catalogue, loaded page by page.
struct GenreGridView: View {
    @Environment(FilmotecaModel.self) private var app
    let route: GenreRoute
    @State private var items: [Content] = []
    @State private var total = 0
    @State private var page = 0
    @State private var isLoading = false
    @State private var failed = false

    var body: some View {
        TitleGrid(
            title: route.label,
            subtitle: total > 0 ? app.t("titles_count").replacingOccurrences(of: "%@", with: String(total)) : nil,
            items: items,
            gradient: GenreStyle.gradient(hex: route.color, index: route.index),
            isLoading: isLoading && items.isEmpty,
            onReachEnd: { Task { await load() } }
        )
        .task { if page == 0 { await load() } }
        .overlay { if failed && items.isEmpty { ErrorState(message: app.t("nothing_here")) { Task { await load() } } } }
    }

    private func load() async {
        guard !isLoading, page == 0 || items.count < total else { return }
        isLoading = true
        failed = false
        defer { isLoading = false }
        do {
            let result = try await app.container.catalogRepository.catalog(locale: app.locale, search: "", type: nil, genre: route.value, year: nil, country: nil, access: nil, minRating: nil, page: page + 1)
            let fresh = result.items.filter { item in app.allows(item) && !items.contains { $0.id == item.id } }
            items.append(contentsOf: fresh)
            total = result.total
            page += 1
        } catch {
            failed = true
        }
    }
}

private struct TitleGrid: View {
    @Environment(FilmotecaModel.self) private var app
    let title: String
    var subtitle: String?
    let items: [Content]
    let gradient: LinearGradient
    var icon: String?
    var isLoading = false
    var onReachEnd: (() -> Void)?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                HStack(spacing: 14) {
                    if let icon {
                        Image(systemName: icon).font(.system(size: 24, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 54, height: 54).background(gradient, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    VStack(alignment: .leading, spacing: 4) {
                        Text(title).filmotecaTitle(.largeTitle, weight: .black).foregroundStyle(.white).lineLimit(2)
                        if let subtitle { Text(subtitle).font(.subheadline).foregroundStyle(FilmotecaTheme.muted) }
                    }
                }
                .padding(.horizontal, 18)
                .padding(.top, 8)

                if isLoading {
                    ProgressView().tint(FilmotecaTheme.accent).frame(maxWidth: .infinity).padding(60)
                } else {
                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 104), spacing: 12)], spacing: 18) {
                        ForEach(items) { item in
                            NavigationLink(value: item) { PosterCard(content: item, width: 108) }
                                .buttonStyle(PressableCardStyle())
                                .onAppear { if item.id == items.last?.id { onReachEnd?() } }
                        }
                    }
                    .padding(.horizontal, 18)
                }
            }
            .padding(.bottom, 40)
        }
        .background { AmbientBackdrop(color: nil).overlay(alignment: .top) { gradient.opacity(0.35).frame(height: 260).mask(LinearGradient(colors: [.black, .clear], startPoint: .top, endPoint: .bottom)).ignoresSafeArea() } }
        .toolbarBackground(.hidden, for: .navigationBar)
        .navigationBarTitleDisplayMode(.inline)
    }
}

struct LoadingScreen: View {
    var body: some View {
        VStack(spacing: 20) { FilmotecaWordmark(); ProgressView().tint(FilmotecaTheme.accent) }
            .frame(maxWidth: .infinity, maxHeight: .infinity).filmotecaBackground()
    }
}

struct ErrorState: View {
    let message: String
    let retry: () -> Void
    @Environment(FilmotecaModel.self) private var app

    var body: some View {
        ContentUnavailableView {
            Label(app.tr("Conținut indisponibil"), systemImage: "wifi.exclamationmark")
        } description: { Text(message) } actions: { Button(app.t("retry"), action: retry).buttonStyle(GlassButtonStyle(prominent: true)) }
            .foregroundStyle(.white).filmotecaBackground()
    }
}

struct EmptyLibraryCard: View {
    let icon: String
    let title: String
    let subtitle: String
    var body: some View {
        VStack(spacing: 14) {
            Image(systemName: icon).font(.system(size: 34)).foregroundStyle(FilmotecaTheme.accent)
            Text(title).font(.headline)
            Text(subtitle).font(.subheadline).foregroundStyle(FilmotecaTheme.muted).multilineTextAlignment(.center)
        }.frame(maxWidth: .infinity).padding(34).background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 20)).overlay(RoundedRectangle(cornerRadius: 20).stroke(FilmotecaTheme.hairline))
    }
}

extension Content {
    var metadata: String {
        [releaseYear.map(String.init), runtimeMinutes.map { "\($0) min" }, ageRating, typeLabel].compactMap { $0 }.joined(separator: "  •  ")
    }
}
