<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Record;
use App\Rules\PublicUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SecurityService
{
    const SCORE_THRESHOLD_MEDIUM = 30;

    const SCORE_THRESHOLD_HIGH = 70;

    const SCORE_THRESHOLD_CRITICAL = 100;

    /**
     * Advanced threat patterns with assigned risk scores.
     *
     * Each type is a list of confidence-tiered groups (OWASP CRS-style paranoia
     * levels): strong groups are unambiguous attack syntax and score high,
     * weak groups are structurally-common tokens that also occur in benign
     * traffic and score low so a single incidental match can't alone cross
     * the medium-risk threshold. `sources` restricts a group to specific
     * request parts (e.g. sensitive-file probes only make sense as a URL
     * path, not as incidental text inside a request body).
     */
    protected array $threatPatterns = [
        'sqli' => [
            [
                'patterns' => [
                    "/'\s*OR\s*['\"]?1['\"]?\s*=\s*['\"]?1/",
                    "/UNION\s+SELECT/i",
                    "/DROP\s+TABLE/i",
                    "/SLEEP\s*\(/i",
                    '/INFORMATION_SCHEMA/i',
                ],
                'score' => 40,
            ],
            [
                // Weak: also appears in legitimate sort/report query params.
                'patterns' => [
                    "/GROUP\s+BY\s+\d+/i",
                    "/ORDER\s+BY\s+\d+/i",
                ],
                'score' => 15,
            ],
        ],
        'xss' => [
            [
                'patterns' => [
                    '/<script/i',
                    '/javascript:/i',
                    "/onerror\s*=/i",
                    "/onload\s*=/i",
                    '/<iframe/i',
                    "/string\.fromcharcode/i",
                ],
                'score' => 30,
            ],
            [
                // Weak: single JS identifiers, common in error messages/support text.
                'patterns' => [
                    "/document\.cookie/i",
                    "/alert\s*\(/i",
                    "/prompt\s*\(/i",
                ],
                'score' => 15,
            ],
        ],
        'path_traversal' => [
            [
                'patterns' => [
                    "/\.\.\//",
                    "/\/etc\/passwd/",
                    "/proc\/self/i",
                ],
                'score' => 50,
            ],
        ],
        'sensitive_file_probe' => [
            [
                // Only meaningful as the request path itself — matching this
                // against a body/query blob just means someone typed ".env"
                // in a support message, not that they're probing for it.
                'patterns' => [
                    "/\.env(?:$|[^a-z0-9_.-])/i",
                    "/\.git\//i",
                    "/\.htaccess/i",
                    "/config\/database\.php/i",
                ],
                'score' => 45,
                'sources' => ['url'],
            ],
        ],
        'command_injection' => [
            [
                'patterns' => [
                    "/;\s*cat\s+/i",
                    "/\|\s*grep\s+/i",
                    "/&&\s*ls/i",
                    "/system\s*\(/i",
                    "/exec\s*\(/i",
                    "/passthru\s*\(/i",
                    "/shell_exec\s*\(/i",
                    "/curl\s+.*\|\s*sh/i",
                ],
                'score' => 60,
            ],
        ],
        'lfi_rfi' => [
            [
                'patterns' => [
                    "/php:\/\/filter/i",
                    "/https?:\/\/.*\.(txt|php|exe)/i",
                    "/expect:\/\//i",
                ],
                'score' => 50,
            ],
        ],
    ];

    /**
     * Unambiguous offensive-security tooling — safe to flag on sight.
     */
    protected array $knownAttackTools = ['sqlmap', 'nmap', 'nikto', 'dirbuster', 'gobuster'];

    /**
     * Generic HTTP client libraries used by countless legitimate integrations,
     * webhooks, and health checks. Their presence alone isn't a threat signal,
     * so they score low (informational) rather than triggering a "suspicious
     * tool" alert like the tools above.
     */
    protected array $genericHttpClients = ['python-requests', 'go-http-client', 'okhttp', 'node-fetch'];

    /**
     * Analyze a record for potential security threats.
     */
    public function analyze(Project $project, Record $record): void
    {
        if ($record->type !== 'request') {
            return;
        }

        $payload = $record->payload;
        $ip = $payload['ip'] ?? 'unknown';
        $detectedThreats = [];
        $totalScore = 0;

        // 1. De-obfuscate and Prepare Inputs
        $rawInputs = [
            'url' => $payload['url'] ?? '',
            'query' => json_encode($payload['query'] ?? []),
            'body' => is_array($payload['payload'] ?? null) ? json_encode($payload['payload']) : ($payload['payload'] ?? ''),
            'headers' => is_array($payload['headers'] ?? null) ? json_encode($payload['headers']) : ($payload['headers'] ?? ''),
        ];

        // Maps a decoded pseudo-source (e.g. "body_decoded") back to the base
        // source it came from ("body"), so a match can be scored as an
        // evasion attempt only when it shows up decoded but not in the raw input.
        $decodedSources = [];

        $preparedInputs = [];
        foreach ($rawInputs as $key => $val) {
            $decoded = urldecode($val);
            $preparedInputs[$key] = $decoded;

            // Long base64/hex-looking strings (JWTs, API keys, session tokens)
            // are routine in real traffic, so decoding alone is not scored —
            // only content that decodes into an actual threat pattern below is.
            if ($this->isObfuscated($decoded)) {
                $decodedKey = $key.'_decoded';
                $preparedInputs[$decodedKey] = $this->deobfuscate($decoded);
                $decodedSources[$decodedKey] = $key;
            }
        }

        // 2. Pattern Matching (confidence-tiered, source-scoped)
        foreach ($preparedInputs as $source => $value) {
            $baseSource = $decodedSources[$source] ?? $source;
            $isEvasion = isset($decodedSources[$source]);

            foreach ($this->threatPatterns as $type => $groups) {
                foreach ($groups as $group) {
                    $allowedSources = $group['sources'] ?? null;

                    if ($allowedSources !== null && ! in_array($baseSource, $allowedSources, true)) {
                        continue;
                    }

                    foreach ($group['patterns'] as $pattern) {
                        if (! preg_match($pattern, $value)) {
                            continue;
                        }

                        $score = $group['score'];

                        // Evasion bonus: threat only surfaced after decoding,
                        // meaning the attacker deliberately obfuscated it.
                        if ($isEvasion) {
                            $score += 15;
                        }

                        $detectedThreats[] = [
                            'type' => $type,
                            'source' => $source,
                            'pattern' => $pattern,
                        ];
                        $totalScore += $score;
                    }
                }
            }
        }

        // 3. Anomaly Detection (Scanner, UA, Status Codes)
        $anomalies = $this->detectAnomalies($project, $ip, $payload);
        if (! empty($anomalies)) {
            foreach ($anomalies as $anomaly) {
                $detectedThreats[] = $anomaly;
                $totalScore += $anomaly['score'];
            }
        }

        if (! empty($detectedThreats)) {
            $this->processThreats($project, $record, $detectedThreats, $totalScore);
        }
    }

    /**
     * Detect anomalies like rapid 404s (Scanning) or suspicious Headers.
     */
    protected function detectAnomalies(Project $project, string $ip, array $payload): array
    {
        $anomalies = [];
        $cachePrefix = "sec_anom_{$project->id}_{$ip}_";

        // A. Scanner Detection (Too many 404s)
        if (($payload['status_code'] ?? 200) === 404) {
            $key = $cachePrefix.'404_count';
            $count = Cache::increment($key);
            Cache::put($key, $count, 60); // Reset every minute

            if ($count > 10) {
                $anomalies[] = [
                    'type' => 'anomaly',
                    'detail' => 'Directory Scanning Detected (Rapid 404s)',
                    'score' => 20,
                ];
            }
        }

        // B. User-Agent Anomaly
        $ua = strtolower($payload['headers']['user-agent'] ?? '');

        foreach ($this->knownAttackTools as $tool) {
            if (Str::contains($ua, $tool)) {
                $anomalies[] = [
                    'type' => 'anomaly',
                    'detail' => "Suspicious Security Tool Detected: $tool",
                    'score' => 40,
                ];
            }
        }

        // Generic scripting clients are routine for webhooks/integrations/health
        // checks — informational weight only, not enough alone to raise an alert.
        foreach ($this->genericHttpClients as $client) {
            if (Str::contains($ua, $client)) {
                $anomalies[] = [
                    'type' => 'anomaly',
                    'detail' => "Automated HTTP Client Detected: $client",
                    'score' => 5,
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Check if a string looks like it contains Base64 or Hex.
     */
    protected function isObfuscated(string $value): bool
    {
        return $this->extractObfuscatedCandidates($value) !== [];
    }

    /**
     * Pull out the individual base64/hex-looking substrings from a larger
     * blob (e.g. a JSON request body). Decoding the whole blob directly
     * never works — real payloads are wrapped in JSON punctuation that
     * breaks strict base64/hex decoding — so each candidate must be
     * extracted and decoded on its own.
     *
     * @return array<int, string>
     */
    protected function extractObfuscatedCandidates(string $value): array
    {
        $candidates = [];

        if (preg_match_all('/[a-zA-Z0-9+\/]{20,}={0,2}/', $value, $matches)) {
            $candidates = array_merge($candidates, $matches[0]);
        }

        if (preg_match_all('/(?:[0-9a-fA-F]{2}){8,}/', $value, $matches)) {
            $candidates = array_merge($candidates, $matches[0]);
        }

        return $candidates;
    }

    /**
     * Attempt to de-obfuscate a string by decoding each candidate substring
     * independently and concatenating whatever successfully decodes to
     * printable text.
     */
    protected function deobfuscate(string $value): string
    {
        $decodedParts = [];

        foreach ($this->extractObfuscatedCandidates($value) as $candidate) {
            $decoded = base64_decode($candidate, true);
            if ($decoded !== false && ctype_print($decoded)) {
                $decodedParts[] = $decoded;

                continue;
            }

            if (ctype_xdigit($candidate) && strlen($candidate) > 10) {
                $bin = @hex2bin($candidate);
                if ($bin !== false && ctype_print($bin)) {
                    $decodedParts[] = $bin;
                }
            }
        }

        return implode(' ', $decodedParts);
    }

    /**
     * Process threats and update IP reputation.
     */
    protected function processThreats(Project $project, Record $record, array $threats, int $roundScore): void
    {
        $ip = $record->payload['ip'] ?? 'unknown';
        $repKey = "sec_rep_{$project->id}_{$ip}";

        // Cumulative Score
        $cumulativeScore = Cache::get($repKey, 0) + $roundScore;
        Cache::put($repKey, $cumulativeScore, 3600 * 24); // Store for 24h

        $riskLevel = $this->getRiskLevel($cumulativeScore);

        // Report
        $hash = md5("security_{$riskLevel}_{$ip}");
        $title = 'Security Issue: '.strtoupper($riskLevel)." Risk from $ip";
        $message = "Cumulative Threat Score: $cumulativeScore. Detected: ".collect($threats)->pluck('type')->unique()->implode(', ');

        $issue = $project->issues()->firstOrCreate(
            ['hash' => $hash],
            [
                'type' => 'security',
                'title' => $title,
                'message' => $message,
                'status' => 'open',
                'priority' => $this->getPriority($riskLevel),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        $issue->increment('occurrences_count');
        $issue->update([
            'last_seen_at' => now(),
            'message' => $message,
        ]);

        $record->update(['issue_id' => $issue->id]);

        // Tag the record
        $payload = $record->payload;
        $payload['_security_threats'] = $threats;
        $payload['_security_score'] = $cumulativeScore;
        $payload['_security_risk'] = $riskLevel;
        $record->update(['payload' => $payload]);
    }

    protected function getRiskLevel(int $score): string
    {
        if ($score >= self::SCORE_THRESHOLD_CRITICAL) {
            return 'critical';
        }
        if ($score >= self::SCORE_THRESHOLD_HIGH) {
            return 'high';
        }
        if ($score >= self::SCORE_THRESHOLD_MEDIUM) {
            return 'medium';
        }

        return 'low';
    }

    protected function getPriority(string $risk): string
    {
        return match ($risk) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            default => 'low',
        };
    }

    public function audit(Project $project, Record $record): void
    {
        $payload = $record->payload['payload'] ?? [];
        $hashes = $payload['hashes'] ?? [];
        $env = $payload['environment'] ?? [];
        $publicFiles = $payload['public_files'] ?? [];

        $securityIssues = [];

        // 1. File Integrity Check
        $settings = $project->settings ?? [];
        $oldHashes = $settings['security_hashes'] ?? [];
        $hashChanges = $this->compareHashes($oldHashes, $hashes);

        if (! empty($hashChanges)) {
            $securityIssues[] = [
                'type' => 'file_integrity',
                'details' => $hashChanges,
                'priority' => 'critical',
            ];
        }

        // 2. Environment Audit
        if (($env['app_debug'] ?? false) && ($env['app_env'] ?? '') === 'production') {
            $securityIssues[] = [
                'type' => 'environment',
                'details' => 'APP_DEBUG is enabled in production environment',
                'priority' => 'high',
            ];
        }

        if (! ($env['session_secure'] ?? true)) {
            $securityIssues[] = [
                'type' => 'configuration',
                'details' => 'Session cookies are not set to Secure',
                'priority' => 'medium',
            ];
        }

        // 3. Public Folder Audit
        if (! empty($publicFiles['suspicious_files_found'] ?? [])) {
            $securityIssues[] = [
                'type' => 'suspicious_files',
                'details' => 'Suspicious files found in public folder: '.implode(', ', $publicFiles['suspicious_files_found']),
                'priority' => 'high',
            ];
        }

        if (($publicFiles['directory_listing_enabled'] ?? false) && $this->verifyDirectoryListing($project)) {
            $securityIssues[] = [
                'type' => 'configuration',
                'details' => 'Directory listing is enabled in public folder',
                'priority' => 'medium',
            ];
        }

        // 4. Dependency Analysis (Simple version)
        $deps = $payload['dependencies'] ?? [];
        if (($deps['count'] ?? 0) > 200) {
            $securityIssues[] = [
                'type' => 'dependencies',
                'details' => 'Large number of dependencies detected ('.$deps['count'].'). Increase attack surface.',
                'priority' => 'low',
            ];
        }

        // Report Issues
        if (! empty($securityIssues)) {
            $this->reportSecurityAuditIssues($project, $record, $securityIssues);
        }

        // Update the baseline
        $settings['security_hashes'] = $hashes;
        $settings['security_env'] = $env;
        $settings['last_audit_at'] = now()->toDateTimeString();
        $project->update(['settings' => $settings]);
    }

    /**
     * The client's directory-listing check is a local heuristic (e.g. inspecting
     * .htaccess) and can't see the real webserver config, especially on Nginx/
     * Cloud hosts where .htaccess is ignored — so it over-reports. Confirm by
     * actually requesting the public folder and looking for a real listing
     * page before raising the issue.
     */
    protected function verifyDirectoryListing(Project $project): bool
    {
        if (empty($project->url)) {
            return false;
        }

        $validator = Validator::make(['url' => $project->url], ['url' => ['url', new PublicUrl]]);

        if ($validator->fails()) {
            return false;
        }

        $base = rtrim($project->url, '/');

        foreach (['/build/', '/vendor/', '/storage/'] as $path) {
            try {
                $response = Http::timeout(5)->get($base.$path);
            } catch (\Throwable $e) {
                continue;
            }

            if ($response->status() === 200 && Str::contains($response->body(), 'Index of', ignoreCase: true)) {
                return true;
            }
        }

        return false;
    }

    protected function compareHashes(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $file => $hash) {
            if (isset($old[$file]) && $old[$file] !== $hash) {
                $changes[] = ['file' => $file, 'type' => 'modified'];
            } elseif (! isset($old[$file])) {
                $changes[] = ['file' => $file, 'type' => 'added'];
            }
        }

        foreach ($old as $file => $hash) {
            if (! isset($new[$file])) {
                $changes[] = ['file' => $file, 'type' => 'deleted'];
            }
        }

        return $changes;
    }

    protected function reportSecurityAuditIssues(Project $project, Record $record, array $issues): void
    {
        $hash = md5("security_audit_{$project->id}");
        $highestPriority = 'low';
        $priorities = ['low', 'medium', 'high', 'critical'];

        foreach ($issues as $issue) {
            if (array_search($issue['priority'], $priorities) > array_search($highestPriority, $priorities)) {
                $highestPriority = $issue['priority'];
            }
        }

        $title = 'Security Audit: '.count($issues).' issues detected';
        $message = collect($issues)->pluck('details')->flatten()->implode('; ');

        $issueModel = $project->issues()->firstOrCreate(
            ['hash' => $hash],
            [
                'type' => 'security',
                'title' => $title,
                'message' => Str::limit($message, 500),
                'status' => 'open',
                'priority' => $highestPriority,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        $issueModel->increment('occurrences_count');
        $issueModel->update([
            'last_seen_at' => now(),
            'message' => Str::limit($message, 500),
            'priority' => $highestPriority,
        ]);

        $record->update(['issue_id' => $issueModel->id]);

        // Tag the record
        $payload = $record->payload;
        $payload['_security_audit_issues'] = $issues;
        $record->update(['payload' => $payload]);
    }

    /**
     * Report file integrity issues.
     */
    protected function reportIntegrityIssue(Project $project, Record $record, array $changes): void
    {
        $ip = $record->payload['ip'] ?? 'unknown';
        $hash = md5("security_fim_{$project->id}");

        $title = 'Security: File Integrity Change Detected';
        $message = "Unauthorized file changes detected on server ($ip). ".count($changes).' items affected.';

        $issue = $project->issues()->firstOrCreate(
            ['hash' => $hash],
            [
                'type' => 'security',
                'title' => $title,
                'message' => $message,
                'status' => 'open',
                'priority' => 'critical',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        $issue->increment('occurrences_count');
        $issue->update([
            'last_seen_at' => now(),
            'message' => $message,
        ]);

        $record->update(['issue_id' => $issue->id]);

        // Tag the record
        $payload = $record->payload;
        $payload['_security_changes'] = $changes;
        $record->update(['payload' => $payload]);
    }
}
