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

it('resolves the job-attempt that a mail record was sent inside of', function () {
    $project = Project::factory()->create();

    $jobAttempt = Record::create([
        'project_id' => $project->id,
        'type' => 'job-attempt',
        'fingerprint' => 'send-mail-job',
        'payload' => [
            'name' => 'App\\Jobs\\SendWelcomeEmailJob',
            'attempt_id' => 'attempt-xyz-789',
            'status' => 'processed',
        ],
        'created_at' => now(),
    ]);

    $mail = Record::create([
        'project_id' => $project->id,
        'type' => 'mail',
        'fingerprint' => 'welcome-mail',
        'payload' => [
            'class' => 'App\\Mail\\WelcomeMail',
            'subject' => 'Welcome!',
            'execution_id' => 'attempt-xyz-789',
            'execution_source' => 'job',
            'execution_preview' => 'App\\Jobs\\SendWelcomeEmailJob',
        ],
        'created_at' => now(),
    ]);

    $resolved = app(RecordService::class)->getExecutionSourceRecord($project, $mail);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($jobAttempt->id);
});

it('resolves the request that directly dispatched a queued job', function () {
    $project = Project::factory()->create();

    $request = Record::create([
        'project_id' => $project->id,
        'type' => 'request',
        'fingerprint' => 'dispatching-request',
        'payload' => [
            'route_path' => '/checkout',
            'status_code' => 200,
            'trace_id' => 'trace-root-1',
        ],
        'created_at' => now(),
    ]);

    $queuedJob = Record::create([
        'project_id' => $project->id,
        'type' => 'queued-job',
        'fingerprint' => 'queued-job-1',
        'payload' => [
            'name' => 'App\\Jobs\\ProcessOrderJob',
            'execution_id' => 'trace-root-1',
            'execution_source' => 'request',
            'execution_preview' => 'POST /checkout',
        ],
        'created_at' => now(),
    ]);

    $resolved = app(RecordService::class)->getExecutionSourceRecord($project, $queuedJob);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($request->id);
});

it('resolves the artisan command that sent a mail directly', function () {
    $project = Project::factory()->create();

    $command = Record::create([
        'project_id' => $project->id,
        'type' => 'command',
        'fingerprint' => 'digest-command',
        'payload' => [
            'command' => 'digest:send --ansi',
            'trace_id' => 'trace-command-1',
        ],
        'created_at' => now(),
    ]);

    $mail = Record::create([
        'project_id' => $project->id,
        'type' => 'mail',
        'fingerprint' => 'digest-mail',
        'payload' => [
            'class' => 'App\\Mail\\DigestMail',
            'subject' => 'Weekly Digest',
            'execution_id' => 'trace-command-1',
            'execution_source' => 'command',
            'execution_preview' => 'digest:send',
        ],
        'created_at' => now(),
    ]);

    $resolved = app(RecordService::class)->getExecutionSourceRecord($project, $mail);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($command->id);
});
