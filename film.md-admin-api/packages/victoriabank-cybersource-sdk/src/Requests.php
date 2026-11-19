<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

use Victoriabank\CyberSource\Exception\VbRuleException;

/**
 * Request builders and response interpreters - a line-by-line port of the Node.js SDK (lib/requests.js). No I/O. The tests
 * compare every builder with the canonical payloads exemple/payloads/P01-P40 and run the parity vectors shared with Node.
 *
 *   R2  Account Verification 0.00 + TOKEN_CREATE before the first financial authorization.
 *   R3  Every financial authorization uses the TMS token (customer.id or paymentInstrument.id).
 *   R4  Every customer-initiated authorization carries full 3-D Secure.
 *   R5  MIT carries the stored-credential indicators.   R6  0.00 never with 3DS / recurring / installment.
 */
final class Requests
{
    public const ENDPOINTS = [
        'microformSessions' => '/microform/v2/sessions',
        'ucSessions' => '/uc/v1/sessions',
        'payments' => '/pts/v2/payments',
        'timeoutReversal' => '/pts/v2/reversals',
        'paSetup' => '/risk/v1/authentication-setups',
        'searches' => '/tss/v2/searches',
    ];
    public const BROWSER_FIELDS = ['ipAddress', 'httpAcceptContent', 'httpBrowserLanguage', 'httpBrowserJavaEnabled',
        'httpBrowserJavaScriptEnabled', 'httpBrowserColorDepth', 'httpBrowserScreenHeight', 'httpBrowserScreenWidth',
        'httpBrowserTimeDifference', 'userAgentBrowserValue'];
    public const BILL_TO_FIELDS = ['firstName', 'lastName', 'company', 'address1', 'address2', 'locality', 'administrativeArea', 'postalCode',
        'country', 'email', 'phoneNumber'];
    /** consumerAuthenticationInformation.deviceChannel for a browser (API field reference: SDK | Browser | 3RI). */
    public const DEVICE_CHANNEL = 'Browser';
    /** Reasons meaning "the original request may have succeeded": search by reference before anything else (R10). */
    public const UNKNOWN_REASONS = ['DUPLICATE_REQUEST', 'SYSTEM_ERROR', 'SERVER_TIMEOUT', 'SERVICE_TIMEOUT', 'PROCESSOR_TIMEOUT'];
    /** API field reference: clientReferenceInformation.transactionId, String, at most 30 characters, unique for 180 days. */
    public const TRANSACTION_ID_PATTERN = '/^[A-Za-z0-9]{1,30}$/D';

    public static function capturePath(string $id): string { return '/pts/v2/payments/' . rawurlencode($id) . '/captures'; }
    public static function reversalPath(string $id): string { return '/pts/v2/payments/' . rawurlencode($id) . '/reversals'; }
    /** {id} = capture ID or sale ID. */
    public static function refundPath(string $id): string { return '/pts/v2/payments/' . rawurlencode($id) . '/refunds'; }
    /** Void of a sale (authorization with capture). */
    public static function voidPaymentPath(string $id): string { return '/pts/v2/payments/' . rawurlencode($id) . '/voids'; }
    public static function voidCapturePath(string $id): string { return '/pts/v2/captures/' . rawurlencode($id) . '/voids'; }
    public static function voidRefundPath(string $id): string { return '/pts/v2/refunds/' . rawurlencode($id) . '/voids'; }
    public static function transactionPath(string $id): string { return '/tss/v2/transactions/' . rawurlencode($id); }
    public static function customerPath(string $id): string { return '/tms/v2/customers/' . rawurlencode($id); }
    public static function paymentInstrumentPath(string $customerId, string $piId): string
    {
        return self::customerPath($customerId) . '/payment-instruments/' . rawurlencode($piId);
    }

    /** PATCH: incremental authorization of a pre-authorization. */
    public static function incrementalPath(string $id): string { return '/pts/v2/payments/' . rawurlencode($id); }
    public static function instrumentIdentifierPath(string $id): string { return '/tms/v1/instrumentidentifiers/' . rawurlencode($id); }

    // ------------------------------------------------------------ stored credentials: the CIT/MIT value table
    /**
     * policy.storedCredentials of the rule file = the single CIT/MIT value table (review_final/08): MIT kind x network ->
     * commerceIndicator, reason, rule scenario; agreement type x network -> firstRecurringPayment, reason, scenario.
     * The builders below are driven by it; the rule scenarios and check-request.js enforce the same values.
     */
    public static function storedCredentials(): array
    {
        return Rules::policy()['storedCredentials'] ?? ['mit' => [], 'citAgreement' => []];
    }

    /** 15-digit network transaction ID (Visa / Mastercard), as a PCRE pattern. */
    public static function ntidPattern(): string
    {
        return '/' . (self::storedCredentials()['ntidPattern'] ?? '^\d{15}$') . '/D';
    }

    /** @return string[] MIT kinds the SDK sends (at least one network supported). */
    public static function mitKinds(): array
    {
        $out = [];
        foreach (self::storedCredentials()['mit'] as $k => $row) {
            foreach ($row as $c) {
                if (is_array($c) && !empty($c['supported'])) {
                    $out[] = (string) $k;
                    break;
                }
            }
        }
        return $out;
    }

    /** Agreement types for the CIT that establishes an agreement (authorize / validateChallenge / UC session, option agreement). */
    public const AGREEMENT_TYPES = ['recurring', 'installment', 'unscheduled'];

    private static function networkOfInput(?string $brand, ?string $cardType): ?string
    {
        $b = strtolower((string) ($brand ?? self::brandOf($cardType) ?? ''));
        return $b === 'visa' || $b === 'mastercard' ? $b : null;
    }

    /**
     * Cell of the MIT table for (kind, network), or a VbRuleException naming the frequent mistake.
     * @return array{kind:string,brand:string,commerceIndicator:string,reason:?string,scenario:string,pendingConfirmation?:string[]}
     */
    public static function mitCell(?string $kind, ?string $brand): array
    {
        $sc = self::storedCredentials();
        $k = strtolower((string) $kind);
        $b = strtolower((string) $brand);
        $row = $sc['mit'][$k] ?? null;
        if (!is_array($row) || $k === '') {
            throw new VbRuleException('mit', ["R5: unknown MIT kind \"$kind\": one of " . implode(', ', self::mitKinds())
                . ' (installments are "installment", not "recurring"; a variable-amount subscription is "recurring_variable")']);
        }
        if ($b !== 'visa' && $b !== 'mastercard') {
            throw new VbRuleException("mit_$k", ['R5: the card network is required (cardType "001"/"002" from tokens.cardType, or brand): '
                . 'the MIT reason code depends on it (Mastercard 7/8/9/10, Visa none for recurring / installment / UCOF)']);
        }
        $c = $row[$b] ?? null;
        if (!is_array($c) || empty($c['supported'])) {
            throw new VbRuleException($c['scenario'] ?? "mit_{$k}_$b", ['R5: ' . ($c['error'] ?? "MIT $k is not supported for $b")]);
        }
        return ['kind' => $k, 'brand' => $b, 'commerceIndicator' => $c['commerceIndicator'], 'reason' => $c['reason'] ?? null, 'scenario' => $c['scenario']]
            + (isset($c['pendingConfirmation']) ? ['pendingConfirmation' => $c['pendingConfirmation']] : []);
    }

    /**
     * Cell of the agreement table: $agreement = ['type' => 'recurring'|'installment'|'unscheduled', 'variableAmount' => bool?].
     * The network is required for recurring / installment (Mastercard reason 7/8/9).
     * @return array{type:string,brand:?string,firstRecurringPayment:bool,reason:?string,scenario:string,pendingConfirmation?:string[]}
     */
    public static function agreementCell(mixed $agreement, ?string $brand = null, ?string $cardType = null): array
    {
        if (!is_array($agreement)) {
            throw new VbRuleException('cit_agreement', ['R5: agreement must be an array [type, variableAmount?]']);
        }
        $given = $agreement['type'] ?? $agreement['kind'] ?? null;
        $type = strtolower((string) $given);
        if (!in_array($type, self::AGREEMENT_TYPES, true)) {
            throw new VbRuleException('cit_agreement', ["R5: unknown agreement type \"$given\": one of " . implode(', ', self::AGREEMENT_TYPES)]);
        }
        if (!empty($agreement['variableAmount']) && $type !== 'recurring') {
            throw new VbRuleException('cit_agreement', ['R5: variableAmount applies to a recurring agreement only (Mastercard standing order, reason 8)']);
        }
        $key = $type === 'recurring' && !empty($agreement['variableAmount']) ? 'recurring_variable' : $type;
        $row = self::storedCredentials()['citAgreement'][$key];
        $b = self::networkOfInput($agreement['brand'] ?? $brand, $agreement['cardType'] ?? $cardType);
        if ($b === null && $row['visa']['scenario'] !== $row['mastercard']['scenario']) {
            throw new VbRuleException("cit_agreement_$key", ["R5: agreement \"$key\": pass cardType (\"001\"/\"002\", tokens.cardType) or brand - "
                . 'Mastercard needs reason 7 (subscription), 8 (standing order) or 9 (installment) on the CIT that establishes it']);
        }
        $c = $row[$b ?? 'visa'];
        return ['type' => $key, 'brand' => $b, 'firstRecurringPayment' => ($c['firstRecurringPayment'] ?? false) === true,
            'reason' => $c['reason'] ?? null, 'scenario' => $c['scenario']]
            + (isset($c['pendingConfirmation']) ? ['pendingConfirmation' => $c['pendingConfirmation']] : []);
    }

    /** initiator (+ recurringOptions) of a customer-initiated payment with the token: subsequent CIT or agreement CIT. */
    private static function citStoredCredential(?array $agreementInfo): array
    {
        if ($agreementInfo === null) {
            return ['authorizationOptions' => ['initiator' => ['type' => 'customer', 'storedCredentialUsed' => true]]];
        }
        $initiator = ['type' => 'customer', 'credentialStoredOnFile' => true];                  // C4: without storedCredentialUsed
        if ($agreementInfo['reason']) {
            $initiator['merchantInitiatedTransaction'] = ['reason' => $agreementInfo['reason']];
        }
        return ['authorizationOptions' => ['initiator' => $initiator]]
            + ($agreementInfo['firstRecurringPayment'] ? ['recurringOptions' => ['firstRecurringPayment' => true]] : []);
    }

    /** httpBrowserJavaEnabled / httpBrowserJavaScriptEnabled are Boolean (API field reference). Same values as Node's toBool. */
    public static function toBool(mixed $v): bool
    {
        return in_array($v, [true, 'Y', 'y', 'true', 1, '1'], true);
    }

    // ------------------------------------------------------------ amounts
    /**
     * Amount as CyberSource expects it ("1250.00"). Accepts an int/float or a string of digits with at most 2 decimals;
     * formats by string (no float rounding). Rejects '', null, booleans, arrays, signs, exponents and more than 2 decimals.
     * 0.00 is refused unless $allowZero (only the Account Verification is a 0.00 authorization). Same results as Node.js.
     */
    public static function amount(mixed $a, bool $allowZero = false): string
    {
        if (is_int($a)) {
            $s = (string) $a;
        } elseif (is_float($a) && is_finite($a)) {
            $s = self::floatString($a);
        } elseif (is_string($a)) {
            $s = $a;
        } else {
            throw new \InvalidArgumentException('Invalid amount ' . json_encode($a) . ': a number or a string such as "1250.00" is required');
        }
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $s)) {
            throw new \InvalidArgumentException('Invalid amount ' . json_encode($a) . ': at most 12 digits and 2 decimals, no sign or exponent');
        }
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $int = ltrim($int, '0');
        $out = ($int === '' ? '0' : $int) . '.' . str_pad($frac, 2, '0');
        if (!$allowZero && $out === '0.00') {
            throw new \InvalidArgumentException('Invalid amount 0.00: this operation needs an amount greater than zero');
        }
        return $out;
    }

    /** Shortest decimal text that reads back as the same float (what JavaScript's String(n) prints), independent of php.ini. */
    private static function floatString(float $f): string
    {
        if ($f == 0.0) {
            return '0';
        }
        for ($p = 1; $p <= 17; $p++) {
            $s = str_replace(',', '.', sprintf("%.{$p}G", $f));
            if ((float) $s === $f) {
                break;
            }
        }
        return strtolower($s);
    }

    // ------------------------------------------------------------ merchant transaction ID (timeout reversal)
    /** New merchant transaction ID: 30 random hex characters (120 bits). */
    public static function newTransactionId(): string { return bin2hex(random_bytes(15)); }

    private static function transactionIdOf(?string $v): string
    {
        if ($v === null || $v === '') {
            return self::newTransactionId();
        }
        if (!preg_match(self::TRANSACTION_ID_PATTERN, $v)) {
            throw new \InvalidArgumentException("Invalid transactionId \"$v\": [A-Za-z0-9], at most 30 characters");
        }
        return $v;
    }

    private static function clientRef(string $code, ?string $transactionId): array
    {
        return ['code' => $code, 'transactionId' => self::transactionIdOf($transactionId)];
    }

    // ------------------------------------------------------------ billing data
    /** Card networks accept ASCII only (Visa Platform Connect replaces other characters). */
    public static function toAscii(mixed $v): mixed
    {
        if (!is_string($v)) {
            return $v;
        }
        if (class_exists(\Normalizer::class)) {
            $v = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($v, \Normalizer::FORM_D));
        } else {
            $v = strtr($v, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
                'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T', 'é' => 'e', 'è' => 'e', 'ü' => 'u', 'ö' => 'o']);
        }
        return preg_replace('/[^\x20-\x7E]/', '', $v);
    }

    /** Only the known billTo fields, as ASCII strings (numbers converted, other types dropped); address lines <= 60 (split). */
    public static function normalizeBillTo(?array $b): array
    {
        $out = [];
        foreach (self::BILL_TO_FIELDS as $k) {
            $v = $b[$k] ?? null;
            if (is_string($v)) {
                $out[$k] = self::toAscii($v);
            } elseif (is_int($v) || (is_float($v) && is_finite($v))) {
                $out[$k] = is_int($v) ? (string) $v : self::floatString($v);
            }
        }
        if (isset($out['address1']) && strlen($out['address1']) > 60) {
            $sp = strrpos(substr($out['address1'], 0, 61), ' ');
            $cut = ($sp !== false && $sp > 20) ? $sp : 60;
            $out['address2'] = substr(trim(trim(substr($out['address1'], $cut)) . ' ' . ($out['address2'] ?? '')), 0, 60);
            $out['address1'] = trim(substr($out['address1'], 0, $cut));
        }
        foreach (['firstName' => 60, 'lastName' => 60, 'company' => 60, 'address2' => 60, 'locality' => 50, 'email' => 255] as $k => $max) {
            if (isset($out[$k]) && strlen($out[$k]) > $max) {
                $out[$k] = substr($out[$k], 0, $max);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ sessions
    public static function microformSession(string $targetOrigin, array $allowedCardNetworks = ['VISA', 'MASTERCARD']): array
    {
        return [
            'clientVersion' => 'v2',
            'targetOrigins' => [$targetOrigin],
            'allowedCardNetworks' => $allowedCardNetworks,
            'allowedPaymentTypes' => ['CARD'],
            'transientTokenResponseOptions' => ['includeCardPrefix' => false],
        ];
    }

    /** UC session 1: 0.00, AUTH, no 3DS (R6), token creation (R2). $existingCustomerId: add the card under that customer. */
    public static function ucSessionAccountVerification(string $targetOrigin, string $reference, string $currency, ?array $billTo,
        ?string $existingCustomerId = null, string $locale = 'ro_RO'): array
    {
        $s = [
            'targetOrigins' => [$targetOrigin],
            'country' => 'MD',
            'locale' => $locale,
            'allowedCardNetworks' => ['VISA', 'MASTERCARD'],
            'allowedPaymentTypes' => $existingCustomerId ? ['PANENTRY', 'TMS_TOKEN'] : ['PANENTRY'],
        ];
        if ($existingCustomerId) {
            $s['paymentConfigurations'] = ['TMS_TOKEN' => ['customer' => ['id' => $existingCustomerId]]];
        }
        return $s + [
            'buttonType' => 'ADD_CARD',
            'captureMandate' => ['billingType' => 'FULL', 'requestEmail' => true, 'requestPhone' => false, 'requestShipping' => false,
                'showAcceptedNetworkIcons' => true, 'showConfirmationStep' => false],
            'completeMandate' => [
                'type' => 'AUTH', 'decisionManager' => false, 'consumerAuthentication' => 'NONE',
                'tms' => ['tokenCreate' => true, 'tokenTypes' => $existingCustomerId ? ['paymentInstrument', 'instrumentIdentifier']
                    : ['customer', 'paymentInstrument', 'instrumentIdentifier']],
            ],
            'transientTokenResponseOptions' => ['includeCardPrefix' => false],
            'data' => [
                'clientReferenceInformation' => ['code' => $reference],
                'orderInformation' => ['amountDetails' => ['totalAmount' => '0.00', 'currency' => $currency], 'billTo' => self::normalizeBillTo($billTo)],
                'processingInformation' => ['authorizationOptions' => ['initiator' => ['credentialStoredOnFile' => true]]],
            ],
        ];
    }

    /**
     * UC session 2: pay with the TMS token, 3-D Secure orchestrated by Unified Checkout (R3, R4).
     * $agreement: this payment establishes a recurring / installment / unscheduled agreement (credentialStoredOnFile + Mastercard reason).
     */
    public static function ucSessionPayWithToken(string $targetOrigin, string $reference, string $customerId, mixed $amount,
        string $currency, ?array $billTo, string $locale = 'ro_RO', bool $capture = false, ?array $agreement = null, ?string $brand = null,
        ?string $cardType = null): array
    {
        $a = $agreement !== null ? self::agreementCell($agreement, $brand, $cardType) : null;
        $data = [
            'clientReferenceInformation' => ['code' => $reference],
            'orderInformation' => ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency], 'billTo' => self::normalizeBillTo($billTo)],
        ];
        if ($a !== null) {
            $data['processingInformation'] = ['authorizationOptions' => ['initiator' => ['credentialStoredOnFile' => true]
                + ($a['reason'] ? ['merchantInitiatedTransaction' => ['reason' => $a['reason']]] : [])]];
        }
        return [
            'targetOrigins' => [$targetOrigin],
            'country' => 'MD',
            'locale' => $locale,
            'allowedCardNetworks' => ['VISA', 'MASTERCARD'],
            'allowedPaymentTypes' => ['TMS_TOKEN'],
            'paymentConfigurations' => ['TMS_TOKEN' => ['customer' => ['id' => $customerId]]],   // one object (API specification)
            'buttonType' => 'PAY',
            'captureMandate' => ['billingType' => 'NONE', 'requestEmail' => false, 'requestPhone' => false, 'requestShipping' => false, 'showConfirmationStep' => true],
            'completeMandate' => ['type' => $capture ? 'CAPTURE' : 'AUTH', 'decisionManager' => false, 'consumerAuthentication' => '3DS'],
            'data' => $data,
        ];
    }

    /** Payment methods of a direct-payment session (bank scenario A): card entry, Apple Pay, Google Pay. */
    public const UC_DIRECT_PAYMENT_TYPES = ['PANENTRY', 'APPLEPAY', 'GOOGLEPAY'];

    /**
     * UC direct payment (bank scenario A, rules uc_session_direct): one session, amount > 0, 3-D Secure, NOTHING stored
     * (no tms.tokenCreate, no TMS_TOKEN, no stored-credential indicators). $capture: completeMandate CAPTURE = sale, released
     * with voidPayment (never a reversal); default AUTH = captured on shipment. billTo is sent only when it has fields.
     */
    public static function ucSessionDirect(string $targetOrigin, string $reference, mixed $amount, string $currency, ?array $billTo = null,
        string $locale = 'ro_RO', ?array $allowedPaymentTypes = null, bool $capture = false): array
    {
        $bill = self::normalizeBillTo($billTo);
        $order = ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency]];
        if ($bill) {
            $order['billTo'] = $bill;
        }
        return [
            'targetOrigins' => [$targetOrigin],
            'country' => 'MD',
            'locale' => $locale,
            'allowedCardNetworks' => ['VISA', 'MASTERCARD'],
            'allowedPaymentTypes' => array_values($allowedPaymentTypes ?? self::UC_DIRECT_PAYMENT_TYPES),
            'buttonType' => 'PAY',
            'captureMandate' => ['billingType' => 'FULL', 'requestEmail' => true, 'requestPhone' => false, 'requestShipping' => false,
                'showAcceptedNetworkIcons' => true, 'showConfirmationStep' => false],
            'completeMandate' => ['type' => $capture ? 'CAPTURE' : 'AUTH', 'decisionManager' => false, 'consumerAuthentication' => '3DS'],
            'data' => [
                'clientReferenceInformation' => ['code' => $reference],
                'orderInformation' => $order,
            ],
        ];
    }

    /** The TMS token that selects the card (R3): payment instrument of a specific card, or the Customer (default card). */
    public static function tokenRef(?string $customerId, ?string $paymentInstrumentId): array
    {
        if ($paymentInstrumentId) {
            return ['paymentInstrument' => ['id' => $paymentInstrumentId]];
        }
        if ($customerId) {
            return ['customer' => ['id' => $customerId]];
        }
        throw new \InvalidArgumentException('A TMS token (customerId or paymentInstrumentId) is required (R3)');
    }

    // ------------------------------------------------------------ Account Verification
    public static function accountVerification(string $reference, string $transientToken, string $currency, ?array $billTo,
        ?string $existingCustomerId = null, ?string $transactionId = null): array
    {
        $b = [
            'clientReferenceInformation' => self::clientRef($reference, $transactionId),
            'processingInformation' => [
                'commerceIndicator' => 'internet',
                'actionList' => ['TOKEN_CREATE'],
                'actionTokenTypes' => $existingCustomerId ? ['paymentInstrument', 'instrumentIdentifier'] : ['customer', 'paymentInstrument', 'instrumentIdentifier'],
                'authorizationOptions' => [
                    'cardVerificationIndicator' => true,
                    'initiator' => ['type' => 'customer', 'credentialStoredOnFile' => true],
                ],
            ],
        ];
        if ($existingCustomerId) {
            $b['paymentInformation'] = ['customer' => ['id' => $existingCustomerId]];
            $b['tokenInformation'] = ['transientTokenJwt' => $transientToken, 'paymentInstrument' => ['default' => true]];
        } else {
            $b['tokenInformation'] = ['transientTokenJwt' => $transientToken];
        }
        $b['orderInformation'] = ['amountDetails' => ['totalAmount' => '0.00', 'currency' => $currency], 'billTo' => self::normalizeBillTo($billTo)];
        return $b;
    }

    private const THREE_DS_RESULT_FIELDS = ['cavv', 'eciRaw', 'xid', 'directoryServerTransactionId', 'paSpecificationVersion'];

    /**
     * Alternate Account Verification: 0.00 + TOKEN_CREATE combined with 3-D Secure already completed via
     * POST /risk/v1/authentication-setups + POST /risk/v1/authentications, instead of deferring 3-D Secure to the
     * first paid CIT (accountVerification() above, the default). Confirmed by CyberSource support on a bank-certified
     * sandbox test (merchant test_498000049807145, 2026-11-04/05, package IN/microform/bank-logs-2026-11-04-05) -
     * Visa only so far (commerceIndicator "vbv"); no Mastercard equivalent confirmed yet (R6 alternate flow, scenario
     * account_verification_3ds_combined). $threeDs = the result of that prior authentication.
     */
    public static function accountVerificationWith3ds(string $reference, string $transientToken, string $currency, ?array $billTo,
        ?string $existingCustomerId, ?string $transactionId, array $threeDs): array
    {
        $missing = array_values(array_filter(self::THREE_DS_RESULT_FIELDS, static fn ($f) => !array_key_exists($f, $threeDs) || $threeDs[$f] === null || $threeDs[$f] === ''));
        if ($missing) {
            throw new \InvalidArgumentException('3DS result fields missing: ' . implode(', ', $missing));
        }
        $b = self::accountVerification($reference, $transientToken, $currency, $billTo, $existingCustomerId, $transactionId);
        $b['processingInformation']['commerceIndicator'] = 'vbv';
        $b['consumerAuthenticationInformation'] = array_intersect_key($threeDs, array_flip(self::THREE_DS_RESULT_FIELDS));
        return $b;
    }

    // ------------------------------------------------------------ 3-D Secure + CIT
    public static function payerAuthSetup(string $reference, string $customerId): array
    {
        return ['clientReferenceInformation' => ['code' => $reference], 'paymentInformation' => ['customer' => ['customerId' => $customerId]]];
    }

    /**
     * CIT authorization with the token, enrollment check combined with authorization (R3, R4).
     * $agreement ['type' => 'recurring'|'installment'|'unscheduled', 'variableAmount' => bool?] (+ $brand / $cardType): this paid
     * CIT ESTABLISHES the agreement - credentialStoredOnFile true, firstRecurringPayment (recurring), Mastercard reason 7/8/9;
     * its network transaction ID is the previousTransactionId of every MIT of that agreement. commerceIndicator is left to
     * payer authentication (C12).
     */
    public static function citAuthorizeWith3ds(string $reference, ?string $customerId, ?string $paymentInstrumentId, mixed $amount,
        string $currency, ?array $billTo, array $device, string $referenceId, string $returnUrl, bool $capture = false, ?string $transactionId = null,
        ?array $agreement = null, ?string $brand = null, ?string $cardType = null): array
    {
        $missing = array_values(array_filter(self::BROWSER_FIELDS, static fn ($f) => !array_key_exists($f, $device) || $device[$f] === null || $device[$f] === ''));
        if ($missing) {
            throw new \InvalidArgumentException('3DS browser fields missing: ' . implode(', ', $missing));
        }
        $a = $agreement !== null ? self::agreementCell($agreement, $brand, $cardType) : null;
        $dev = [];
        foreach (self::BROWSER_FIELDS as $f) {
            $dev[$f] = in_array($f, ['httpBrowserJavaEnabled', 'httpBrowserJavaScriptEnabled'], true) ? self::toBool($device[$f]) : (string) $device[$f];
        }
        return [
            'clientReferenceInformation' => self::clientRef($reference, $transactionId),
            'processingInformation' => ['capture' => $capture, 'actionList' => ['CONSUMER_AUTHENTICATION']] + self::citStoredCredential($a),
            'paymentInformation' => self::tokenRef($customerId, $paymentInstrumentId),
            'orderInformation' => ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency], 'billTo' => self::normalizeBillTo($billTo)],
            'deviceInformation' => $dev,
            'consumerAuthenticationInformation' => ['referenceId' => $referenceId, 'returnUrl' => $returnUrl, 'deviceChannel' => self::DEVICE_CHANNEL, 'acsWindowSize' => '02'],
        ];
    }

    /** After the challenge: validation + authorization (a new authorization request: its own transaction ID). Same agreement as the first request. */
    public static function citValidateAndAuthorize(string $reference, ?string $customerId, ?string $paymentInstrumentId, mixed $amount,
        string $currency, string $authenticationTransactionId, bool $capture = false, ?string $transactionId = null,
        ?array $agreement = null, ?string $brand = null, ?string $cardType = null): array
    {
        $a = $agreement !== null ? self::agreementCell($agreement, $brand, $cardType) : null;
        return [
            'clientReferenceInformation' => self::clientRef($reference, $transactionId),
            'processingInformation' => ['capture' => $capture, 'actionList' => ['VALIDATE_CONSUMER_AUTHENTICATION']] + self::citStoredCredential($a),
            'paymentInformation' => self::tokenRef($customerId, $paymentInstrumentId),
            'orderInformation' => ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency]],
            'consumerAuthenticationInformation' => ['authenticationTransactionId' => $authenticationTransactionId],
        ];
    }

    // ------------------------------------------------------------ MIT (R5)
    /**
     * Merchant-initiated transaction with the TMS token (R5), driven by policy.storedCredentials.mit:
     *   kind recurring (fixed) | recurring_variable | installment | unscheduled | topup | resubmission | delayed | reauthorization
     *   | noshow (incremental: use incrementalAuthorization). Mastercard 7/8/9/10, industry practice 1-4; Visa recurring /
     *   installment / UCOF without reason. $previousTransactionId = the 15-digit NTID of the transaction that ESTABLISHED THE
     *   AGREEMENT (stored per agreement). No CVV, no 3-D Secure data, no credentialStoredOnFile.
     * $brand: 'visa' | 'mastercard' (or pass $cardType '001' / '002'). Throws VbRuleException (nothing built) for an unknown
     * kind, a missing network, an unsupported cell or a missing / non-15-digit NTID.
     */
    public static function mit(string $reference, ?string $customerId, ?string $paymentInstrumentId, mixed $amount, string $currency,
        ?string $brand, string $kind, ?string $previousTransactionId, bool $capture = true, ?string $transactionId = null, ?string $cardType = null): array
    {
        $cell = self::mitCell($kind, self::networkOfInput($brand, $cardType) ?? $brand);
        if ($previousTransactionId === null || $previousTransactionId === '') {
            throw new VbRuleException($cell['scenario'], ['R5: previousTransactionId missing: pass the networkTransactionId of the transaction that established '
                . 'the agreement (agreement CIT or Account Verification), stored per agreement (frequent mistake: MIT without NTID)']);
        }
        if (!preg_match(self::ntidPattern(), $previousTransactionId)) {
            throw new VbRuleException($cell['scenario'], ["R5: previousTransactionId \"$previousTransactionId\" is not a 15-digit network transaction ID: use "
                . 'processorInformation.networkTransactionId / transactionId of the agreement transaction, not the payment id (22 digits)']);
        }
        $mi = ['previousTransactionId' => $previousTransactionId];
        if ($cell['reason'] !== null) {
            $mi['reason'] = $cell['reason'];
        }
        return [
            'clientReferenceInformation' => self::clientRef($reference, $transactionId),
            'processingInformation' => [
                'capture' => $capture,
                'commerceIndicator' => $cell['commerceIndicator'],
                'authorizationOptions' => ['initiator' => ['type' => 'merchant', 'storedCredentialUsed' => true, 'merchantInitiatedTransaction' => $mi]],
            ],
            'paymentInformation' => self::tokenRef($customerId, $paymentInstrumentId) + ['tokenizedCard' => ['transactionType' => '3']],
            'orderInformation' => ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency]],
        ];
    }

    /**
     * Incremental authorization (PATCH /pts/v2/payments/{id}) of a PRE-authorization: the amount ADDED, same currency, at
     * most 100 per authorization (Payments guide). Availability / eligible MCCs: to be confirmed by Victoriabank (C9).
     */
    public static function incrementalAuthorization(string $reference, mixed $additionalAmount, string $currency, ?string $transactionId = null): array
    {
        return [
            'clientReferenceInformation' => self::clientRef($reference, $transactionId),
            'orderInformation' => ['amountDetails' => ['additionalAmount' => self::amount($additionalAmount), 'currency' => $currency]],
        ];
    }

    // ------------------------------------------------------------ TMS: card update
    /**
     * Body of PATCH /tms/v2/customers/{customerId}/payment-instruments/{id}: new expiry date (card re-issued with the same
     * number), billing address and / or default flag. A NEW card number never passes through your server: capture it with
     * Microform / Unified Checkout and run a new Account Verification (R1, R2).
     */
    public static function updatePaymentInstrument(string|int|null $expirationMonth = null, string|int|null $expirationYear = null, ?array $billTo = null,
        ?bool $isDefault = null): array
    {
        $body = [];
        if ($expirationMonth !== null || $expirationYear !== null) {
            $m = str_pad((string) $expirationMonth, 2, '0', STR_PAD_LEFT);
            $y = (string) $expirationYear;
            if (!preg_match('/^(0[1-9]|1[0-2])$/D', $m) || !preg_match('/^20\d{2}$/D', $y)) {
                throw new \InvalidArgumentException("Invalid expiry $expirationMonth/$expirationYear: month 1-12 and a 4-digit year");
            }
            $body['card'] = ['expirationMonth' => $m, 'expirationYear' => $y];
        }
        if ($billTo !== null) {
            $body['billTo'] = self::normalizeBillTo($billTo);
        }
        if ($isDefault !== null) {
            $body['default'] = $isDefault;
        }
        if (!$body) {
            throw new \InvalidArgumentException('Nothing to update: expirationMonth/expirationYear, billTo or isDefault');
        }
        return $body;
    }

    /** The NTID TMS keeps on an Instrument Identifier (ONE per card, not per agreement - prefer the NTID you stored per agreement). */
    public static function ntidOfInstrumentIdentifier(array|string|null $data): ?string
    {
        $v = is_array($data) ? ($data['processingInformation']['authorizationOptions']['initiator']['merchantInitiatedTransaction']['previousTransactionId'] ?? null) : null;
        return $v === null ? null : (string) $v;
    }

    // ------------------------------------------------------------ follow-on operations
    public static function capture(string $reference, mixed $amount, string $currency): array
    {
        return ['clientReferenceInformation' => ['code' => $reference], 'orderInformation' => ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency]]];
    }

    public static function reversal(string $reference, mixed $authorizedAmount, string $currency, string $reason = 'Order canceled'): array
    {
        return ['clientReferenceInformation' => ['code' => $reference],
            'reversalInformation' => ['amountDetails' => ['totalAmount' => self::amount($authorizedAmount), 'currency' => $currency], 'reason' => $reason]];
    }

    /**
     * Timeout reversal (POST /pts/v2/reversals): reverses the authorization sent with this merchant transaction ID when its
     * response never arrived. Amount = the authorized amount. Wait 60 seconds after the timeout (Payments guide).
     */
    public static function timeoutReversal(string $reference, string $transactionId, mixed $amount, string $currency, string $reason = 'Timeout'): array
    {
        if (!preg_match(self::TRANSACTION_ID_PATTERN, $transactionId)) {
            throw new \InvalidArgumentException('A timeout reversal needs the transactionId of the original authorization');
        }
        return ['clientReferenceInformation' => ['code' => $reference, 'transactionId' => $transactionId],
            'reversalInformation' => ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency], 'reason' => $reason]];
    }

    public static function refund(string $reference, mixed $amount, string $currency): array
    {
        return ['clientReferenceInformation' => ['code' => $reference], 'orderInformation' => ['amountDetails' => ['totalAmount' => self::amount($amount), 'currency' => $currency]]];
    }

    public static function voidRequest(string $reference): array { return ['clientReferenceInformation' => ['code' => $reference]]; }

    /** Transactions with this clientReferenceInformation.code; optional window and limit (default: yesterday to tomorrow, 10). */
    public static function searchByReference(string $reference, string $from = 'NOW/DAY-1DAY', string $to = 'NOW/DAY+1DAY', int $limit = 10): array
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,50}$/D', $reference)) {
            throw new \InvalidArgumentException("Invalid reference \"$reference\": [A-Za-z0-9-], at most 50 characters");
        }
        foreach ([$from, $to] as $t) {
            if (!preg_match('#^(NOW(/DAY)?([+-]\d{1,4}(DAY|DAYS|HOUR|HOURS))?(/DAY)?|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?Z)$#D', $t)) {
                throw new \InvalidArgumentException("Invalid search time \"$t\": NOW/DAY-7DAYS or 2026-10-01T00:00:00Z");
            }
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('limit must be an integer 1-1000');
        }
        return ['save' => false, 'name' => 'status-check', 'timezone' => 'Europe/Chisinau',
            'query' => "clientReferenceInformation.code:$reference AND submitTimeUtc:[$from TO $to}",
            'offset' => 0, 'limit' => $limit, 'sort' => 'id:asc,submitTimeUtc:asc'];
    }

    // ------------------------------------------------------------ response interpretation
    /** Card network from the CyberSource card type code. */
    public static function brandOf(?string $cardType): ?string
    {
        return ['001' => 'visa', '002' => 'mastercard'][$cardType ?? ''] ?? null;
    }

    /** Card network of a payment response (card type, then 3DS indicator, then Mastercard-only fields), or null. */
    public static function networkOf(array|string|null $r): ?string
    {
        $r = is_array($r) ? $r : [];
        $p = $r['paymentInformation'] ?? [];
        $c = $r['consumerAuthenticationInformation'] ?? [];
        $byType = self::brandOf($r['paymentAccountInformation']['card']['type'] ?? $p['card']['type'] ?? $p['tokenizedCard']['type'] ?? null);
        if ($byType) {
            return $byType;
        }
        $ind = strtolower((string) ($c['indicator'] ?? $c['ecommerceIndicator'] ?? $r['processingInformation']['commerceIndicator'] ?? ''));
        if (str_starts_with($ind, 'vbv')) {
            return 'visa';
        }
        if (str_starts_with($ind, 'spa')) {
            return 'mastercard';
        }
        if (!empty($c['ucafAuthenticationData']) || (array_key_exists('ucafCollectionIndicator', $c) && $c['ucafCollectionIndicator'] !== null)) {
            return 'mastercard';
        }
        return null;
    }

    /**
     * 3-D Secure evidence (R4), per network (policy.threeDs in the rule file):
     *   Visa ECI 05 authenticated, 06 attempted, 07 none; Mastercard ECI 02 authenticated, 01 attempted, 00/06/07 NOT authenticated;
     *   Mastercard response ucafCollectionIndicator "0" = downgraded -> not full 3DS.
     * full3ds = cryptogram + authenticated, or attempted when acceptAttempted (default: the rule file's policy).
     * @param array{acceptAttempted?:bool,brand?:string} $opts
     */
    public static function full3dsEvidence(array|string|null $r, array $opts = []): array
    {
        $pol = Rules::policy()['threeDs'] ?? [];
        $acceptAttempted = $opts['acceptAttempted'] ?? $pol['acceptAttempted'] ?? true;
        $c = is_array($r) ? ($r['consumerAuthenticationInformation'] ?? []) : [];
        $eci = $c['eciRaw'] ?? $c['eci'] ?? null;
        $cryptogram = $c['cavv'] ?? $c['ucafAuthenticationData'] ?? null;
        $brand = $opts['brand'] ?? self::networkOf($r);
        $table = $pol['eci'] ?? ['visa' => ['authenticated' => ['05'], 'attempted' => ['06']], 'mastercard' => ['authenticated' => ['02'], 'attempted' => ['01']]];
        if ($brand !== null && isset($table[$brand])) {
            $authenticated = in_array($eci, $table[$brand]['authenticated'], true);
            $attempted = in_array($eci, $table[$brand]['attempted'], true);
        } else {
            $authenticated = in_array($eci, ['05', '02'], true);
            $attempted = $eci === '01';
        }
        $uci = $c['ucafCollectionIndicator'] ?? null;
        $downgraded = $brand === 'mastercard' && $uci !== null && (string) $uci === (string) ($pol['mastercardDowngradedUcafCollectionIndicator'] ?? '0');
        $hasCryptogram = !empty($cryptogram);
        return ['eci' => $eci, 'brand' => $brand, 'hasCryptogram' => $hasCryptogram, 'xid' => $c['xid'] ?? null,
            'authenticated' => $authenticated, 'attempted' => $attempted, 'downgraded' => $downgraded, 'acceptAttempted' => (bool) $acceptAttempted,
            'full3ds' => $hasCryptogram && !$downgraded && ($authenticated || ($attempted && $acceptAttempted))];
    }

    /**
     * Next step for your order state machine. UNKNOWN = every 5xx, a reason in UNKNOWN_REASONS (including HTTP 400
     * DUPLICATE_REQUEST) or a status this SDK does not know: reconcile, never success or failure.
     * AUTHORIZED_RISK_DECLINED = DECLINED with riskDeclined true (an authorization exists: release it).
     */
    public static function interpretPayment(int $httpStatus, array|string|null $data): array
    {
        $d = is_array($data) ? $data : [];
        $s = $d['status'] ?? null;
        $reason = $d['reason'] ?? $d['errorInformation']['reason'] ?? null;
        if ($s === 'PENDING_AUTHENTICATION') return ['next' => 'CHALLENGE', 'challenge' => $d['consumerAuthenticationInformation'] ?? []];
        if ($s === 'AUTHORIZED') return ['next' => 'APPROVED'];
        if ($s === 'PARTIAL_AUTHORIZED') return ['next' => 'PARTIAL'];
        if ($s === 'AUTHORIZED_PENDING_REVIEW' || $s === 'PENDING_REVIEW') return ['next' => 'REVIEW'];
        if ($s === 'AUTHENTICATION_FAILED') return ['next' => 'AUTH_FAILED', 'reason' => $d['errorInformation']['reason'] ?? null];
        if ($s === 'AUTHORIZED_RISK_DECLINED') return ['next' => 'DECLINED', 'reason' => $d['errorInformation']['reason'] ?? $s, 'riskDeclined' => true];
        if ($s === 'DECLINED') return ['next' => 'DECLINED', 'reason' => $d['errorInformation']['reason'] ?? null];
        if (in_array($reason, self::UNKNOWN_REASONS, true) || $httpStatus >= 500) {
            return ['next' => 'UNKNOWN', 'reason' => $reason];
        }
        // INVALID_REQUEST is a final "fix the request" answer on HTTP 400 and on HTTP 201 alike (201 status enum)
        if ($httpStatus === 400 || $s === 'INVALID_REQUEST') return ['next' => 'FIX_REQUEST', 'reason' => $reason, 'details' => $d['details'] ?? null];
        return ['next' => 'UNKNOWN'];
    }

    /** What to persist after Account Verification (never the PAN). */
    public static function tokensFrom(array|string|null $r): array
    {
        $r = is_array($r) ? $r : [];
        $t = $r['tokenInformation'] ?? [];
        $p = $r['paymentInformation'] ?? [];
        $proc = $r['processorInformation'] ?? [];
        $nt = isset($proc['networkTransactionId']) && $proc['networkTransactionId'] !== '' ? (string) $proc['networkTransactionId'] : null;
        $tx = isset($proc['transactionId']) && $proc['transactionId'] !== '' ? (string) $proc['transactionId'] : null;
        return [
            'customerId' => $t['customer']['id'] ?? $p['customer']['id'] ?? null,
            'paymentInstrumentId' => $t['paymentInstrument']['id'] ?? $p['paymentInstrument']['id'] ?? null,
            'instrumentIdentifierId' => $t['instrumentIdentifier']['id'] ?? $p['instrumentIdentifier']['id'] ?? null,
            // NTID: documented in processorInformation.transactionId; on Visa Platform Connect networkTransactionId has the same value (C2)
            'networkTransactionId' => $nt ?? $tx,
            // both present and different: report it to the bank (C2); the SDK keeps networkTransactionId
            'ntidMismatch' => $nt !== null && $tx !== null && $nt !== $tx,
            'paymentAccountReference' => $proc['paymentAccountReferenceNumber'] ?? null,
            // card network of the stored card ("001" Visa, "002" Mastercard): drives the MIT reason code
            'cardType' => $r['paymentAccountInformation']['card']['type'] ?? $p['card']['type'] ?? $p['tokenizedCard']['type'] ?? null,
        ];
    }
}
