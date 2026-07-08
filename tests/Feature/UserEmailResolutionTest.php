<?php

use App\Models\Project;
use App\Services\RecordService;

test('user stats resolve real email from user detail records', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'user',
        'payload' => [
            't' => 'user',
            'id' => 123,
            'name' => 'Ada Lovelace',
            'username' => 'ada@example.com',
        ],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'request-user-123',
        'payload' => [
            't' => 'request',
            'user' => 123,
            'status_code' => 200,
            'duration' => 25,
        ],
        'created_at' => now(),
    ]);

    $stats = app(RecordService::class)->getUserStats($project, '24h');
    $user = $stats['users']->items()[0];

    expect($user->user_name)->toBe('Ada Lovelace')
        ->and($user->user_email)->toBe('ada@example.com')
        ->and((string) $user->user_id)->toBe('123');
});

test('dashboard active users resolve real email from user detail records', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'user',
        'payload' => [
            't' => 'user',
            'id' => 456,
            'name' => 'Grace Hopper',
            'username' => 'grace@example.com',
        ],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'request-user-456',
        'payload' => [
            't' => 'request',
            'user' => 456,
            'status_code' => 200,
            'duration' => 25,
        ],
        'created_at' => now(),
    ]);

    $stats = app(RecordService::class)->getDashboardStats($project, '24h');
    $user = $stats['active_users']->first();

    expect($user->user_identifier)->toBe('Grace Hopper')
        ->and($user->user_email)->toBe('grace@example.com')
        ->and((string) $user->user_id)->toBe('456');
});

test('user history resolves real email from user detail records', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'user',
        'payload' => [
            't' => 'user',
            'id' => 789,
            'name' => 'Katherine Johnson',
            'username' => 'katherine@example.com',
        ],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'request-user-789',
        'payload' => [
            't' => 'request',
            'user' => 789,
            'status_code' => 200,
            'duration' => 25,
        ],
        'created_at' => now(),
    ]);

    $history = app(RecordService::class)->getUserHistory($project, md5('789'), '24h');

    expect($history['user_name'])->toBe('Katherine Johnson')
        ->and($history['user_email'])->toBe('katherine@example.com')
        ->and((string) $history['user_id'])->toBe('789');
});

test('user stats, exception stats, and user history report last_seen as UTC ISO timestamps', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'request-user-321',
        'payload' => [
            't' => 'request',
            'user' => 321,
            'status_code' => 200,
            'duration' => 25,
        ],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'exception',
        'fingerprint' => 'exception-user-321',
        'payload' => [
            't' => 'exception',
            'class' => 'RuntimeException',
            'message' => 'boom',
            'user' => 321,
        ],
        'created_at' => now(),
    ]);

    $userStats = app(RecordService::class)->getUserStats($project, '24h');
    $userRow = $userStats['users']->items()[0];

    $exceptionStats = app(RecordService::class)->getExceptionStats($project, '24h');
    $exceptionRow = $exceptionStats['exceptions']->items()[0];

    $history = app(RecordService::class)->getUserHistory($project, md5('321'), '24h');

    expect($userRow->last_seen)->toMatch('/Z$/')
        ->and($exceptionRow->last_seen)->toMatch('/Z$/')
        ->and($history['stats']->first_seen)->toMatch('/Z$/')
        ->and($history['stats']->last_seen)->toMatch('/Z$/');
});

test('user history stats correctly count exceptions and failed status codes as errors', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'ok-request',
        'payload' => ['t' => 'request', 'user' => 654, 'status_code' => 200],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'not-found-request',
        'payload' => ['t' => 'request', 'user' => 654, 'status_code' => 404],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'server-error-request',
        'payload' => ['t' => 'request', 'user' => 654, 'status_code' => 500],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'exception',
        'fingerprint' => 'exception-user-654',
        'payload' => ['t' => 'exception', 'class' => 'RuntimeException', 'message' => 'boom', 'user' => 654],
        'created_at' => now(),
    ]);

    $history = app(RecordService::class)->getUserHistory($project, md5('654'), '24h');

    expect($history['stats']->total)->toBe(4)
        ->and($history['stats']->error_count)->toBe(3)
        ->and($history['stats']->ok_count)->toBe(1);
});

test('synthetic guest session identifiers are excluded from authenticated user stats', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'guest-request',
        'payload' => ['t' => 'request', 'user' => 'guest_7cf6295f61e4', 'status_code' => 200],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'real-user-request',
        'payload' => ['t' => 'request', 'user' => 999, 'status_code' => 200],
        'created_at' => now(),
    ]);

    $stats = app(RecordService::class)->getUserStats($project, '24h');

    expect($stats['users']->total())->toBe(1)
        ->and((string) $stats['users']->items()[0]->user_id)->toBe('999')
        ->and($stats['overview']['auth_users'])->toBe(1)
        ->and($stats['overview']['guest_requests'])->toBe(1);
});

test('users list scope filter switches between authenticated, guest, and all', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'guest-scope-request',
        'payload' => ['t' => 'request', 'user' => 'guest_deadbeef0000', 'status_code' => 200],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'real-user-scope-request',
        'payload' => ['t' => 'request', 'user' => 42, 'status_code' => 200],
        'created_at' => now(),
    ]);

    $authenticated = app(RecordService::class)->getUserStats($project, '24h', null, null, 'authenticated');
    $guest = app(RecordService::class)->getUserStats($project, '24h', null, null, 'guest');
    $all = app(RecordService::class)->getUserStats($project, '24h', null, null, 'all');

    expect($authenticated['users']->total())->toBe(1)
        ->and((string) $authenticated['users']->items()[0]->user_id)->toBe('42')
        ->and($guest['users']->total())->toBe(1)
        ->and((string) $guest['users']->items()[0]->user_id)->toBe('guest_deadbeef0000')
        ->and($all['users']->total())->toBe(2);
});

test('user history status filter switches between error, ok, and all records', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'ok-history-request',
        'payload' => ['t' => 'request', 'user' => 741, 'status_code' => 200],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'error-history-request',
        'payload' => ['t' => 'request', 'user' => 741, 'status_code' => 500],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'exception',
        'fingerprint' => 'exception-history-741',
        'payload' => ['t' => 'exception', 'class' => 'RuntimeException', 'message' => 'boom', 'user' => 741],
        'created_at' => now(),
    ]);

    $hash = md5('741');

    $all = app(RecordService::class)->getUserHistory($project, $hash, '24h', null, null, 'all');
    $errors = app(RecordService::class)->getUserHistory($project, $hash, '24h', null, null, 'error');
    $ok = app(RecordService::class)->getUserHistory($project, $hash, '24h', null, null, 'ok');

    expect($all['records']->total())->toBe(3)
        ->and($errors['records']->total())->toBe(2)
        ->and($ok['records']->total())->toBe(1);
});

test('synthetic guest sessions are excluded from dashboard active and impacted users', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'guest-dashboard-request',
        'payload' => ['t' => 'request', 'user' => 'guest_abc123', 'status_code' => 200],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'exception',
        'fingerprint' => 'guest-dashboard-exception',
        'payload' => ['t' => 'exception', 'class' => 'RuntimeException', 'message' => 'boom', 'user' => 'guest_abc123'],
        'created_at' => now(),
    ]);

    $dashboard = app(RecordService::class)->getDashboardStats($project, '24h');

    expect($dashboard['active_users'])->toHaveCount(0)
        ->and($dashboard['impacted_users'])->toHaveCount(0)
        ->and($dashboard['auth_users_count'])->toBe(0)
        ->and($dashboard['guest_users_count'])->toBe(1);
});
