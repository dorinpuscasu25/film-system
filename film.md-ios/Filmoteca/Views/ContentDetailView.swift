import SwiftUI

struct ContentDetailView: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @State private var viewModel: ContentDetailViewModel
    @State private var reviewPendingDeletion: Review?

    init(seed: Content, container: AppContainer) {
        _viewModel = State(initialValue: ContentDetailViewModel(seed: seed, container: container))
    }

    var body: some View {
        Group {
            if let movie = viewModel.content {
                ScrollView(.vertical) {
                    VStack(spacing: 0) {
                        hero(movie)
                        VStack(alignment: .leading, spacing: 25) {
                            primaryActions(movie)
                            if let tagline = movie.tagline, !tagline.isEmpty { Text(tagline).filmotecaTitle(.title3, weight: .semibold).foregroundStyle(.white.opacity(0.9)) }
                            Text(movie.description ?? movie.shortDescription ?? "").font(.body).foregroundStyle(.white.opacity(0.78)).lineSpacing(4)
                            facts(movie)
                            if let seasons = movie.seasons, !seasons.isEmpty { episodesSection(movie, seasons: seasons) }
                            peopleSection("Distribuție", people: movie.cast ?? [])
                            peopleSection("Echipa", people: movie.crew ?? [])
                            if let images = movie.previewImages, !images.isEmpty { gallery(images) }
                            reviewsSection(movie)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.horizontal, 18)
                        .padding(.bottom, 45)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }.ignoresSafeArea(edges: .top).background(FilmotecaTheme.background)
            } else if let error = viewModel.state.errorMessage { ErrorState(message: error) { Task { await viewModel.load(app: app) } } }
            else { LoadingScreen() }
        }
        .toolbarBackground(.hidden, for: .navigationBar)
        .toolbarColorScheme(.dark, for: .navigationBar)
        .task(id: "\(viewModel.seed.slug)-\(app.locale.rawValue)") { await viewModel.load(app: app) }
        .sheet(isPresented: Binding(get: { viewModel.isPurchasePresented }, set: { viewModel.isPurchasePresented = $0 })) { if let content = viewModel.content { PurchaseSheet(content: content) { offer in try await viewModel.purchase(offer: offer, app: app) } } }
        .sheet(isPresented: Binding(get: { viewModel.isReviewPresented }, set: { viewModel.isReviewPresented = $0 })) { if let content = viewModel.content { ReviewComposer(content: content) { rating, comment in try await viewModel.submitReview(rating: rating, comment: comment) } } }
        .fullScreenCover(item: Binding(get: { viewModel.playerRequest }, set: { viewModel.playerRequest = $0 })) { PlayerView(request: $0, container: app.container) }
        .alert(
            "Ștergi recenzia?",
            isPresented: Binding(
                get: { reviewPendingDeletion != nil },
                set: { if !$0 { reviewPendingDeletion = nil } }
            ),
            presenting: reviewPendingDeletion
        ) { review in
            Button("Șterge", role: .destructive) {
                Task {
                    do { try await viewModel.deleteReview(review) }
                    catch { app.globalError = error.localizedDescription }
                    reviewPendingDeletion = nil
                }
            }
            Button("Anulează", role: .cancel) { reviewPendingDeletion = nil }
        } message: { _ in
            Text("Recenzia va fi eliminată definitiv.")
        }
    }

    private func hero(_ movie: Content) -> some View {
        GeometryReader { geometry in
            ZStack(alignment: .bottomLeading) {
                RemoteImage(url: URL(string: movie.heroMobileURL ?? movie.heroDesktopURL ?? movie.backdropURL))
                    .frame(width: geometry.size.width, height: 560)
                    .clipped()
                LinearGradient(colors: [.clear, FilmotecaTheme.background.opacity(0.2), FilmotecaTheme.background], startPoint: .top, endPoint: .bottom)
                VStack(alignment: .leading, spacing: 11) {
                    if movie.isTrending == true { Text("ÎN TREND").font(.caption2.weight(.black)).tracking(2).foregroundStyle(FilmotecaTheme.accent) }
                    Text(movie.title).font(.system(size: 39, weight: .black, design: .serif)).lineLimit(3).shadow(radius: 8)
                    if let original = movie.originalTitle, original != movie.title { Text(original).font(.subheadline).italic().foregroundStyle(FilmotecaTheme.muted) }
                    HStack(spacing: 9) {
                        if let rating = movie.imdbRating, rating > 0 { Label("\(rating, specifier: "%.1f")", systemImage: "star.fill").foregroundStyle(FilmotecaTheme.gold) }
                        Text(movie.metadata)
                    }.font(.caption.weight(.semibold)).foregroundStyle(.white.opacity(0.8))
                    ScrollView(.horizontal, showsIndicators: false) { HStack { ForEach(movie.genres, id: \.self) { Text($0).font(.caption.weight(.semibold)).padding(.horizontal, 10).padding(.vertical, 6).background(.ultraThinMaterial, in: Capsule()) } } }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 18)
                .padding(.bottom, 24)
            }
            .frame(width: geometry.size.width, height: 560)
        }
        .frame(height: 560)
        .clipped()
    }

    private func primaryActions(_ movie: Content) -> some View {
        VStack(spacing: 13) {
            Button { Task { await viewModel.watch(app: app) } } label: { Label(watchLabel(movie), systemImage: "play.fill").frame(maxWidth: .infinity) }.buttonStyle(GlassButtonStyle(prominent: true))
            HStack(spacing: 0) {
                actionButton(app.favorites.contains(movie.slug) ? "În lista mea" : "Lista mea", icon: app.favorites.contains(movie.slug) ? "checkmark" : "plus") { Task { await app.toggleFavorite(movie.slug) } }
                actionButton("Trailer", icon: "play.circle") { viewModel.playTrailer() }
                ShareLink(item: FilmotecaTheme.webBaseURL.appending(path: "movie/\(movie.slug)"), subject: Text(movie.title)) { VStack(spacing: 7) { Image(systemName: "square.and.arrow.up").font(.title3); Text("Distribuie").font(.caption) }.frame(maxWidth: .infinity).foregroundStyle(.white) }
                actionButton("Recenzie", icon: "star.bubble") { guard app.isAuthenticated else { app.authPresented = true; return }; viewModel.isReviewPresented = true }
            }
        }
    }

    private func actionButton(_ title: String, icon: String, action: @escaping () -> Void) -> some View { Button(action: action) { VStack(spacing: 7) { Image(systemName: icon).font(.title3); Text(title).font(.caption).lineLimit(1) }.frame(maxWidth: .infinity) }.buttonStyle(.plain) }

    private func watchLabel(_ movie: Content) -> String {
        if hasAccess(movie) || movie.isFree == true { return app.t("watch") }
        return movie.price > 0 ? "Cumpără acces de la \(movie.price.formatted(.number.precision(.fractionLength(0...2)))) \(movie.currency ?? "MDL")" : app.t("watch")
    }

    private func facts(_ movie: Content) -> some View {
        VStack(alignment: .leading, spacing: 13) {
            Text("Detalii").filmotecaTitle(.title3)
            fact("Țara", movie.countryNames?.joined(separator: ", ") ?? movie.countryName)
            fact("Audio", movie.audioLocales?.map { $0.uppercased() }.joined(separator: ", "))
            fact("Subtitrări", movie.subtitleLocales?.map { $0.uppercased() }.joined(separator: ", "))
            fact("Tip", movie.typeLabel ?? movie.type.capitalized)
        }.padding(17).background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 17))
    }

    private func fact(_ label: String, _ value: String?) -> some View { Group { if let value, !value.isEmpty { HStack(alignment: .top) { Text(label).foregroundStyle(FilmotecaTheme.muted).frame(width: 90, alignment: .leading); Text(value); Spacer() }.font(.subheadline) } } }

    private func episodesSection(_ movie: Content, seasons: [Season]) -> some View {
        VStack(alignment: .leading, spacing: 15) {
            HStack { Text("Episoade").filmotecaTitle(.title3); Spacer(); Picker("Sezon", selection: Binding(get: { viewModel.selectedSeason }, set: { viewModel.selectedSeason = $0 })) { ForEach(seasons.indices, id: \.self) { Text(seasons[$0].title ?? "Sezonul \(seasons[$0].seasonNumber)").tag($0) } }.pickerStyle(.menu).tint(.white) }
            if seasons.indices.contains(viewModel.selectedSeason) { ForEach(seasons[viewModel.selectedSeason].episodes) { episode in
                Button { Task { await viewModel.watch(app: app, episodeID: episode.id) } } label: {
                    HStack(spacing: 13) {
                        ZStack { RemoteImage(url: URL(string: episode.thumbnailURL ?? episode.backdropURL ?? movie.backdropURL)); Image(systemName: "play.fill").font(.caption).padding(10).background(.black.opacity(0.55), in: Circle()) }.frame(width: 128, height: 76).clipShape(RoundedRectangle(cornerRadius: 10))
                        VStack(alignment: .leading, spacing: 5) { Text("\(episode.episodeNumber). \(episode.title)").font(.subheadline.bold()).lineLimit(2); if let minutes = episode.runtimeMinutes { Text("\(minutes) min").font(.caption).foregroundStyle(FilmotecaTheme.muted) }; Text(episode.description ?? "").font(.caption2).foregroundStyle(FilmotecaTheme.muted).lineLimit(2) }
                    }.foregroundStyle(.white)
                }.buttonStyle(.plain)
            } }
        }
    }

    @ViewBuilder private func peopleSection(_ title: String, people: [Person]) -> some View {
        if !people.isEmpty { VStack(alignment: .leading, spacing: 14) { Text(title).filmotecaTitle(.title3); ScrollView(.horizontal, showsIndicators: false) { HStack(spacing: 15) { ForEach(people) { person in VStack(spacing: 8) { RemoteImage(url: URL(string: person.avatarURL ?? "")).frame(width: 76, height: 76).clipShape(Circle()); Text(person.name).font(.caption.bold()).lineLimit(1); Text(person.role ?? person.job ?? "").font(.caption2).foregroundStyle(FilmotecaTheme.muted).lineLimit(1) }.frame(width: 92) } } } } }
    }

    private func gallery(_ images: [String]) -> some View { VStack(alignment: .leading, spacing: 14) { Text("Galerie").filmotecaTitle(.title3); ScrollView(.horizontal, showsIndicators: false) { HStack(spacing: 10) { ForEach(images, id: \.self) { RemoteImage(url: URL(string: $0)).frame(width: 230, height: 135).clipShape(RoundedRectangle(cornerRadius: 12)) } } } } }

    private func reviewsSection(_ movie: Content) -> some View {
        VStack(alignment: .leading, spacing: 15) {
            HStack { Text("Recenzii").filmotecaTitle(.title3); Spacer(); if let summary = viewModel.reviews?.summary { Label("\(summary.averageRating, specifier: "%.1f")", systemImage: "star.fill").foregroundStyle(FilmotecaTheme.gold); Text("(\(summary.count))").foregroundStyle(FilmotecaTheme.muted) } }
            if viewModel.reviews?.items.isEmpty != false {
                Text("Fii primul care scrie o recenzie.").font(.subheadline).foregroundStyle(FilmotecaTheme.muted)
            }
            ForEach(viewModel.reviews?.items ?? []) { review in
                VStack(alignment: .leading, spacing: 8) {
                    HStack {
                        Text(review.userAvatar).frame(width: 32, height: 32).background(FilmotecaTheme.elevated, in: Circle())
                        Text(review.userName).font(.subheadline.bold())
                        Spacer()
                        Text(String(repeating: "★", count: review.rating)).font(.caption).foregroundStyle(FilmotecaTheme.gold)
                        if review.userID?.stringValue == app.user?.id {
                            Button(role: .destructive) { reviewPendingDeletion = review } label: {
                                Image(systemName: "trash")
                                    .font(.subheadline)
                                    .foregroundStyle(.red)
                                    .padding(7)
                                    .background(.red.opacity(0.12), in: Circle())
                            }
                            .buttonStyle(.plain)
                            .accessibilityLabel("Șterge recenzia")
                        }
                    }
                    Text(review.comment).font(.subheadline).foregroundStyle(.white.opacity(0.78))
                }
                .padding(14)
                .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 14))
            }
            Button {
                guard app.isAuthenticated else { app.authPresented = true; return }
                viewModel.isReviewPresented = true
            } label: {
                Label(viewModel.reviews?.items.isEmpty != false ? "Scrie prima recenzie" : "Scrie o recenzie", systemImage: "square.and.pencil")
                    .frame(maxWidth: .infinity)
            }
            .buttonStyle(GlassButtonStyle())
        }
    }

    private func hasAccess(_ movie: Content) -> Bool { viewModel.hasAccess(app: app) }
}

private struct PurchaseSheet: View {
    @Environment(FilmotecaModel.self) private var app
    let content: Content
    let performPurchase: (Offer) async throws -> Void
    @State private var selected: Offer?
    @State private var purchasing = false
    @State private var error: String?
    @State private var topUpPresented = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    HStack(spacing: 14) {
                        RemoteImage(url: content.poster).frame(width: 75, height: 108).clipShape(RoundedRectangle(cornerRadius: 10))
                        VStack(alignment: .leading, spacing: 5) {
                            Text(content.title).filmotecaTitle(.title3)
                            Text("Alege opțiunea de vizionare și calitatea").foregroundStyle(FilmotecaTheme.muted)
                        }
                    }

                    ForEach(offerGroups) { group in
                        VStack(alignment: .leading, spacing: 10) {
                            Text(group.label.uppercased())
                                .font(.caption2.weight(.black))
                                .tracking(1.8)
                                .foregroundStyle(.white.opacity(0.75))
                            ForEach(group.offers) { offer in
                                offerButton(offer)
                            }
                        }
                    }

                    HStack {
                        Label("Sold portofel", systemImage: "wallet.pass")
                            .font(.subheadline)
                            .foregroundStyle(FilmotecaTheme.muted)
                        Spacer()
                        Text("\(app.balance.formatted(.number.precision(.fractionLength(2)))) \(app.currency)")
                            .font(.subheadline.bold())
                            .foregroundStyle(app.balance >= (selected?.priceAmount ?? 0) ? .green : .orange)
                    }

                    if let selected, app.balance < selected.priceAmount {
                        Text("Sold insuficient pentru opțiunea aleasă.")
                            .font(.footnote)
                            .foregroundStyle(.orange)
                        Button { topUpPresented = true } label: {
                            Label(app.t("topup"), systemImage: "creditcard")
                        }
                        .buttonStyle(GlassButtonStyle(prominent: true))
                    } else {
                        Button { Task { await purchase() } } label: {
                            if purchasing {
                                ProgressView().tint(.white)
                            } else if let selected {
                                Text("Confirmă cumpărarea – \(price(selected))")
                            } else {
                                Text("Selectează o opțiune")
                            }
                        }
                        .buttonStyle(GlassButtonStyle(prominent: true))
                        .disabled(selected == nil || purchasing)
                    }

                    if let error { Text(error).font(.footnote).foregroundStyle(.red) }
                    Text("Plata accesului este efectuată din soldul existent. Alimentarea poate fi inițiată direct din aplicație.")
                        .font(.caption2)
                        .foregroundStyle(.tertiary)
                }
                .padding(20)
            }
            .background(FilmotecaTheme.background)
            .navigationTitle("Acces la film")
            .navigationBarTitleDisplayMode(.inline)
        }
        .presentationDetents([.large])
        .onAppear { selected = content.purchaseOffers.first }
        .sheet(isPresented: $topUpPresented, onDismiss: { Task { await app.refreshAccount() } }) { WalletTopUpSheet() }
    }

    private var offerGroups: [PurchaseOfferGroup] {
        let grouped = Dictionary(grouping: content.purchaseOffers) { offer in
            switch offer.offerType.lowercased() {
            case "rental", "rent": return "days-\(offer.rentalDays ?? 0)"
            case "lifetime", "purchase", "buy": return "lifetime"
            case "free": return "free"
            default: return "\(offer.offerType)-\(offer.rentalDays ?? 0)"
            }
        }

        return grouped.map { key, offers in
            PurchaseOfferGroup(id: key, label: offers[0].durationLabel, offers: offers.sorted {
                if $0.priceAmount == $1.priceAmount { return $0.qualityLabel < $1.qualityLabel }
                return $0.priceAmount < $1.priceAmount
            })
        }
        .sorted {
            let lhs = $0.offers.first?.rentalDays ?? Int.max
            let rhs = $1.offers.first?.rentalDays ?? Int.max
            return lhs == rhs ? $0.label < $1.label : lhs < rhs
        }
    }

    private func offerButton(_ offer: Offer) -> some View {
        Button { selected = offer } label: {
            HStack(spacing: 14) {
                VStack(alignment: .leading, spacing: 5) {
                    Text(offer.durationLabel.uppercased())
                        .font(.caption2.weight(.black))
                        .tracking(1.1)
                        .foregroundStyle(FilmotecaTheme.muted)
                    Text(offer.qualityLabel).font(.title3.bold())
                    Text(price(offer)).font(.headline)
                }
                Spacer()
                Image(systemName: selected?.id == offer.id ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(selected?.id == offer.id ? FilmotecaTheme.accent : .secondary)
            }
            .padding(16)
            .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 15))
            .overlay(RoundedRectangle(cornerRadius: 15).stroke(selected?.id == offer.id ? FilmotecaTheme.accent : FilmotecaTheme.hairline, lineWidth: selected?.id == offer.id ? 2 : 1))
        }
        .buttonStyle(.plain)
    }

    private func price(_ offer: Offer) -> String {
        "\(offer.currency) \(offer.priceAmount.formatted(.number.precision(.fractionLength(2))))"
    }

    private func purchase() async { guard let selected else { return }; purchasing = true; defer { purchasing = false }; do { try await performPurchase(selected) } catch { self.error = error.localizedDescription } }
}

private struct PurchaseOfferGroup: Identifiable {
    let id: String
    let label: String
    let offers: [Offer]
}

private struct ReviewComposer: View {
    @Environment(\.dismiss) private var dismiss
    let content: Content
    let submitReview: (Int, String) async throws -> Void
    @State private var rating = 5
    @State private var comment = ""
    @State private var sending = false
    @State private var error: String?
    var body: some View {
        NavigationStack { VStack(spacing: 22) { Text(content.title).filmotecaTitle(.title2); HStack { ForEach(1...5, id: \.self) { value in Button { rating = value } label: { Image(systemName: value <= rating ? "star.fill" : "star").font(.title).foregroundStyle(FilmotecaTheme.gold) } } }; TextEditor(text: $comment).frame(minHeight: 150).padding(8).scrollContentBackground(.hidden).background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 14)); if let error { Text(error).foregroundStyle(.red).font(.footnote) }; Button("Publică recenzia") { Task { await submit() } }.buttonStyle(GlassButtonStyle(prominent: true)).disabled(comment.trimmingCharacters(in: .whitespacesAndNewlines).count < 3 || sending); Spacer() }.padding(20).background(FilmotecaTheme.background).navigationTitle("Recenzie").navigationBarTitleDisplayMode(.inline).toolbar { ToolbarItem(placement: .cancellationAction) { Button("Anulează") { dismiss() } } } }.presentationDetents([.large])
    }
    private func submit() async { sending = true; defer { sending = false }; do { try await submitReview(rating, comment); dismiss() } catch { self.error = error.localizedDescription } }
}
