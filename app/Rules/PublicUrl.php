<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects URLs that resolve to loopback, private, link-local, or otherwise
 * reserved IP ranges, to prevent SSRF against internal infrastructure
 * (e.g. cloud metadata endpoints) via user-supplied webhook/uptime URLs.
 */
class PublicUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = parse_url($value, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! $host) {
            $fail(__('The :attribute must be a valid http or https URL.'));

            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if (empty($ips)) {
            $fail(__('The :attribute host could not be resolved.'));

            return;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $fail(__('The :attribute must not point to a private or internal address.'));

                return;
            }
        }
    }

    /**
     * Resolve a hostname to its IPv4 and IPv6 addresses.
     *
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}
