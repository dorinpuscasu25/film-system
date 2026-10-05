import StoreKit
import SwiftUI

/// Replaces the old web-checkout wallet top-up flow on iOS. Apple requires In-App Purchase for
/// digital credits consumed inside the app (rule 3.1.1) — the film catalog itself is priced
/// identically everywhere; only what a *credit pack* costs differs here, to cover Apple's
/// commission. See docs/ios-in-app-purchase-audit.md.
struct CreditPackSheet: View {
    @Environment(FilmotecaModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var packs: [StoreKitService.CreditPack] = []
    @State private var isLoadingProducts = true
    @State private var purchasingProductID: String?
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                if let error {
                    Section { Text(error).font(.footnote).foregroundStyle(.red) }
                }
                Section {
                    HStack {
                        Text(app.t("current_balance")).foregroundStyle(FilmotecaTheme.muted)
                        Spacer()
                        Text(app.balance.formatted(.number.precision(.fractionLength(0...2))) + " " + app.currency).bold()
                    }
                }
                Section {
                    if isLoadingProducts {
                        HStack { Spacer(); ProgressView(); Spacer() }
                    } else if packs.isEmpty {
                        Text(app.t("credit_packs_unavailable")).font(.subheadline).foregroundStyle(FilmotecaTheme.muted)
                    } else {
                        ForEach(packs) { pack in
                            Button { Task { await purchase(pack.product) } } label: {
                                HStack {
                                    VStack(alignment: .leading, spacing: 3) {
                                        Text(app.t("credits_amount").replacingOccurrences(of: "%d", with: pack.credits.formatted(.number.precision(.fractionLength(0...2)))))
                                            .font(.subheadline.bold())
                                    }
                                    Spacer()
                                    if purchasingProductID == pack.id {
                                        ProgressView()
                                    } else {
                                        Text(pack.product.displayPrice).font(.subheadline.bold())
                                    }
                                }
                            }
                            .disabled(purchasingProductID != nil)
                        }
                    }
                } header: {
                    Text(app.t("credit_packs_header"))
                } footer: {
                    Text(app.t("credit_packs_footer"))
                }
            }
            .scrollContentBackground(.hidden)
            .background(FilmotecaTheme.background)
            .navigationTitle(app.t("topup"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(app.t("close")) { dismiss() }.disabled(purchasingProductID != nil)
                }
            }
        }
        .task { await loadProducts() }
    }

    private func loadProducts() async {
        isLoadingProducts = true
        defer { isLoadingProducts = false }
        do {
            packs = try await app.container.storeKitService.creditPacks()
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func purchase(_ product: Product) async {
        purchasingProductID = product.id
        error = nil
        defer { purchasingProductID = nil }
        do {
            if try await app.container.storeKitService.purchase(product) != nil {
                await app.refreshAccount()
                dismiss()
            }
        } catch StoreKitService.PurchaseError.redeemPending {
            error = app.t("credit_pack_redeem_pending")
        } catch {
            self.error = error.localizedDescription
        }
    }
}
