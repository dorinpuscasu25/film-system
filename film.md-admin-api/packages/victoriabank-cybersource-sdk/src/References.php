<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

/**
 * Reference scheme (R10): one clientReferenceInformation.code per request, at most 50 characters.
 *   ORD-1001 first attempt, ORD-1001-A2 ... later attempts; -CP1 capture, -RV1 reversal, -RF1/-RF2 refunds, -VD1 void.
 */
final class References
{
    public function __construct(public readonly string $order)
    {
        self::check($order);
    }

    private static function check(string $ref): string
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,50}$/', $ref)) {
            throw new \InvalidArgumentException("Invalid reference \"$ref\": [A-Za-z0-9-], at most 50 characters");
        }
        return $ref;
    }

    public function attempt(int $n): string { return self::check($n === 1 ? $this->order : "{$this->order}-A$n"); }
    public function capture(int $n): string { return self::check("{$this->order}-CP$n"); }
    public function reversal(int $n): string { return self::check("{$this->order}-RV$n"); }
    public function refund(int $n): string { return self::check("{$this->order}-RF$n"); }
    public function void(int $n): string { return self::check("{$this->order}-VD$n"); }

    /** The order reference behind any of the references above. */
    public static function orderOf(string $ref): string
    {
        return (string) preg_replace('/-(A|CP|RV|RF|VD)\d+$/', '', $ref);
    }
}
