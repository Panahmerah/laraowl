<?php

use App\Models\Project;
use App\Models\Record;
use App\Services\SecurityService;
use Illuminate\Support\Facades\Http;

function makeSecurityAuditRecord(Project $project, array $publicFiles): Record
{
    return $project->records()->create([
        'type' => 'security-audit',
        'payload' => [
            't' => 'security-audit',
            'payload' => [
                'public_files' => $publicFiles,
            ],
        ],
        'created_at' => now(),
    ]);
}

test('directory listing flag is confirmed and reported when the public folder is actually browsable', function () {
    Http::fake([
        '*/build/' => Http::response('<html><title>Index of /build</title></html>', 200),
    ]);

    $project = Project::factory()->create(['url' => 'https://example-app.com']);
    $record = makeSecurityAuditRecord($project, ['directory_listing_enabled' => true]);

    app(SecurityService::class)->audit($project, $record);

    expect($project->issues()->where('type', 'security')->exists())->toBeTrue();
});

test('directory listing flag is suppressed as a false positive when the server actually returns 403', function () {
    Http::fake([
        '*/build/' => Http::response('Forbidden', 403),
        '*/vendor/' => Http::response('Forbidden', 403),
        '*/storage/' => Http::response('Forbidden', 403),
    ]);

    $project = Project::factory()->create(['url' => 'https://example-app.com']);
    $record = makeSecurityAuditRecord($project, ['directory_listing_enabled' => true]);

    app(SecurityService::class)->audit($project, $record);

    expect($project->issues()->where('type', 'security')->exists())->toBeFalse();
});

test('directory listing flag is skipped when the project has no public url to verify against', function () {
    $project = Project::factory()->create(['url' => null]);
    $record = makeSecurityAuditRecord($project, ['directory_listing_enabled' => true]);

    app(SecurityService::class)->audit($project, $record);

    expect($project->issues()->where('type', 'security')->exists())->toBeFalse();
});
