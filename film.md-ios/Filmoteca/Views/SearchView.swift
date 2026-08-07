import SwiftUI

struct SearchView: View {
    @Environment(FilmotecaModel.self) private var app
    @State private var viewModel: SearchViewModel
    @State private var filtersPresented = false

    init(container: AppContainer) {
        _viewModel = State(initialValue: SearchViewModel(container: container))
    }

    private let columns = [GridItem(.adaptive(minimum: 105), spacing: 11)]

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                FilmotecaWordmark().padding(.top, 4)
                resultToolbar

                if viewModel.state.isLoading {
                    posterSkeletons
                } else if let error = viewModel.state.errorMessage, viewModel.items.isEmpty {
                    ErrorState(message: error) { Task { await viewModel.search(locale: app.locale, profile: app.activeProfile) } }
                        .frame(minHeight: 330)
                } else if viewModel.items.isEmpty {
                    EmptyLibraryCard(
                        icon: viewModel.query.isEmpty ? "sparkles.tv" : "magnifyingglass",
                        title: viewModel.query.isEmpty ? app.t("discover_cinema") : app.t("no_results"),
                        subtitle: viewModel.query.isEmpty ? app.t("discover_subtitle") : app.t("adjust_filters")
                    )
                    .filmotecaReveal()
                } else {
                    LazyVGrid(columns: columns, alignment: .leading, spacing: 20) {
                        ForEach(Array(viewModel.items.enumerated()), id: \.element.id) { index, item in
                            NavigationLink(value: item) {
                                PosterCard(content: item, width: 108)
                            }
                            .buttonStyle(.plain)
                            .filmotecaReveal(delay: min(Double(index) * 0.025, 0.25))
                        }
                    }

                    if viewModel.canLoadMore {
                        Button {
                            Task { await viewModel.loadMore(locale: app.locale, profile: app.activeProfile) }
                        } label: {
                            HStack(spacing: 9) {
                                if viewModel.isLoadingMore { ProgressView().tint(.white) }
                                Text(viewModel.isLoadingMore ? app.t("loading") : app.t("load_more"))
                            }
                            .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(GlassButtonStyle())
                        .disabled(viewModel.isLoadingMore)
                        .padding(.top, 6)
                    }
                }
            }
            .padding(.horizontal, 16)
            .padding(.bottom, 30)
        }
        .background(FilmotecaTheme.background)
        .navigationTitle(app.t("search"))
        .searchable(
            text: Binding(get: { viewModel.query }, set: { viewModel.query = $0 }),
            placement: .navigationBarDrawer(displayMode: .always),
            prompt: app.t("search_prompt")
        )
        .task(id: "\(viewModel.searchID)|\(app.locale.rawValue)|\(app.activeProfile?.id ?? "")") {
            await viewModel.search(locale: app.locale, profile: app.activeProfile)
        }
        .sheet(isPresented: $filtersPresented) { filtersSheet }
    }

    private var resultToolbar: some View {
        HStack(spacing: 12) {
            Text(viewModel.state.isLoading ? app.t("loading") : app.t("results_count", count: viewModel.total))
                .font(.caption)
                .foregroundStyle(FilmotecaTheme.muted)

            Spacer()

            Button { filtersPresented = true } label: {
                HStack(spacing: 7) {
                    Image(systemName: "line.3.horizontal.decrease")
                    Text(app.t("filters"))
                    if viewModel.activeFilterCount > 0 {
                        Text("\(viewModel.activeFilterCount)")
                            .font(.caption2.bold())
                            .frame(minWidth: 20, minHeight: 20)
                            .background(FilmotecaTheme.accent, in: Circle())
                    }
                }
                .font(.caption.weight(.bold))
                .padding(.horizontal, 12)
                .frame(height: 38)
                .background(FilmotecaTheme.surface, in: Capsule())
                .overlay(Capsule().stroke(viewModel.activeFilterCount > 0 ? FilmotecaTheme.accent.opacity(0.65) : FilmotecaTheme.hairline))
            }
            .buttonStyle(.plain)
        }
        .padding(.bottom, 4)
        .overlay(alignment: .bottom) { Rectangle().fill(FilmotecaTheme.hairline).frame(height: 1) }
    }

    private var posterSkeletons: some View {
        LazyVGrid(columns: columns, spacing: 20) {
            ForEach(0..<9, id: \.self) { index in
                RoundedRectangle(cornerRadius: 13, style: .continuous)
                    .fill(FilmotecaTheme.surface)
                    .frame(height: 160)
                    .shimmer()
                    .opacity(0.75 + Double(index % 3) * 0.08)
            }
        }
    }

    private var filtersSheet: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    filterSection(title: app.t("content_type")) {
                        FlowLayout(spacing: 8) {
                            ForEach(typeOptions, id: \.label) { option in
                                filterChip(option.label, selected: viewModel.selectedType == option.value) {
                                    withAnimation(.spring(response: 0.3, dampingFraction: 0.78)) {
                                        viewModel.selectedType = option.value
                                    }
                                }
                            }
                        }
                    }

                    filterSection(title: app.t("details")) {
                        VStack(spacing: 0) {
                            filterPicker(title: app.t("genre"), icon: "theatermasks", selection: Binding(get: { viewModel.selectedGenre }, set: { viewModel.selectedGenre = $0 }), options: viewModel.filters?.genres ?? [])
                            Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                            filterPicker(title: app.t("country"), icon: "globe.europe.africa", selection: Binding(get: { viewModel.selectedCountry }, set: { viewModel.selectedCountry = $0 }), options: viewModel.filters?.countries ?? [])
                            Divider().overlay(FilmotecaTheme.hairline).padding(.leading, 48)
                            filterPicker(title: app.t("release_year"), icon: "calendar", selection: Binding(get: { viewModel.selectedYear }, set: { viewModel.selectedYear = $0 }), options: viewModel.filters?.years ?? [])
                        }
                        .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }

                    filterSection(title: app.t("access")) {
                        HStack(spacing: 8) {
                            filterChip(app.t("all"), selected: viewModel.selectedAccess == nil) { viewModel.selectedAccess = nil }
                            filterChip(app.t("free_short"), selected: viewModel.selectedAccess == "free") { viewModel.selectedAccess = "free" }
                            filterChip(app.t("paid"), selected: viewModel.selectedAccess == "paid") { viewModel.selectedAccess = "paid" }
                        }
                    }

                    filterSection(title: app.t("minimum_rating")) {
                        VStack(spacing: 12) {
                            HStack {
                                Label("IMDb", systemImage: "star.fill").foregroundStyle(FilmotecaTheme.gold)
                                Spacer()
                                Text(viewModel.minRating > 0 ? String(format: "%.1f+", viewModel.minRating) : app.t("any_rating"))
                                    .font(.headline.monospacedDigit())
                            }
                            Slider(value: Binding(get: { viewModel.minRating }, set: { viewModel.minRating = $0 }), in: 0...10, step: 0.5)
                                .tint(FilmotecaTheme.gold)
                        }
                        .padding(16)
                        .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }

                    Button(role: .destructive) {
                        withAnimation { viewModel.clearFilters() }
                    } label: {
                        Label(app.t("clear_filters"), systemImage: "arrow.counterclockwise")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(GlassButtonStyle())
                    .disabled(!viewModel.hasActiveSearchOrFilters)
                    .opacity(viewModel.hasActiveSearchOrFilters ? 1 : 0.45)
                }
                .padding(18)
            }
            .background(FilmotecaTheme.background)
            .navigationTitle(app.t("filters"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button(app.t("show_results")) { filtersPresented = false }
                        .fontWeight(.bold)
                }
            }
        }
        .presentationDetents([.medium, .large])
        .presentationDragIndicator(.visible)
        .presentationCornerRadius(28)
    }

    private var typeOptions: [(value: String?, label: String)] {
        let apiLabels = Dictionary(uniqueKeysWithValues: (viewModel.filters?.types ?? []).map { ($0.value, $0.label) })
        return [
            (nil, app.t("all")),
            ("movie", apiLabels["movie"] ?? app.t("movies")),
            ("series", apiLabels["series"] ?? app.t("series")),
            ("documentary", apiLabels["documentary"] ?? app.t("documentaries")),
            ("short", apiLabels["short"] ?? app.t("short_films")),
            ("animation", apiLabels["animation"] ?? app.t("animation")),
        ]
    }

    private func filterSection<ContentView: View>(title: String, @ViewBuilder content: () -> ContentView) -> some View {
        VStack(alignment: .leading, spacing: 11) {
            Text(title.uppercased())
                .font(.caption2.weight(.black))
                .tracking(1.2)
                .foregroundStyle(FilmotecaTheme.muted)
            content()
        }
    }

    private func filterChip(_ label: String, selected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(label)
                .font(.caption.weight(.bold))
                .foregroundStyle(selected ? .white : FilmotecaTheme.muted)
                .padding(.horizontal, 14)
                .frame(minHeight: 38)
                .background(selected ? FilmotecaTheme.accent : FilmotecaTheme.surface, in: Capsule())
                .overlay(Capsule().stroke(selected ? .clear : FilmotecaTheme.hairline))
        }
        .buttonStyle(.plain)
    }

    private func filterPicker(title: String, icon: String, selection: Binding<String?>, options: [FilterOption]) -> some View {
        Menu {
            Button {
                selection.wrappedValue = nil
            } label: {
                Label(app.t("all"), systemImage: selection.wrappedValue == nil ? "checkmark" : "circle")
            }
            if !options.isEmpty { Divider() }
            ForEach(options) { option in
                Button {
                    selection.wrappedValue = option.value
                } label: {
                    Label(
                        "\(option.label) (\(option.count))",
                        systemImage: selection.wrappedValue == option.value ? "checkmark" : "circle"
                    )
                }
            }
        } label: {
            HStack(spacing: 13) {
                Image(systemName: icon).frame(width: 24).foregroundStyle(FilmotecaTheme.accent)
                Text(title)
                Spacer()
                Text(options.first(where: { $0.value == selection.wrappedValue })?.label ?? app.t("all"))
                    .foregroundStyle(.white)
                    .lineLimit(1)
                Image(systemName: "chevron.up.chevron.down")
                    .font(.caption)
                    .foregroundStyle(FilmotecaTheme.muted)
            }
            .contentShape(Rectangle())
        }
        .padding(16)
        .tint(.white)
    }
}

private struct FlowLayout: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        layout(proposal: proposal, subviews: subviews).size
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let result = layout(proposal: proposal, subviews: subviews)
        for (index, point) in result.points.enumerated() {
            subviews[index].place(at: CGPoint(x: bounds.minX + point.x, y: bounds.minY + point.y), proposal: .unspecified)
        }
    }

    private func layout(proposal: ProposedViewSize, subviews: Subviews) -> (size: CGSize, points: [CGPoint]) {
        let width = proposal.width ?? .infinity
        var points: [CGPoint] = []
        var x: CGFloat = 0
        var y: CGFloat = 0
        var rowHeight: CGFloat = 0

        for subview in subviews {
            let size = subview.sizeThatFits(.unspecified)
            if x + size.width > width, x > 0 {
                x = 0
                y += rowHeight + spacing
                rowHeight = 0
            }
            points.append(CGPoint(x: x, y: y))
            x += size.width + spacing
            rowHeight = max(rowHeight, size.height)
        }

        return (CGSize(width: width.isFinite ? width : x, height: y + rowHeight), points)
    }
}
