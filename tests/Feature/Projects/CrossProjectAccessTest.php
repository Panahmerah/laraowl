<?php

use App\Enums\TeamRole;
use App\Models\AlertRule;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Record;
use App\Models\Team;
use App\Models\Threshold;
use App\Models\User;

function actingUserWithOwnProject(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $project];
}

test('user cannot view another tenant issue through their own project context', function () {
    [$user, $team, $project] = actingUserWithOwnProject();

    $otherProject = Project::factory()->create();
    $issue = Issue::create([
        'project_id' => $otherProject->id,
        'hash' => 'abc123',
        'type' => 'exception',
        'title' => 'Secret Issue',
        'message' => 'sensitive',
        'status' => 'open',
        'priority' => 'high',
    ]);

    $this->actingAs($user)
        ->get(route('issues.show', ['current_team' => $team, 'project' => $project, 'issue' => $issue]))
        ->assertNotFound();
});

test('user cannot update another tenant issue through their own project context', function () {
    [$user, $team, $project] = actingUserWithOwnProject();

    $otherProject = Project::factory()->create();
    $issue = Issue::create([
        'project_id' => $otherProject->id,
        'hash' => 'abc123',
        'type' => 'exception',
        'title' => 'Secret Issue',
        'message' => 'sensitive',
        'status' => 'open',
        'priority' => 'high',
    ]);

    $this->actingAs($user)
        ->patch(route('issues.update', ['current_team' => $team, 'project' => $project, 'issue' => $issue]), [
            'status' => 'resolved',
        ])
        ->assertNotFound();

    expect($issue->fresh()->status)->toBe('open');
});

test('user cannot update or delete another tenant alert rule', function () {
    [$user, $team, $project] = actingUserWithOwnProject();

    $otherProject = Project::factory()->create();
    $rule = AlertRule::create([
        'project_id' => $otherProject->id,
        'name' => 'Other Rule',
        'event_type' => 'new_exception',
        'settings' => [],
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('alerts.update', ['current_team' => $team, 'project' => $project, 'rule' => $rule]), [
            'name' => 'Hacked',
            'settings' => [],
            'is_enabled' => false,
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('alerts.destroy', ['current_team' => $team, 'project' => $project, 'rule' => $rule]))
        ->assertNotFound();

    expect($rule->fresh())->not->toBeNull();
});

test('user cannot update, delete, or test another tenant integration', function () {
    [$user, $team, $project] = actingUserWithOwnProject();

    $otherProject = Project::factory()->create();
    $integration = Integration::create([
        'project_id' => $otherProject->id,
        'name' => 'Victim Slack',
        'type' => 'slack',
        'data' => ['webhook_url' => 'https://hooks.slack.com/services/x'],
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('integrations.update', ['current_team' => $team, 'project' => $project, 'integration' => $integration]), [
            'name' => 'Hijacked',
            'is_enabled' => false,
            'data' => ['webhook_url' => 'https://hooks.slack.com/services/attacker'],
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('integrations.test', ['current_team' => $team, 'project' => $project, 'integration' => $integration]))
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('integrations.destroy', ['current_team' => $team, 'project' => $project, 'integration' => $integration]))
        ->assertNotFound();

    expect($integration->fresh()->name)->toBe('Victim Slack');
});

test('user cannot delete another tenant threshold', function () {
    [$user, $team, $project] = actingUserWithOwnProject();

    $otherProject = Project::factory()->create();
    $threshold = Threshold::create([
        'project_id' => $otherProject->id,
        'type' => 'route',
        'key' => '/secret',
        'value' => 200,
    ]);

    $this->actingAs($user)
        ->delete(route('thresholds.destroy', ['current_team' => $team, 'project' => $project, 'threshold' => $threshold]))
        ->assertNotFound();

    expect($threshold->fresh())->not->toBeNull();
});

test('user cannot view another tenant record occurrence', function () {
    [$user, $team, $project] = actingUserWithOwnProject();

    $otherProject = Project::factory()->create();
    $record = Record::factory()->create(['project_id' => $otherProject->id]);

    $this->actingAs($user)
        ->get(route('records.show', ['current_team' => $team, 'project' => $project, 'record' => $record]))
        ->assertNotFound();
});
