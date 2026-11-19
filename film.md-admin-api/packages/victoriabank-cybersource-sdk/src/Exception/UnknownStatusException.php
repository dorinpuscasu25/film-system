<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource\Exception;

/**
 * The outcome of a request is UNKNOWN: every HTTP 5xx, a timeout or transport error, or a reason in UNKNOWN_REASONS
 * (including HTTP 400 DUPLICATE_REQUEST). The operation may have been processed. Never resend it: search by
 * clientReferenceInformation.code first (VbCyberSource::searchByReference), rule R10. For an authorization,
 * $transactionId is the merchant transaction ID to use with VbCyberSource::reverseByTransactionId().
 */
final class UnknownStatusException extends \RuntimeException
{
    /** clientReferenceInformation.code of the request (set by VbCyberSource::send). */
    public ?string $reference = null;
    /** clientReferenceInformation.transactionId of an authorization request (set by VbCyberSource::send). */
    public ?string $transactionId = null;

    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly ?array $data = null,
        public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
