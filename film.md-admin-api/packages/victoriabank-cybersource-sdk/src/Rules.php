<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

/**
 * Victoriabank rules R1-R13 (rules/vb-cybersource-rules.json - the same file used by the Node.js SDK, the AI kit and the
 * VB simulator) and the validator that applies them to a request body. The file also carries the policy switches
 * (policy.threeDs.acceptAttempted, ECI table) used by Requests::full3dsEvidence().
 */
final class Rules
{
    /** Rule-file format this engine understands. A newer file must come with a newer SDK (no silent partial checks). */
    public const ENGINE = 2;
    private static ?array $rules = null;
    private static string $hash = '';

    private static function data(): array
    {
        if (self::$rules === null) {
            $raw = (string) file_get_contents(__DIR__ . '/../rules/vb-cybersource-rules.json');
            $r = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($r['engine'] ?? 1) > self::ENGINE) {
                throw new \RuntimeException("vb-cybersource-rules.json requires rule engine {$r['engine']}; this SDK supports " . self::ENGINE . '. Update the SDK.');
            }
            self::$hash = substr(hash('sha256', $raw), 0, 12);
            self::$rules = $r;
        }
        return self::$rules;
    }

    public static function version(): string { return (string) self::data()['version']; }

    /** sha256 (first 12 hex) of the exact rule file. */
    public static function hash(): string { self::data(); return self::$hash; }

    /** Policy switches of the rule file (bank decisions). */
    public static function policy(): array { return self::data()['policy'] ?? []; }

    /** @return string[] */
    public static function scenarios(): array
    {
        return array_keys(array_filter(self::data()['scenarios'], static fn ($s) => empty($s['abstract'])));
    }

    private static function get(array $body, string $path): mixed
    {
        $p = str_ends_with($path, '[]') ? substr($path, 0, -2) : $path;
        $cur = $body;
        foreach (explode('.', $p) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return null;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }

    /** String form of a value, as JavaScript's String(v) (booleans "true"/"false"). */
    private static function str(mixed $v): string
    {
        return is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : (string) json_encode($v));
    }

    private static function re(string $pattern): string { return '#' . str_replace('#', '\#', $pattern) . '#u'; }

    private static function check(array $rule, array $body): bool
    {
        if ($rule['rule'] === 'anyOf') {
            foreach ($rule['anyOf'] as $r) {
                if (self::check($r, $body)) {
                    return true;
                }
            }
            return false;
        }
        $v = self::get($body, $rule['path']);
        $has = $v !== null && $v !== '';
        $val = $rule['value'] ?? null;
        return match ($rule['rule']) {
            'exists' => $has,
            'absent' => !$has,
            'equals' => $v === $val,
            'notEquals' => $v !== $val,
            'oneOf' => in_array($v, $val, true),
            'contains' => is_array($v) && in_array($val, $v, true),
            'notContains' => !is_array($v) || !in_array($val, $v, true),
            'allMatch' => is_array($v) && count($v) > 0 && count(array_filter($v, static fn ($x) => preg_match(self::re($val), self::str($x)) === 1)) === count($v),
            'maxLen' => !$has || mb_strlen(self::str($v), 'UTF-8') <= $val,
            'pattern' => !$has || preg_match(self::re($val), self::str($v)) === 1,
            'asciiValues' => !$has || !is_array($v) || count(array_filter($v, static fn ($x) => is_string($x) && preg_match('/[^\x20-\x7E]/', $x))) === 0,
            default => throw new \RuntimeException("Unknown rule type {$rule['rule']}"),
        };
    }

    /** Effective policy: the rule file's policy, optionally overridden (e.g. ['threeDs' => ['acceptAttempted' => false]]). */
    public static function effectivePolicy(?array $override = null): array
    {
        $p = self::policy();
        foreach ($override ?? [] as $k => $v) {
            $p[$k] = array_merge($p[$k] ?? [], $v);
        }
        return $p;
    }

    /** @return array[] rules of a scenario (engine 2: "when" / "whenNot" select rules by a policy switch) */
    public static function rulesFor(string $scenario, ?array $policy = null): array
    {
        $sc = self::data()['scenarios'][$scenario] ?? null;
        if (!$sc || !empty($sc['abstract'])) {
            throw new \InvalidArgumentException("Unknown VB scenario $scenario");
        }
        $pol = self::effectivePolicy($policy);
        $parent = isset($sc['extends']) ? self::data()['scenarios'][$sc['extends']]['rules'] : [];
        $global = ($sc['endpoint'] ?? '') === 'POST /pts/v2/payments' ? self::data()['global'] : [];
        return array_values(array_filter([...$global, ...$parent, ...$sc['rules']], static fn ($r) =>
            (!isset($r['when']) || self::get($pol, $r['when']) === true) && (!isset($r['whenNot']) || self::get($pol, $r['whenNot']) === false)));
    }

    private static function describe(array $r): string
    {
        $one = static fn (array $x) => $x['path'] . ' ' . $x['rule'] . (array_key_exists('value', $x) ? ' ' . json_encode($x['value'], JSON_UNESCAPED_SLASHES) : '');
        $text = $r['rule'] === 'anyOf'
            ? "{$r['id']}: one of [" . implode(' | ', array_map($one, $r['anyOf'])) . ']'
            : "{$r['id']}: " . $one($r);
        return $text . (isset($r['why']) ? " — {$r['why']}" : '');
    }

    /**
     * @param string|string[] $scenario one scenario, or several validated together (e.g. ['cit_3ds_combined',
     *   'cit_agreement_recurring_visa'] for the CIT that establishes an agreement); duplicate violations are reported once
     * @return string[] violations, each prefixed with its rule ID (empty = compliant)
     */
    public static function validate(string|array $scenario, array $body, ?array $policy = null): array
    {
        $out = [];
        foreach ((array) $scenario as $name) {
            foreach (self::rulesFor($name, $policy) as $r) {
                if (!self::check($r, $body)) {
                    $out[] = self::describe($r);
                }
            }
        }
        return array_values(array_unique($out));
    }
}
