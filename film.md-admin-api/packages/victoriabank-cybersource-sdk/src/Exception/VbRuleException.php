<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource\Exception;

use Victoriabank\CyberSource\Rules;

/** Thrown before a request is sent when it breaks a Victoriabank rule (R1-R13). Nothing was sent to CyberSource. */
final class VbRuleException extends \InvalidArgumentException
{
    public readonly string $rulesVersion;
    /** sha256 (first 12 hex) of the rule file that was applied. */
    public readonly string $rulesHash;

    /** Scenario name; several validated together are joined with "+" (e.g. "cit_3ds_combined+cit_agreement_recurring_visa"). */
    public readonly string $scenario;

    /** @param string|string[] $scenario @param string[] $violations */
    public function __construct(string|array $scenario, public readonly array $violations)
    {
        $this->scenario = is_array($scenario) ? implode('+', $scenario) : $scenario;
        $scenario = $this->scenario;
        $this->rulesVersion = Rules::version();
        $this->rulesHash = Rules::hash();
        parent::__construct("Request violates Victoriabank rules {$this->rulesVersion} (sha256 {$this->rulesHash}) ($scenario): " . implode('; ', $violations));
    }
}
