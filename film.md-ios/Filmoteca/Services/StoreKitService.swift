import Foundation
import StoreKit
import OSLog

/// Wraps StoreKit 2 for the credit-pack purchase flow (see AppleIapService on the backend and
/// docs/ios-in-app-purchase-audit.md for the model this implements: 1 credit = 1 MDL everywhere,
/// iOS sells credit *packs*, never the film price itself, to cover Apple's commission).
///
/// Every verified transaction — whether from an active `purchase()` call, a relaunch replaying
/// `Transaction.unfinished`, or a background `Transaction.updates` event (Ask to Buy approval,
/// a purchase made on another device) — goes through the same redeem path to the backend.
/// `finish()` is only ever called once the backend has confirmed the credit was recorded, so a
/// network failure leaves the transaction outstanding for StoreKit to redeliver and retry rather
/// than silently losing the purchase.
@MainActor
final class StoreKitService {
    struct CreditPack: Identifiable {
        let product: Product
        let credits: Double
        var id: String { product.id }
    }

    enum PurchaseError: LocalizedError {
        /// Apple charged the customer but our backend didn't confirm the credit yet. The
        /// transaction stays unfinished and is retried on next launch / login.
        case redeemPending(underlying: Error)

        var errorDescription: String? {
            switch self {
            case .redeemPending(let underlying): underlying.localizedDescription
            }
        }
    }

    private let session: any SessionRepositoryProtocol
    private var updatesTask: Task<Void, Never>?
    private let logger = Logger(subsystem: "md.filmoteca.ios", category: "StoreKit")

    /// Called after a transaction delivered outside an active `purchase()` call (relaunch,
    /// Ask to Buy approval, retry after login) credits the wallet, so the UI can refresh.
    var onBackgroundRedeem: (() -> Void)?

    init(session: any SessionRepositoryProtocol) {
        self.session = session
    }

    /// Call once at app launch.
    func start() {
        guard updatesTask == nil else { return }
        updatesTask = Task { [weak self] in
            for await update in StoreKit.Transaction.updates {
                await self?.handleInBackground(update)
            }
        }
        retryUnfinishedTransactions()
    }

    /// Re-sends any purchase Apple charged for but our backend never confirmed — e.g. one that
    /// completed while the user was logged out or offline. Call after login too.
    func retryUnfinishedTransactions() {
        Task { [weak self] in
            for await transaction in StoreKit.Transaction.unfinished {
                await self?.handleInBackground(transaction)
            }
        }
    }

    /// The packs configured in the admin panel, joined with the StoreKit products Apple returns
    /// for them. Apple owns the displayed price; the backend owns how many credits it grants.
    func creditPacks() async throws -> [CreditPack] {
        let packs = try await session.appleIapPacks()
        guard !packs.isEmpty else { return [] }
        let products = Dictionary(
            uniqueKeysWithValues: try await Product.products(for: packs.map(\.productID)).map { ($0.id, $0) }
        )
        return packs.compactMap { pack in
            products[pack.productID].map { CreditPack(product: $0, credits: pack.creditsMdl) }
        }
    }

    /// Returns the redeemed wallet state once the backend confirms the purchase, or `nil` if the
    /// user cancelled or the purchase needs approval (Ask to Buy) — in the latter case StoreKit
    /// delivers it later through `Transaction.updates`, handled the same way.
    /// Throws `PurchaseError.redeemPending` when Apple charged but the backend didn't confirm.
    @discardableResult
    func purchase(_ product: Product) async throws -> AppleIapRedeemResponse? {
        switch try await product.purchase() {
        case .success(let verification):
            guard case .verified(let transaction) = verification else {
                logger.error("Ignoring an unverified StoreKit transaction.")
                return nil
            }
            do {
                return try await redeem(transaction, jws: verification.jwsRepresentation)
            } catch {
                throw PurchaseError.redeemPending(underlying: error)
            }
        case .userCancelled, .pending:
            return nil
        @unknown default:
            return nil
        }
    }

    private func handleInBackground(_ verification: VerificationResult<StoreKit.Transaction>) async {
        guard case .verified(let transaction) = verification else {
            // StoreKit's own local verification failed — the backend re-verifies independently
            // regardless, but there's nothing legitimate here to even send it.
            logger.error("Ignoring an unverified StoreKit transaction.")
            return
        }
        guard session.hasStoredSession else { return } // retried after login
        do {
            _ = try await redeem(transaction, jws: verification.jwsRepresentation)
            onBackgroundRedeem?()
        } catch {
            logger.error("Apple IAP redeem failed, leaving transaction unfinished for retry: \(error.localizedDescription, privacy: .public)")
        }
    }

    private func redeem(_ transaction: StoreKit.Transaction, jws: String) async throws -> AppleIapRedeemResponse {
        let response = try await session.redeemAppleIap(signedTransaction: jws)
        await transaction.finish()
        return response
    }
}
