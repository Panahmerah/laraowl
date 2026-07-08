<?php

use App\Models\Project;
use App\Models\Record;
use App\Services\SecurityService;

function makeRequestRecord(Project $project, array $payload): Record
{
    return $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'req-'.uniqid(),
        'payload' => array_merge([
            't' => 'request',
            'ip' => '203.0.113.10',
            'status_code' => 200,
            'url' => '/api/ping',
            'query' => [],
            'payload' => [],
            'headers' => [],
        ], $payload),
        'created_at' => now(),
    ]);
}

function hasSecurityIssue(Project $project): bool
{
    return $project->issues()->where('type', 'security')->exists();
}

function securityIssuePriority(Project $project): ?string
{
    return $project->issues()->where('type', 'security')->value('priority');
}

test('a normal JWT bearer token does not get scored as an obfuscated payload', function () {
    $project = Project::factory()->create();
    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $record = makeRequestRecord($project, [
        'headers' => ['authorization' => "Bearer {$jwt}"],
    ]);

    app(SecurityService::class)->analyze($project, $record);

    expect(hasSecurityIssue($project))->toBeFalse();
});

test('a real attack tool user agent is flagged at medium+ priority but a generic http client library stays low', function () {
    $attackerProject = Project::factory()->create();
    $legitProject = Project::factory()->create();

    $attackerRecord = makeRequestRecord($attackerProject, [
        'headers' => ['user-agent' => 'sqlmap/1.7.2'],
    ]);
    $legitRecord = makeRequestRecord($legitProject, [
        'headers' => ['user-agent' => 'python-requests/2.31.0'],
    ]);

    app(SecurityService::class)->analyze($attackerProject, $attackerRecord);
    app(SecurityService::class)->analyze($legitProject, $legitRecord);

    expect(securityIssuePriority($attackerProject))->toBe('medium')
        ->and(securityIssuePriority($legitProject))->toBe('low');
});

test('sensitive file names are only flagged as probes in the URL, not incidental body text', function () {
    $bodyMentionProject = Project::factory()->create();
    $urlProbeProject = Project::factory()->create();

    $bodyMentionRecord = makeRequestRecord($bodyMentionProject, [
        'url' => '/api/support-tickets',
        'payload' => ['message' => 'Can you check if our .env file is configured correctly?'],
    ]);
    $urlProbeRecord = makeRequestRecord($urlProbeProject, [
        'url' => '/.env',
    ]);

    app(SecurityService::class)->analyze($bodyMentionProject, $bodyMentionRecord);
    app(SecurityService::class)->analyze($urlProbeProject, $urlProbeRecord);

    expect(hasSecurityIssue($bodyMentionProject))->toBeFalse()
        ->and(hasSecurityIssue($urlProbeProject))->toBeTrue();
});

test('a single weak sqli structural match alone stays low priority, not a full sqli alert', function () {
    $project = Project::factory()->create();

    $record = makeRequestRecord($project, [
        'url' => '/api/reports?sort=name&clause=ORDER BY 1',
    ]);

    app(SecurityService::class)->analyze($project, $record);

    expect(securityIssuePriority($project))->toBe('low');
});

test('base64-encoded XSS payload is caught via decoding and scored higher than a plain match', function () {
    $project = Project::factory()->create();
    $encoded = base64_encode('<script>alert(1)</script>this-is-a-long-enough-base64-string');

    $record = makeRequestRecord($project, [
        'payload' => ['comment' => $encoded],
    ]);

    app(SecurityService::class)->analyze($project, $record);

    expect(hasSecurityIssue($project))->toBeTrue();
});
