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
    var rank: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ZStack(alignment: .bottomLeading) {
                RemoteImage(url: content.poster)
                    .frame(width: width, height: width * 1.48)
                    .clipShape(RoundedRectangle(cornerRadius: 13, style: .continuous))
                    .overlay(alignment: .topLeading) {
                        HStack(spacing: 5) {
                            if content.isNew { badge(app.tr("NOU"), color: FilmotecaTheme.accent) }
                            if content.isFree == true { badge(app.tr("GRATUIT"), color: .white.opacity(0.2)) }
                        }.padding(8)
                    }
                    .overlay(RoundedRectangle(cornerRadius: 13).stroke(.white.opacity(0.1)))
                    .shadow(color: .black.opacity(0.4), radius: 14, y: 8)
                if let rank {
                    Text("\(rank)")
                        .font(.system(size: 58, weight: .black, design: .rounded))
                        .foregroundStyle(.white)
                        .shadow(color: .black, radius: 4)
                        .offset(x: -9, y: 12)
                }
            }
            Text(content.title)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.white)
                .lineLimit(1)
            HStack(spacing: 5) {
                if let year = content.releaseYear { Text(String(year)) }
                if let rating = content.imdbRating, rating > 0 { Text("★ \(rating, specifier: "%.1f")").foregroundStyle(FilmotecaTheme.gold) }
            }
            .font(.caption2.weight(.medium)).foregroundStyle(FilmotecaTheme.muted)
        }
        .frame(width: width, alignment: .leading)
        .contentShape(Rectangle())
    }

    private func badge(_ title: String, color: Color) -> some View {
        Text(title).font(.system(size: 8, weight: .black)).tracking(0.5).padding(.horizontal, 7).padding(.vertical, 4).background(color, in: Capsule())
    }
}

struct MediaRow: View {
    let title: String
    var subtitle: String?
    let items: [Content]
    var ranked = false

    var body: some View {
        if !items.isEmpty {
            VStack(alignment: .leading, spacing: 14) {
                VStack(alignment: .leading, spacing: 3) {
                    Text(title).filmotecaTitle(.title3).foregroundStyle(.white)
                    if let subtitle, !subtitle.isEmpty { Text(subtitle).font(.caption).foregroundStyle(FilmotecaTheme.muted) }
                }.padding(.horizontal, 18)
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(spacing: 13) {
                        ForEach(Array(items.enumerated()), id: \.element.id) { index, item in
                            NavigationLink(value: item) { PosterCard(content: item, rank: ranked ? index + 1 : nil) }.buttonStyle(.plain)
                        }
                    }.padding(.horizontal, 18).padding(.bottom, 7)
                }
            }
        }
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
