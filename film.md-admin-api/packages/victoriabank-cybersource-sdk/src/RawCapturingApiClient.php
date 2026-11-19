<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

/**
 * The official SDK's ApiClient, keeping the raw (MLE-decrypted, JSON-decoded) response body of the last call.
 * The official API methods deserialize the body into response MODELS, which drop fields they do not know yet; the VB
 * transport returns the raw body instead (same as the Node.js transport). Loaded only by OfficialSdkTransport, i.e. only
 * when the official SDK (cybersource/rest-client-php) is installed.
 */
final class RawCapturingApiClient extends \CyberSource\ApiClient
{
    /** @var mixed raw body of the last successful call (stdClass / array / string), null before the first call */
    public mixed $lastRawBody = null;

    public function callApi($resourcePath, $method, $queryParams, $postData, $headerParams, $responseType = null, $endpointPath = null, $isResponseMLEForAPI = false)
    {
        $this->lastRawBody = null;
        $r = parent::callApi($resourcePath, $method, $queryParams, $postData, $headerParams, $responseType, $endpointPath, $isResponseMLEForAPI);
        $this->lastRawBody = $r[0] ?? null;
        return $r;
    }
}
