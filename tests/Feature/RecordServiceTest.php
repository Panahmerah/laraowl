<?php

use App\Models\Project;
use App\Models\Record;
use App\Services\RecordService;

it('handles non numeric outgoing request status values without SQL errors', function () {
    $project = Project::factory()->create();

    Record::create([
        'project_id' => $project->id,
        'type' => 'outgoing-request',
        'fingerprint' => 'failed-status',
        'payload' => [
            'host' => 'api.example.com',
            'status' => 'failed',
            'duration' => 125,
        ],
        'created_at' => now(),
    ]);

    Record::create([
        'project_id' => $project->id,
        'type' => 'outgoing-request',
        'fingerprint' => 'ok-status',
        'payload' => [
            'host' => 'api.example.com',
            'status_code' => 200,
            'duration' => 75,
        ],
        'created_at' => now(),
    ]);

    $stats = app(RecordService::class)->getOutgoingRequestStats($project, '24h');

    expect($stats['overview'])
        ->toMatchArray([
            'total' => 2,
            'ok' => 1,
            'failed' => 0,
        ]);

    expect($stats['hosts']->total())->toBe(2);
});

it('links a failed request to the exception thrown during the same trace', function () {
    $project = Project::factory()->create();

    $request = Record::create([
        'project_id' => $project->id,
        'type' => 'request',
        'fingerprint' => 'failed-request',
        'payload' => [
            'route_path' => '/checkout',
            'status_code' => 500,
            'trace_id' => 'trace-abc-123',
        ],
        'created_at' => now(),
    ]);

    $exception = Record::create([
        'project_id' => $project->id,
        'type' => 'exception',
        'fingerprint' => 'checkout-error',
        'payload' => [
            'class' => 'RuntimeException',
            'message' => 'Payment gateway timed out',
            'file' => 'app/Services/PaymentService.php',
            'line' => 42,
            'trace_id' => 'trace-abc-123',
        ],
        'created_at' => now(),
    ]);

    $linked = app(RecordService::class)->getLinkedExceptionRecord($project, $request);

    expect($linked)->not->toBeNull()
        ->and($linked->id)->toBe($exception->id);
});

it('returns null when a request has no matching trace_id exception', function () {
    $project = Project::factory()->create();

    $request = Record::create([
        'project_id' => $project->id,
        'type' => 'request',
        'fingerprint' => 'no-link-request',
        'payload' => [
            'route_path' => '/health',
            'status_code' => 200,
            'trace_id' => 'trace-unmatched',
        ],
        'created_at' => now(),
    ]);

    $linked = app(RecordService::class)->getLinkedExceptionRecord($project, $request);

    expect($linked)->toBeNull();
});
