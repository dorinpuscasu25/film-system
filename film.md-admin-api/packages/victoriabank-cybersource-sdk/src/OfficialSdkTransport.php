<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

use Victoriabank\CyberSource\Exception\UnknownStatusException;

/**
 * Transport over the OFFICIAL CyberSource PHP SDK (composer: cybersource/rest-client-php, needs ext-gmp). Use it in
 * production: the official SDK signs every request (JWT v2) and applies Message-Level Encryption per its configuration.
 *
 *   $merchant = new \CyberSource\Authentication\Core\MerchantConfiguration();
 *   $merchant->setAuthenticationType('JWT');  $merchant->setJwtKeyType('SHARED_SECRET');
 *   $merchant->setMerchantID(...); $merchant->setApiKeyID(...); $merchant->setSecretKey(...);
 *   $merchant->setRunEnvironment('apitest.cybersource.com');   // + MLE settings of your account
 *   $config = (new \CyberSource\Configuration())->setHost($merchant->getHost());
 *   $vb = new VbCyberSource(new OfficialSdkTransport(new \CyberSource\ApiClient($config, $merchant)));
 *
 * The official SDK works with request MODEL objects: the body is converted into the model of the operation. The tests
 * check that every canonical VB request survives this conversion unchanged (no field is dropped). Responses: the RAW
 * (MLE-decrypted) body is returned, not the response model (A15) - same contract as the Node.js OfficialSdkTransport.
 * Errors: timeouts and every HTTP 5xx throw UnknownStatusException; other HTTP errors are returned as [status, body].
 */
final class OfficialSdkTransport implements Transport
{
    /** [method, path pattern, API class, method, request model, argument order] */
    private const ROUTES = [
        ['POST', '#^/microform/v2/sessions$#', 'MicroformIntegrationApi', 'generateCaptureContextWithHttpInfo', 'GenerateCaptureContextRequest', 'b'],
        ['POST', '#^/uc/v1/sessions$#', 'UnifiedCheckoutV1CaptureContextApi', 'generateUnifiedCheckoutV1CaptureContextWithHttpInfo', 'GenerateUnifiedCheckoutV1CaptureContextRequest', 'b'],
        ['POST', '#^/pts/v2/payments$#', 'PaymentsApi', 'createPaymentWithHttpInfo', 'CreatePaymentRequest', 'b'],
        ['POST', '#^/pts/v2/payments/([^/]+)/captures$#', 'CaptureApi', 'capturePaymentWithHttpInfo', 'CapturePaymentRequest', 'b,id'],
        ['POST', '#^/pts/v2/payments/([^/]+)/reversals$#', 'ReversalApi', 'authReversalWithHttpInfo', 'AuthReversalRequest', 'id,b'],
        ['POST', '#^/pts/v2/payments/([^/]+)/refunds$#', 'RefundApi', 'refundPaymentWithHttpInfo', 'RefundPaymentRequest', 'b,id'],
        ['POST', '#^/pts/v2/captures/([^/]+)/refunds$#', 'RefundApi', 'refundCaptureWithHttpInfo', 'RefundCaptureRequest', 'b,id'],
        ['POST', '#^/pts/v2/captures/([^/]+)/voids$#', 'VoidApi', 'voidCaptureWithHttpInfo', 'VoidCaptureRequest', 'b,id'],
        ['POST', '#^/pts/v2/refunds/([^/]+)/voids$#', 'VoidApi', 'voidRefundWithHttpInfo', 'VoidRefundRequest', 'b,id'],
        ['POST', '#^/risk/v1/authentication-setups$#', 'PayerAuthenticationApi', 'payerAuthSetupWithHttpInfo', 'PayerAuthSetupRequest', 'b'],
        ['POST', '#^/tss/v2/searches$#', 'SearchTransactionsApi', 'createSearchWithHttpInfo', 'CreateSearchRequest', 'b'],
        ['GET', '#^/tss/v2/transactions/([^/]+)$#', 'TransactionDetailsApi', 'getTransactionWithHttpInfo', null, 'id'],
        ['DELETE', '#^/tms/v2/customers/([^/]+)$#', 'CustomerApi', 'deleteCustomerWithHttpInfo', null, 'id'],
        ['DELETE', '#^/tms/v2/customers/([^/]+)/payment-instruments/([^/]+)$#', 'CustomerPaymentInstrumentApi', 'deleteCustomerPaymentInstrumentWithHttpInfo', null, 'id,id2'],
        ['POST', '#^/pts/v2/reversals$#', 'ReversalApi', 'mitReversalWithHttpInfo', 'MitReversalRequest', 'b'],                       // timeout reversal
        ['POST', '#^/pts/v2/payments/([^/]+)/voids$#', 'VoidApi', 'voidPaymentWithHttpInfo', 'VoidPaymentRequest', 'b,id'],              // void of a sale
        // card expiry / billing / default-card update
        ['PATCH', '#^/tms/v2/customers/([^/]+)/payment-instruments/([^/]+)$#', 'CustomerPaymentInstrumentApi', 'patchCustomersPaymentInstrumentWithHttpInfo', 'PatchCustomerPaymentInstrumentRequest', 'id,id2,b'],
        ['PATCH', '#^/pts/v2/payments/([^/]+)$#', 'PaymentsApi', 'incrementAuthWithHttpInfo', 'IncrementAuthRequest', 'id,b'],             // incremental authorization
        ['GET', '#^/tms/v1/instrumentidentifiers/([^/]+)$#', 'InstrumentIdentifierApi', 'getInstrumentIdentifierWithHttpInfo', null, 'id'],
        ['DELETE', '#^/tms/v1/instrumentidentifiers/([^/]+)$#', 'InstrumentIdentifierApi', 'deleteInstrumentIdentifierWithHttpInfo', null, 'id'],
    ];

    /** @return array<int,array{0:string,1:string,2:string,3:string,4:?string,5:string}> the route table (tests, documentation) */
    public static function routes(): array { return self::ROUTES; }

    /** @var array<string,object> */
    private array $apis = [];

    /** The official ApiClient used for every call (a RawCapturingApiClient: keeps the raw response body). */
    private readonly object $apiClient;

    /**
     * @param object $apiClient       \CyberSource\ApiClient configured with your MerchantConfiguration (JWT + MLE); it is re-created as
     *                                a RawCapturingApiClient with the same configuration so the raw response body is returned (A15)
     * @param bool   $allowWithoutMle only for a deliberate exception agreed with the bank
     */
    public function __construct(object $apiClient, bool $allowWithoutMle = false)
    {
        if (!class_exists(\CyberSource\ApiClient::class)) {
            throw new \RuntimeException('Install the official SDK: composer require cybersource/rest-client-php');
        }
        if ($apiClient instanceof \CyberSource\ApiClient && !$apiClient instanceof RawCapturingApiClient) {
            $apiClient = new RawCapturingApiClient($apiClient->getConfig(), $apiClient->merchantConfig);
        }
        $this->apiClient = $apiClient;
        $production = $apiClient->merchantConfig->getRunEnvironment() === 'api.cybersource.com';
        if ($production && !self::mleConfigured($apiClient->merchantConfig) && !$allowWithoutMle) {
            throw new \InvalidArgumentException('Production configuration without Message-Level Encryption (R8): enable request MLE (setEnableRequestMLEForOptionalApisGlobally / setUseMLEGlobally) and the MLE keys');
        }
        if (!$production && !self::mleConfigured($apiClient->merchantConfig)) {
            trigger_error('[vb-cybersource] official SDK transport without MLE: fine for the simulator, test with MLE before production', E_USER_NOTICE);
        }
    }

    /** Request MLE enabled in the official SDK configuration (and not switched off for mandatory APIs). */
    public static function mleConfigured(object $c): bool
    {
        $on = (bool) ($c->getEnableRequestMLEForOptionalApisGlobally() || $c->getUseMLEGlobally() || $c->getMapToControlMLEonAPI());
        return $on && !$c->getDisableRequestMLEForMandatoryApisGlobally();
    }

    public function baseUrl(): string
    {
        return 'https://' . $this->apiClient->merchantConfig->getRunEnvironment();
    }

    /** The official request model of an operation, built from a VB request body. */
    public static function toModel(string $model, array $body): object
    {
        return \CyberSource\ObjectSerializer::deserialize(json_decode(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            '\\CyberSource\\Model\\' . $model);
    }

    /** JSON array of a model (what the official SDK sends, before MLE). */
    public static function toArray(mixed $data): array|string|null
    {
        if ($data === null || is_string($data)) {
            return $data;
        }
        if ($data instanceof \stdClass || is_array($data)) {           // error bodies arrive as plain objects
            return json_decode(json_encode($data), true);
        }
        return json_decode(json_encode(\CyberSource\ObjectSerializer::sanitizeForSerialization($data)), true);
    }

    /** Model class of the request sent to this method + path (null when the operation has no body). */
    public static function modelFor(string $method, string $path): ?string
    {
        foreach (self::ROUTES as [$m, $re, , , $model]) {
            if ($m === $method && preg_match($re, $path)) {
                return $model;
            }
        }
        throw new \InvalidArgumentException("Operation not mapped to the official SDK: $method $path");
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        foreach (self::ROUTES as [$m, $re, $cls, $fn, $model, $order]) {
            if ($m !== $method || !preg_match($re, $path, $mm)) {
                continue;
            }
            $ids = array_map('rawurldecode', array_slice($mm, 1));
            $arg = ['b' => $model ? self::toModel($model, $body ?? []) : null, 'id' => $ids[0] ?? null, 'id2' => $ids[1] ?? null];
            $args = array_map(static fn ($k) => $arg[$k], explode(',', $order));
            $api = $this->apis[$cls] ??= new ('\\CyberSource\\Api\\' . $cls)($this->apiClient);
            try {
                [$data, $status] = $api->$fn(...$args);
                // the raw body (A15, same as the Node.js transport): the SDK's response models drop fields they do not know yet
                $raw = $this->apiClient instanceof RawCapturingApiClient ? $this->apiClient->lastRawBody : null;
                $out = self::toArray($raw ?? $data);
                return [(int) $status, $out === '' || $out === null ? [] : $out];
            } catch (\CyberSource\ApiException $e) {
                $status = (int) $e->getCode();
                $raw = $e->getResponseBody();
                $decoded = is_string($raw) ? (json_decode($raw, true) ?? ['raw' => $raw]) : (self::toArray($raw) ?? []);
                if ($status === 0 || $status >= 500) {                  // C3: timeouts and every 5xx are UNKNOWN
                    throw new UnknownStatusException("CyberSource status unknown ($method $path): {$e->getMessage()}", $status ?: null, is_array($decoded) ? $decoded : null);
                }
                return [$status, $decoded];
            }
        }
        throw new \InvalidArgumentException("Operation not mapped to the official SDK: $method $path");
    }
}
