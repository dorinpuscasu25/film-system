<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

use Victoriabank\CyberSource\Exception\UnknownStatusException;
use Victoriabank\CyberSource\Exception\VbRuleException;

/**
 * Victoriabank CyberSource SDK for PHP (8.2+) - same methods and results as the Node.js SDK (lib/index.js).
 *
 * Every method builds the canonical request (payloads P01-P40), validates it against the Victoriabank rules BEFORE it is
 * sent, sends it through a Transport and returns the response with its interpretation. `data` is always the original
 * CyberSource response.
 *
 *   $vb = VbCyberSource::create(merchantId: ..., keyId: ..., sharedSecret: ..., baseUrl: 'http://127.0.0.1:8770');   // simulator
 *   $vb = new VbCyberSource(new OfficialSdkTransport($officialApiClient));       // CyberSource TEST certification and production (JWT v2 + MLE)
 *
 * Transports: the built-in Client signs with JWT v2 and has no Message-Level Encryption - use it with the VB simulator (and
 * for debugging). Certify in the CyberSource test environment with OfficialSdkTransport + MLE, the same configuration as
 * production.
 *
 * Network methods return ['httpStatus' => int, 'data' => array|string, ...] or throw Exception\UnknownStatusException: every
 * HTTP 5xx, a timeout or transport error, or a reason in UNKNOWN_REASONS (including HTTP 400 DUPLICATE_REQUEST). The outcome
 * is UNKNOWN and must be reconciled with searchByReference() (R10); the exception carries $reference and, for an
 * authorization, $transactionId (for reverseByTransactionId()).
 *
 * Scope: this SDK (and the CLI examples) covers every API operation. The full reference state machine of the kit (pages,
 * 3-D Secure, Unified Checkout, capture) is implemented in Node.js only; a PHP shop reaches READY with its own flow.
 */
final class VbCyberSource
{
    /** Reasons meaning "the original request may have succeeded": search by reference before anything else (R10). */
    public const UNKNOWN_REASONS = Requests::UNKNOWN_REASONS;

    /** 3DS policy of this instance (default: policy.threeDs.acceptAttempted of the rule file - bank decision). */
    public readonly bool $acceptAttempted;

    public function __construct(
        public readonly Transport $transport,
        public readonly string $currency = 'MDL',
        public readonly bool $strictRules = true,
        ?bool $acceptAttempted = null,
    ) {
        $this->acceptAttempted = $acceptAttempted ?? (bool) (Rules::policy()['threeDs']['acceptAttempted'] ?? true);
    }

    /**
     * Built-in transport (JWT v2, no MLE): VB simulator ($baseUrl, http only on localhost) and debugging. Refuses 'production'.
     */
    public static function create(string $merchantId, string $keyId, string $sharedSecret, string $environment = 'test',
        ?string $baseUrl = null, string $currency = 'MDL', bool $strictRules = true, ?bool $acceptAttempted = null): self
    {
        return new self(new Client($merchantId, $keyId, $sharedSecret, $environment, $baseUrl), $currency, $strictRules, $acceptAttempted);
    }

    /**
     * Validates ($scenario: one name or several validated together) and sends one request.
     * @param string|string[]|null $scenario
     */
    public function send(string|array|null $scenario, string $method, string $path, ?array $body = null): array
    {
        if ($scenario !== null) {
            $v = Rules::validate($scenario, $body ?? [], ['threeDs' => ['acceptAttempted' => $this->acceptAttempted]]);
            if ($v && $this->strictRules) {
                throw new VbRuleException($scenario, $v);
            }
        }
        $ref = $body['clientReferenceInformation'] ?? [];
        $tag = static function (UnknownStatusException $e) use ($ref): UnknownStatusException {
            if (isset($ref['code']) && $e->reference === null) {
                $e->reference = (string) $ref['code'];
            }
            if (isset($ref['transactionId']) && $e->transactionId === null) {
                $e->transactionId = (string) $ref['transactionId'];
            }
            return $e;
        };
        try {
            [$status, $data] = $this->transport->request($method, $path, $body);
        } catch (UnknownStatusException $e) {
            throw $tag($e);
        }
        // C3: a 5xx or an "unknown outcome" reason is never a final answer, whatever the transport (R10)
        $reason = is_array($data) ? ($data['reason'] ?? $data['errorInformation']['reason'] ?? null) : null;
        if ($status >= 500 || in_array($reason, self::UNKNOWN_REASONS, true)) {
            throw $tag(new UnknownStatusException("CyberSource status unknown ($method $path, HTTP $status" . ($reason ? ", $reason" : '') . ')',
                $status, is_array($data) ? $data : null, $reason));
        }
        return ['httpStatus' => $status, 'data' => $data];
    }

    /** 3-D Secure evidence under this instance's policy. */
    public function evidence(array|string|null $response): array
    {
        return Requests::full3dsEvidence($response, ['acceptAttempted' => $this->acceptAttempted]);
    }

    private function keys(): array { return ['baseUrl' => $this->transport->baseUrl()]; }

    private static function jwtText(mixed $data): string
    {
        return is_string($data) ? $data : (string) ($data['raw'] ?? '');
    }

    // ------------------------------------------------------------ sessions (card form in the merchant page, R1 / R13)
    /** Microform v2 capture context, verified (RS256): library URL + SRI to load, JWK to verify the transient token. */
    public function createMicroformSession(string $targetOrigin, array $allowedCardNetworks = ['VISA', 'MASTERCARD']): array
    {
        $r = $this->send('microform_session', 'POST', Requests::ENDPOINTS['microformSessions'], Requests::microformSession($targetOrigin, $allowedCardNetworks));
        if (!in_array($r['httpStatus'], [200, 201], true)) {
            return $r + ['ok' => false];
        }
        $cc = self::jwtText($r['data']);
        $payload = Jwt::verify($cc, $this->keys());
        return ['data' => $cc, 'ok' => true, 'captureContext' => $cc, 'jwk' => $payload['flx']['jwk'] ?? null] + $r + Jwt::clientLibraryOf($payload);
    }

    /** Unified Checkout session 1: 0.00 + tokenCreate, no 3DS (R2, R6). $existingCustomerId: add the card under that customer. */
    public function createUcAddCardSession(string $targetOrigin, string $reference, ?array $billTo, ?string $existingCustomerId = null, string $locale = 'ro_RO'): array
    {
        return $this->ucSession('uc_session_account_verification',
            Requests::ucSessionAccountVerification($targetOrigin, $reference, $this->currency, $billTo, $existingCustomerId, $locale));
    }

    /**
     * Unified Checkout session 2: pay with the TMS token, 3-D Secure by Unified Checkout (R3, R4, R12).
     * $agreement ['type' => 'recurring'|'installment'|'unscheduled', 'variableAmount' => bool?] + $cardType/$brand: this payment
     * establishes the agreement (credentialStoredOnFile, Mastercard reason 7/8/9); store its networkTransactionId as the agreement NTID.
     */
    public function createUcPaySession(string $targetOrigin, string $reference, string $customerId, string|int|float $amount, ?array $billTo,
        string $locale = 'ro_RO', ?array $agreement = null, ?string $cardType = null, ?string $brand = null): array
    {
        return $this->ucSession($agreement !== null ? 'uc_session_pay_with_token_agreement' : 'uc_session_pay_with_token',
            Requests::ucSessionPayWithToken($targetOrigin, $reference, $customerId, $amount, $this->currency, $billTo, $locale, false, $agreement, $brand, $cardType));
    }

    /**
     * Unified Checkout direct payment (bank scenario A, rules uc_session_direct): one session, amount > 0, card / Apple Pay /
     * Google Pay, 3-D Secure, NOTHING stored (no Account Verification, no token). $capture = true: completeMandate CAPTURE (sale):
     * an unwanted / superseded sale is released with voidPayment($paymentId), never with reverse(). Default AUTH: captured on shipment.
     */
    public function createUcDirectSession(string $targetOrigin, string $reference, string|int|float $amount, ?array $billTo = null,
        string $locale = 'ro_RO', ?array $allowedPaymentTypes = null, bool $capture = false): array
    {
        return $this->ucSession('uc_session_direct',
            Requests::ucSessionDirect($targetOrigin, $reference, $amount, $this->currency, $billTo, $locale, $allowedPaymentTypes, $capture));
    }

    private function ucSession(string $scenario, array $body): array
    {
        $r = $this->send($scenario, 'POST', Requests::ENDPOINTS['ucSessions'], $body);
        if (!in_array($r['httpStatus'], [200, 201], true)) {
            return $r + ['ok' => false];
        }
        $jwt = self::jwtText($r['data']);
        $payload = Jwt::verify($jwt, $this->keys());
        return ['data' => $jwt, 'ok' => true, 'sessionJwt' => $jwt] + $r + Jwt::clientLibraryOf($payload);
    }

    /** Transient token from Microform: RS256 + exp (15 min). Pass the JWK returned by createMicroformSession(). */
    public function verifyTransientToken(string $token, ?array $jwk = null): array
    {
        return Jwt::verify($token, $jwk ? ['jwk' => $jwk] : $this->keys());
    }

    // ------------------------------------------------------------ tokenization (R2)
    /** 0.00 Account Verification + TOKEN_CREATE. Returns tokens (customer, payment instrument, NTID, card type) and transactionId. */
    public function verifyCard(string $reference, string $transientToken, ?array $billTo, ?string $existingCustomerId = null, ?string $transactionId = null): array
    {
        $body = Requests::accountVerification($reference, $transientToken, $this->currency, $billTo, $existingCustomerId, $transactionId);
        $r = $this->send('account_verification', 'POST', Requests::ENDPOINTS['payments'], $body);
        $outcome = Requests::interpretPayment($r['httpStatus'], $r['data']);
        $tokens = Requests::tokensFrom($r['data']);
        $tokens['customerId'] ??= $existingCustomerId;
        return $r + ['outcome' => $outcome, 'verified' => $outcome['next'] === 'APPROVED' && !empty($tokens['paymentInstrumentId']), 'tokens' => $tokens,
            'transactionId' => $body['clientReferenceInformation']['transactionId']];
    }

    /**
     * Alternate verifyCard(): 0.00 Account Verification + TOKEN_CREATE combined with 3-D Secure already completed
     * ($threeDs = ['cavv', 'eciRaw', 'xid', 'directoryServerTransactionId', 'paSpecificationVersion'], from a prior
     * POST /risk/v1/authentication-setups + POST /risk/v1/authentications). Confirmed by CyberSource support on a
     * bank-certified sandbox test, 2026-11-04/05 - Visa only so far; use verifyCard() (3-D Secure on the first paid
     * CIT) for Mastercard or when no 3-D Secure result is available yet.
     */
    public function verifyCardWith3ds(string $reference, string $transientToken, ?array $billTo, ?string $existingCustomerId,
        ?string $transactionId, array $threeDs): array
    {
        $body = Requests::accountVerificationWith3ds($reference, $transientToken, $this->currency, $billTo, $existingCustomerId, $transactionId, $threeDs);
        $r = $this->send('account_verification_3ds_combined', 'POST', Requests::ENDPOINTS['payments'], $body);
        $outcome = Requests::interpretPayment($r['httpStatus'], $r['data']);
        $tokens = Requests::tokensFrom($r['data']);
        $tokens['customerId'] ??= $existingCustomerId;
        return $r + ['outcome' => $outcome, 'verified' => $outcome['next'] === 'APPROVED' && !empty($tokens['paymentInstrumentId']), 'tokens' => $tokens,
            'transactionId' => $body['clientReferenceInformation']['transactionId']];
    }

    // ------------------------------------------------------------ customer-initiated payment with 3-D Secure (R3, R4)
    public function setupPayerAuth(string $reference, string $customerId): array
    {
        $r = $this->send('payer_auth_setup', 'POST', Requests::ENDPOINTS['paSetup'], Requests::payerAuthSetup($reference, $customerId));
        return $r + ['setup' => is_array($r['data']) ? ($r['data']['consumerAuthenticationInformation'] ?? []) : []];
    }

    /**
     * Authorization with the token + 3DS enrollment (combined). outcome.next: APPROVED | CHALLENGE | DECLINED | AUTH_FAILED | ...
     * $agreement ['type' => 'recurring'|'installment'|'unscheduled', 'variableAmount' => bool?] (+ $cardType '001'/'002' or $brand):
     * this paid CIT ESTABLISHES the agreement (credentialStoredOnFile, firstRecurringPayment, Mastercard reason 7/8/9). When paid,
     * store $result['agreement']['networkTransactionId']: it is the previousTransactionId of every MIT of that agreement. Pass
     * the same agreement to validateChallenge() after a challenge.
     */
    public function authorize(string $reference, ?string $customerId, ?string $paymentInstrumentId, string|int|float $amount, ?array $billTo,
        array $device, string $referenceId, string $returnUrl, ?string $transactionId = null, ?array $agreement = null, ?string $cardType = null,
        ?string $brand = null): array
    {
        $body = Requests::citAuthorizeWith3ds($reference, $customerId, $paymentInstrumentId, $amount, $this->currency, $billTo, $device, $referenceId,
            $returnUrl, false, $transactionId, $agreement, $brand, $cardType);
        $a = $agreement !== null ? Requests::agreementCell($agreement, $brand, $cardType) : null;
        $r = $this->send($a ? ['cit_3ds_combined', $a['scenario']] : 'cit_3ds_combined', 'POST', Requests::ENDPOINTS['payments'], $body);
        return $this->payment($r, $body['clientReferenceInformation']['transactionId'], $a);
    }

    /** After the challenge: validation + authorization (combined); a new authorization request with its own transactionId. Same agreement option as authorize(). */
    public function validateChallenge(string $reference, ?string $customerId, ?string $paymentInstrumentId, string|int|float $amount,
        string $authenticationTransactionId, ?string $transactionId = null, ?array $agreement = null, ?string $cardType = null, ?string $brand = null): array
    {
        $body = Requests::citValidateAndAuthorize($reference, $customerId, $paymentInstrumentId, $amount, $this->currency, $authenticationTransactionId,
            false, $transactionId, $agreement, $brand, $cardType);
        $a = $agreement !== null ? Requests::agreementCell($agreement, $brand, $cardType) : null;
        $r = $this->send($a ? ['cit_validate', $a['scenario']] : 'cit_validate', 'POST', Requests::ENDPOINTS['payments'], $body);
        return $this->payment($r, $body['clientReferenceInformation']['transactionId'], $a);
    }

    /**
     * Decision of an authorization response (also used for the Unified Checkout result):
     *   mustReverse  approved or in review WITHOUT full 3-D Secure (R4, policy) -> reverse the authorization
     *   mustRelease  the hold must be released: mustReverse, a partial authorization, or AUTHORIZED_RISK_DECLINED
     *   paid         APPROVED with full 3-D Secure: the only state in which goods may be delivered / captured
     */
    private function decide(array $outcome, array $evidence): array
    {
        $mustReverse = in_array($outcome['next'], ['APPROVED', 'REVIEW'], true) && !$evidence['full3ds'];
        return ['mustReverse' => $mustReverse,
            'mustRelease' => $mustReverse || $outcome['next'] === 'PARTIAL' || ($outcome['riskDeclined'] ?? false) === true,
            'paid' => $outcome['next'] === 'APPROVED' && !$mustReverse];
    }

    private function payment(array $r, string $transactionId, ?array $agreement): array
    {
        $outcome = Requests::interpretPayment($r['httpStatus'], $r['data']);
        $evidence = $this->evidence($r['data']);
        $decision = $this->decide($outcome, $evidence);
        $ntid = Requests::tokensFrom($r['data'])['networkTransactionId'];
        $out = $r + ['outcome' => $outcome, 'evidence' => $evidence] + $decision + ['transactionId' => $transactionId, 'networkTransactionId' => $ntid];
        if ($agreement !== null) {
            // the agreement is established only by a PAID agreement CIT: persist [type, brand, networkTransactionId] per agreement
            $out['agreement'] = ['type' => $agreement['type'], 'brand' => $agreement['brand'], 'established' => $decision['paid'],
                'networkTransactionId' => $decision['paid'] ? $ntid : null]
                + (isset($agreement['pendingConfirmation']) ? ['pendingConfirmation' => $agreement['pendingConfirmation']] : []);
        }
        $out['challenge'] = $outcome['next'] === 'CHALLENGE' && is_array($r['data']) ? ($r['data']['consumerAuthenticationInformation'] ?? []) : null;
        return $out;
    }

    // ------------------------------------------------------------ merchant-initiated payment (R5)
    /**
     * MIT with the stored card, values from policy.storedCredentials.mit (rule file):
     *   $kind 'recurring' (fixed amount) | 'recurring_variable' | 'installment' | 'unscheduled' | 'topup' | 'resubmission'
     *         | 'delayed' | 'reauthorization' (Visa only) | 'noshow'   ('incremental': use incrementalAuthorization())
     *   $cardType ('001'/'002', tokens.cardType) or $brand ('visa'/'mastercard'): the reason code depends on the network
     *   $previousTransactionId: the 15-digit NTID of the transaction that ESTABLISHED THE AGREEMENT (stored per agreement)
     * Throws VbRuleException (nothing sent) for an unsupported kind / network or a missing / malformed NTID.
     * Result: outcome, paid, mustRelease, transactionId, kind, scenario, pendingConfirmation (table references still open).
     */
    public function chargeStoredCard(string $reference, ?string $customerId, ?string $paymentInstrumentId, string|int|float $amount,
        ?string $cardType, string $kind, ?string $previousTransactionId, ?string $brand = null, ?string $transactionId = null, bool $capture = true): array
    {
        $b = strtolower((string) ($brand ?? Requests::brandOf($cardType) ?? ''));
        $cell = Requests::mitCell($kind, $b);
        $body = Requests::mit($reference, $customerId, $paymentInstrumentId, $amount, $this->currency, $b, $cell['kind'], $previousTransactionId,
            $capture, $transactionId);
        $r = $this->send($cell['scenario'], 'POST', Requests::ENDPOINTS['payments'], $body);
        $outcome = Requests::interpretPayment($r['httpStatus'], $r['data']);
        return $r + ['outcome' => $outcome, 'paid' => $outcome['next'] === 'APPROVED',
            'mustRelease' => $outcome['next'] === 'PARTIAL' || ($outcome['riskDeclined'] ?? false) === true,
            'transactionId' => $body['clientReferenceInformation']['transactionId'], 'kind' => $cell['kind'], 'scenario' => $cell['scenario']]
            + (isset($cell['pendingConfirmation']) ? ['pendingConfirmation' => $cell['pendingConfirmation']] : []);
    }

    /**
     * Incremental authorization: PATCH /pts/v2/payments/{id} adds $additionalAmount to a PRE-authorization (not captured,
     * same currency, at most 100 increments). Availability and eligible MCCs: to be confirmed by Victoriabank (C9).
     */
    public function incrementalAuthorization(string $paymentId, string $reference, string|int|float $additionalAmount, ?string $currency = null,
        ?string $transactionId = null): array
    {
        $body = Requests::incrementalAuthorization($reference, $additionalAmount, $currency ?? $this->currency, $transactionId);
        $r = $this->send('incremental_authorization', 'PATCH', Requests::incrementalPath($paymentId), $body);
        return $r + ['outcome' => Requests::interpretPayment($r['httpStatus'], $r['data']), 'transactionId' => $body['clientReferenceInformation']['transactionId']];
    }

    // ------------------------------------------------------------ follow-on operations
    public function capture(string $paymentId, string $reference, string|int|float $amount): array
    {
        return $this->send('follow_on', 'POST', Requests::capturePath($paymentId), Requests::capture($reference, $amount, $this->currency));
    }

    /** Reversal of the full authorized amount (before capture, or after a void). */
    public function reverse(string $paymentId, string $reference, string|int|float $amount, string $reason = 'Order canceled'): array
    {
        return $this->send('follow_on', 'POST', Requests::reversalPath($paymentId), Requests::reversal($reference, $amount, $this->currency, $reason));
    }

    /**
     * Timeout reversal: POST /pts/v2/reversals with the merchant transactionId of an authorization whose response never
     * arrived (wait 60 s after the timeout). Availability on Visa Platform Connect: to be confirmed by the bank (B4).
     */
    public function reverseByTransactionId(string $transactionId, string $reference, string|int|float $amount, ?string $currency = null,
        string $reason = 'Timeout'): array
    {
        return $this->send('timeout_reversal', 'POST', Requests::ENDPOINTS['timeoutReversal'],
            Requests::timeoutReversal($reference, $transactionId, $amount, $currency ?? $this->currency, $reason));
    }

    /** Refund of a capture or of a sale (authorization with capture): $captureOrSaleId = capture ID or sale ID. */
    public function refund(string $captureOrSaleId, string $reference, string|int|float $amount): array
    {
        return $this->send('follow_on', 'POST', Requests::refundPath($captureOrSaleId), Requests::refund($reference, $amount, $this->currency));
    }

    /** Void of a sale (authorization with capture, e.g. a MIT or a UC CAPTURE payment) before settlement. */
    public function voidPayment(string $paymentId, string $reference): array
    {
        return $this->send('follow_on', 'POST', Requests::voidPaymentPath($paymentId), Requests::voidRequest($reference));
    }

    /** Void of a capture before settlement. */
    public function voidCapture(string $captureId, string $reference): array
    {
        return $this->send('follow_on', 'POST', Requests::voidCapturePath($captureId), Requests::voidRequest($reference));
    }

    public function voidRefund(string $refundId, string $reference): array
    {
        return $this->send('follow_on', 'POST', Requests::voidRefundPath($refundId), Requests::voidRequest($reference));
    }

    // ------------------------------------------------------------ status, reconciliation, tokens
    /**
     * Transactions with this clientReferenceInformation.code (reconciliation after an unknown status, R10).
     * @param array{from?:string,to?:string,limit?:int} $window default yesterday to tomorrow, 10 results
     */
    public function searchByReference(string $reference, array $window = []): array
    {
        $r = $this->send('search', 'POST', Requests::ENDPOINTS['searches'],
            Requests::searchByReference($reference, $window['from'] ?? 'NOW/DAY-1DAY', $window['to'] ?? 'NOW/DAY+1DAY', $window['limit'] ?? 10));
        return $r + ['transactions' => is_array($r['data']) ? ($r['data']['_embedded']['transactionSummaries'] ?? []) : []];
    }

    public function getTransaction(string $id): array { return $this->send(null, 'GET', Requests::transactionPath($id)); }
    public function deleteCustomer(string $customerId): array { return $this->send(null, 'DELETE', Requests::customerPath($customerId)); }
    public function deletePaymentInstrument(string $customerId, string $paymentInstrumentId): array
    {
        return $this->send(null, 'DELETE', Requests::paymentInstrumentPath($customerId, $paymentInstrumentId));
    }

    /**
     * New expiry date (re-issued card, same number), billing address or default flag of a stored card:
     * PATCH /tms/v2/customers/{customerId}/payment-instruments/{id}. A new card NUMBER = new capture + Account Verification (R1, R2).
     */
    public function updatePaymentInstrument(string $customerId, string $paymentInstrumentId, string|int|null $expirationMonth = null,
        string|int|null $expirationYear = null, ?array $billTo = null, ?bool $isDefault = null): array
    {
        return $this->send('tms_update_payment_instrument', 'PATCH', Requests::paymentInstrumentPath($customerId, $paymentInstrumentId),
            Requests::updatePaymentInstrument($expirationMonth, $expirationYear, $billTo, $isDefault));
    }

    /**
     * Instrument Identifier (GET /tms/v1/instrumentidentifiers/{id}) with the NTID TMS keeps for the card
     * (networkTransactionId). It is ONE per card: the previousTransactionId of a MIT is the NTID you stored per agreement.
     */
    public function getInstrumentIdentifier(string $instrumentIdentifierId): array
    {
        $r = $this->send(null, 'GET', Requests::instrumentIdentifierPath($instrumentIdentifierId));
        return $r + ['networkTransactionId' => Requests::ntidOfInstrumentIdentifier($r['data'])];
    }

    /** DELETE /tms/v1/instrumentidentifiers/{id}: removes the card for EVERY customer of the vault - prefer deletePaymentInstrument(). */
    public function deleteInstrumentIdentifier(string $instrumentIdentifierId): array
    {
        return $this->send(null, 'DELETE', Requests::instrumentIdentifierPath($instrumentIdentifierId));
    }

    /**
     * Unified Checkout result (R9): verifies the RS256 signature, confirms the transaction with GET /tss/v2/transactions/{id},
     * binds it to the expected reference, amount and currency, and applies the same decision as authorize().
     * Result: state 'MATCHED'|'MISMATCH'|'PENDING', paid, outcome, evidence, mustReverse, mustRelease, verified, id, status,
     * tokens, authorizedAmount, transaction, result, data.
     *   paid = outcome APPROVED && !mustReverse && reference + amount + currency match. Deliver only when paid is true.
     *   PENDING = the transaction is not searchable yet: retry later (paid false). MISMATCH = not this order's payment.
     *   For a 0.00 Account Verification result use 'verified' (no 3-D Secure by design, R6).
     */
    public function confirmUcResult(string $resultJwt, string $expectedReference, string|int|float $expectedAmount, ?string $expectedCurrency = null): array
    {
        $p = Jwt::verify($resultJwt, $this->keys() + ['requireExp' => false]);
        $id = (string) ($p['id'] ?? '');
        if (!preg_match('/^\d{6,40}$/D', $id)) {
            throw new \RuntimeException('Unified Checkout result without a transaction id');
        }
        $r = $this->getTransaction($id);
        if ($r['httpStatus'] !== 200 || !is_array($r['data'])) {      // not searchable yet: retry later
            return ['state' => 'PENDING', 'paid' => false, 'verified' => false, 'mustReverse' => false, 'mustRelease' => false,
                'outcome' => ['next' => 'UNKNOWN'], 'evidence' => null, 'id' => $id, 'status' => $p['status'] ?? $p['outcome'] ?? null,
                'result' => $p, 'data' => $r['data']];
        }
        $tx = $r['data'];
        $d = is_array($p['details'] ?? null) ? $p['details'] : $p;
        $status = $p['status'] ?? $p['outcome'] ?? ($tx['applicationInformation']['status'] ?? null);
        $amounts = $tx['orderInformation']['amountDetails'] ?? [];
        $norm = static function (mixed $a): ?string {
            try { return Requests::amount($a, true); } catch (\InvalidArgumentException) { return null; }
        };
        $expected = Requests::amount($expectedAmount, true);
        $currency = $expectedCurrency ?? $this->currency;
        $matched = ($tx['clientReferenceInformation']['code'] ?? null) === $expectedReference
            && $norm($amounts['totalAmount'] ?? $amounts['authorizedAmount'] ?? null) === $expected && ($amounts['currency'] ?? null) === $currency;
        $outcome = Requests::interpretPayment(201, ['status' => $status, 'errorInformation' => $d['errorInformation'] ?? $tx['errorInformation'] ?? null]);
        $evidence = $this->evidence(isset($tx['consumerAuthenticationInformation']) ? $tx : $d);
        $zero = $expected === '0.00';
        $decision = $zero ? ['mustReverse' => false, 'mustRelease' => false, 'paid' => false] : $this->decide($outcome, $evidence);
        $a = Requests::tokensFrom($d);
        $b = Requests::tokensFrom($tx);
        $tokens = [];
        foreach ($a as $k => $v) {
            $tokens[$k] = $v ?? $b[$k];
        }
        return ['state' => $matched ? 'MATCHED' : 'MISMATCH', 'mustReverse' => $decision['mustReverse'], 'mustRelease' => $decision['mustRelease'],
            'paid' => $matched && $decision['paid'], 'verified' => $matched && $zero && $outcome['next'] === 'APPROVED', 'outcome' => $outcome,
            'evidence' => $evidence, 'id' => $id, 'status' => $status,
            'authorizedAmount' => $d['orderInformation']['amountDetails']['authorizedAmount'] ?? $amounts['authorizedAmount'] ?? $amounts['totalAmount'] ?? null,
            'tokens' => $tokens, 'transaction' => $tx, 'result' => $p, 'data' => $tx];
    }

    /** v-c-signature of a webhook notification (key and key ID of your webhook subscription). Verify on the RAW body. */
    public function verifyWebhook(?string $signatureHeader, string $rawBody, string $keyB64, ?string $keyId = null): bool
    {
        return Webhook::verifySignature($signatureHeader, $rawBody, $keyB64, $keyId);
    }

    /**
     * Encrypted (MLE) notification -> event with a plaintext 'payload'. After verifyWebhook(), before acting (confirm via API).
     * Needs phpseclib 3 (brought by the official SDK); without it a clear RuntimeException is thrown.
     */
    public function decryptWebhook(string $rawBody, string $privateKeyPem, ?string $passphrase = null, bool $requireEncrypted = false, ?bool &$encrypted = null): array
    {
        return Webhook::decryptWebhook($rawBody, $privateKeyPem, $passphrase, $requireEncrypted, $encrypted);
    }
}
