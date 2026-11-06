import StoreKit
import SwiftUI

/// Replaces the old web-checkout wallet top-up flow on iOS. Apple requires In-App Purchase for
/// digital credits consumed inside the app (rule 3.1.1) — the film catalog itself is priced
/// identically everywhere; only what a *credit pack* costs differs here, to cover Apple's
/// commission. See docs/ios-in-app-purchase-audit.md.
struct CreditPackSheet: View {
    /// Credits still missing for what the viewer was about to buy. Drives the
    /// "recommended" pack and the call to action once the top-up lands.
    var requiredAmount: Double? = nil
    /// Called when the viewer chooses to carry on after a successful top-up
    /// (e.g. finish buying the film they opened this sheet from).
    var onFunded: (() -> Void)? = nil

    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var packs: [StoreKitService.CreditPack] = []
    @State private var isLoadingProducts = true
    @State private var loadFailed = false
    @State private var purchasingProductID: String?
    @State private var message: Banner?
    @State private var success: Success?

    private struct Banner: Equatable {
        enum Kind { case error, info }
        let kind: Kind
        let text: String
    }

    private struct Success: Equatable {
        let credits: Double
        let balance: Double
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    balanceCard

                    if let success {
                        successView(success)
                            .transition(.scale(scale: 0.92).combined(with: .opacity))
                    } else {
                        packsSection
                    }

                    if let message, success == nil { banner(message) }

                    footer
                }
                .padding(20)
                .animation(.spring(response: 0.4, dampingFraction: 0.85), value: success)
                .animation(.easeInOut(duration: 0.2), value: message)
            }
            .background(FilmotecaTheme.background)
            .navigationTitle(app.t("topup"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(app.t("close")) { dismiss() }.disabled(purchasingProductID != nil)
                }
            }
        }
        .interactiveDismissDisabled(purchasingProductID != nil)
        .sensoryFeedback(.success, trigger: success)
        .sensoryFeedback(.error, trigger: message) { _, new in new?.kind == .error }
        .task { await loadProducts() }
    }

    // MARK: - Sections

    private var balanceCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label(app.t("current_balance"), systemImage: "wallet.pass.fill")
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.white.opacity(0.8))

            Text(amount(success?.balance ?? app.balance) + " " + app.currency)
                .font(.system(size: 34, weight: .bold, design: .rounded))
                .contentTransition(.numericText())
                .monospacedDigit()

            if let shortfall, success == nil {
                Label(
                    app.t("topup_shortfall").replacingOccurrences(of: "%@", with: amount(shortfall) + " " + app.currency),
                    systemImage: "exclamationmark.circle.fill"
                )
                .font(.footnote.weight(.semibold))
                .foregroundStyle(FilmotecaTheme.gold)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(20)
        .background(
            LinearGradient(
                colors: [FilmotecaTheme.accent.opacity(0.55), FilmotecaTheme.elevated],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            ),
            in: RoundedRectangle(cornerRadius: 20)
        )
        .overlay(RoundedRectangle(cornerRadius: 20).stroke(.white.opacity(0.12)))
    }

    @ViewBuilder
    private var packsSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(app.t("credit_packs_header").uppercased())
                .font(.caption2.weight(.black))
                .tracking(1.8)
                .foregroundStyle(.white.opacity(0.75))

            if isLoadingProducts {
                LazyVGrid(columns: columns, spacing: 12) {
                    ForEach(0..<4, id: \.self) { _ in
                        RoundedRectangle(cornerRadius: 18)
                            .fill(FilmotecaTheme.surface)
                            .frame(height: 260)
                    }
                }
                .redacted(reason: .placeholder)
            } else if packs.isEmpty {
                VStack(spacing: 12) {
                    Image(systemName: loadFailed ? "wifi.exclamationmark" : "creditcard.trianglebadge.exclamationmark")
                        .font(.system(size: 30))
                        .foregroundStyle(FilmotecaTheme.muted)
                    Text(app.t(loadFailed ? "credit_packs_load_failed" : "credit_packs_unavailable"))
                        .font(.subheadline)
                        .foregroundStyle(FilmotecaTheme.muted)
                        .multilineTextAlignment(.center)
                    Button { Task { await loadProducts() } } label: {
                        Label(app.t("retry"), systemImage: "arrow.clockwise")
                    }
                    .buttonStyle(GlassButtonStyle())
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 24)
            } else {
                LazyVGrid(columns: columns, spacing: 12) {
                    ForEach(packs) { pack in packCard(pack) }
                }
            }
        }
    }

    private func packCard(_ pack: StoreKitService.CreditPack) -> some View {
        let isRecommended = pack.id == recommendedPackID
        let isPurchasing = purchasingProductID == pack.id

        return Button { Task { await purchase(pack) } } label: {
            VStack(alignment: .leading, spacing: 8) {
                packArtwork(pack)
                    .overlay(alignment: .topLeading) {
                        if isRecommended {
                            Text(app.t("recommended_pack"))
                                .font(.system(size: 9, weight: .black)).tracking(0.6).textCase(.uppercase)
                                .foregroundStyle(.black)
                                .padding(.horizontal, 7).padding(.vertical, 4)
                                .background(FilmotecaTheme.gold, in: Capsule())
                                .padding(8)
                        }
                    }

                // Name and description come from App Store Connect, in the device's language.
                Text(pack.product.displayName.isEmpty ? "\(amount(pack.credits)) \(app.t("credits_unit"))" : pack.product.displayName)
                    .font(.subheadline.weight(.bold))
                    .lineLimit(1)
                if !pack.product.description.isEmpty {
                    Text(pack.product.description)
                        .font(.caption2)
                        .foregroundStyle(FilmotecaTheme.muted)
                        .lineLimit(2, reservesSpace: true)
                }

                ZStack {
                    if isPurchasing {
                        ProgressView().tint(.white)
                    } else {
                        Text(pack.product.displayPrice)
                            .font(.subheadline.weight(.bold))
                    }
                }
                .frame(maxWidth: .infinity, minHeight: 36)
                .background(isRecommended ? FilmotecaTheme.accent : .white.opacity(0.1), in: Capsule())
            }
            .padding(10)
            .frame(maxWidth: .infinity)
            .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 18))
            .overlay(
                RoundedRectangle(cornerRadius: 18)
                    .stroke(isRecommended ? FilmotecaTheme.gold.opacity(0.8) : FilmotecaTheme.hairline, lineWidth: isRecommended ? 1.5 : 1)
            )
            .contentShape(RoundedRectangle(cornerRadius: 18))
        }
        .buttonStyle(.plain)
        .disabled(purchasingProductID != nil)
        .opacity(purchasingProductID != nil && !isPurchasing ? 0.5 : 1)
        .accessibilityLabel("\(amount(pack.credits)) \(app.t("credits_unit")), \(pack.product.displayPrice)")
    }

    /// The same artwork uploaded to App Store Connect, bundled as `iap-<product id>` — StoreKit
    /// doesn't hand out the promotional image. A pack added later without bundled art still
    /// gets a drawn ticket instead of an empty square.
    @ViewBuilder
    private func packArtwork(_ pack: StoreKitService.CreditPack) -> some View {
        if let image = UIImage(named: "iap-\(pack.id)") {
            Image(uiImage: image)
                .resizable()
                .aspectRatio(1, contentMode: .fit)
                .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
        } else {
            ZStack {
                LinearGradient(colors: [FilmotecaTheme.accent, FilmotecaTheme.crimson], startPoint: .topLeading, endPoint: .bottomTrailing)
                VStack(spacing: 0) {
                    Text(amount(pack.credits)).font(.system(size: 40, weight: .black, design: .rounded)).monospacedDigit()
                    Text(app.t("credits_unit")).font(.caption.weight(.heavy)).textCase(.uppercase).tracking(1.5).opacity(0.85)
                }
                .foregroundStyle(.white)
            }
            .aspectRatio(1, contentMode: .fit)
            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
    }

    private func successView(_ success: Success) -> some View {
        // `requiredAmount` is the shortfall when the sheet opened, so the pack
        // just bought must cover it for the original purchase to go through.
        let canContinue = onFunded != nil && success.credits >= (shortfall ?? 0)

        return VStack(spacing: 14) {
            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 56))
                .foregroundStyle(.green)
                .symbolEffect(.bounce, value: success)

            Text(app.t("topup_success_title"))
                .font(.title3.bold())

            Text(app.t("topup_success_body").replacingOccurrences(of: "%@", with: amount(success.credits)))
                .font(.subheadline)
                .foregroundStyle(FilmotecaTheme.muted)
                .multilineTextAlignment(.center)

            Button {
                dismiss()
                if canContinue { onFunded?() }
            } label: {
                Text(app.t(canContinue ? "topup_continue_watch" : "continue_action"))
                    .frame(maxWidth: .infinity)
            }
            .buttonStyle(GlassButtonStyle(prominent: true))
            .padding(.top, 6)
        }
        .frame(maxWidth: .infinity)
        .padding(22)
        .background(FilmotecaTheme.surface, in: RoundedRectangle(cornerRadius: 20))
    }

    private func banner(_ banner: Banner) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: banner.kind == .error ? "exclamationmark.triangle.fill" : "clock.fill")
                .foregroundStyle(banner.kind == .error ? .orange : FilmotecaTheme.gold)
            Text(banner.text)
                .font(.footnote)
                .fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
        }
        .padding(14)
        .background((banner.kind == .error ? Color.orange : FilmotecaTheme.gold).opacity(0.12), in: RoundedRectangle(cornerRadius: 14))
    }

    private var footer: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label(app.t("credit_packs_footer"), systemImage: "infinity")
            Label(app.t("credit_packs_secure"), systemImage: "lock.fill")
        }
        .font(.caption)
        .foregroundStyle(FilmotecaTheme.muted)
    }

    // MARK: - Logic

    private let columns = [GridItem(.flexible(), spacing: 12), GridItem(.flexible(), spacing: 12)]

    private var shortfall: Double? {
        guard let requiredAmount, requiredAmount > 0 else { return nil }
        return requiredAmount
    }

    /// The smallest pack that covers what is missing; otherwise the middle pack,
    /// which is what most people pick when nothing specific is needed.
    private var recommendedPackID: String? {
        guard !packs.isEmpty else { return nil }
        if let shortfall {
            return packs.sorted { $0.credits < $1.credits }.first { $0.credits >= shortfall }?.id
                ?? packs.max { $0.credits < $1.credits }?.id
        }
        guard packs.count >= 3 else { return nil }
        return packs.sorted { $0.credits < $1.credits }[packs.count / 2].id
    }

    private func amount(_ value: Double) -> String {
        value.formatted(.number.precision(.fractionLength(0...2)))
    }

    private func loadProducts() async {
        isLoadingProducts = true
        loadFailed = false
        message = nil
        defer { isLoadingProducts = false }
        do {
            packs = try await app.container.storeKitService.creditPacks()
                .sorted { $0.credits < $1.credits }
        } catch {
            packs = []
            loadFailed = true
        }
    }

    private func purchase(_ pack: StoreKitService.CreditPack) async {
        purchasingProductID = pack.id
        message = nil
        defer { purchasingProductID = nil }
        do {
            switch try await app.container.storeKitService.purchase(pack.product) {
            case .redeemed(let response):
                await app.refreshAccount()
                withAnimation {
                    success = Success(credits: response.creditsGranted, balance: max(app.balance, response.wallet.balanceAmount))
                }
            case .pending:
                message = Banner(kind: .info, text: app.t("credit_pack_pending"))
            case .cancelled:
                break
            }
        } catch StoreKitService.PurchaseError.unverified {
            message = Banner(kind: .error, text: app.t("purchase_error_unverified"))
        } catch StoreKitService.PurchaseError.redeemPending {
            message = Banner(kind: .error, text: app.t("credit_pack_redeem_pending"))
        } catch {
            message = Banner(kind: .error, text: friendlyMessage(for: error))
        }
    }

    private func friendlyMessage(for error: Error) -> String {
        if error is URLError { return app.t("purchase_error_network") }
        if let storeError = error as? StoreKitError {
            switch storeError {
            case .networkError: return app.t("purchase_error_network")
            case .notEntitled, .notAvailableInStorefront: return app.t("purchase_error_unavailable")
            case .userCancelled: return ""
            default: break
            }
        }
        if let purchaseError = error as? Product.PurchaseError, purchaseError == .purchaseNotAllowed {
            return app.t("purchase_error_not_allowed")
        }
        return app.t("purchase_error_generic")
    }
}
