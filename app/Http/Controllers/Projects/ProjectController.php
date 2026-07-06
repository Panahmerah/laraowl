<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Team;
use App\Rules\PublicUrl;
use App\Services\CloudflareService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function update(Request $request, Team $current_team, Project $project)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:255', new PublicUrl],
            'uptime_check_interval' => ['nullable', 'integer', 'min:30'],
            'retention_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $project->update([
            'name' => $request->name,
            'url' => $request->url,
            'uptime_check_interval' => $request->uptime_check_interval ?? 60,
            'retention_days' => $request->retention_days ?? 7,
        ]);

        if ($request->hasFile('logo')) {
            $project->clearMediaCollection('logo');
            $project->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        return back()->with('success', 'Project updated successfully.');
    }

    public function destroy(Team $current_team, Project $project)
    {
        $project->delete();

        return redirect()->route('dashboard', ['current_team' => $current_team->slug])
            ->with('success', 'Project deleted successfully.');
    }

    public function updateCloudflare(Request $request, Team $current_team, Project $project)
    {
        $request->validate([
            'api_token' => ['required', 'string'],
            'zone_id' => ['required', 'string'],
        ]);

        try {
            $isValid = app(CloudflareService::class)->verifyConnection($request->api_token, $request->zone_id);

            if (! $isValid) {
                return back()->withErrors([
                    'api_token' => 'Could not connect to Cloudflare. Please check your Token and Zone ID.',
                    'zone_id' => 'Verification failed',
                ])->withInput();
            }

            // Force update the settings array
            $settings = $project->settings ?? [];
            $settings['cloudflare'] = [
                'api_token' => $request->api_token,
                'zone_id' => $request->zone_id,
            ];

            $project->settings = $settings;
            $project->save();

            return back()->with('success', 'Cloudflare settings updated and verified successfully.');
        } catch (\Exception $e) {
            \Log::error('Cloudflare Save Error: '.$e->getMessage());

            return back()->withErrors(['api_token' => 'An unexpected error occurred while saving.'])->withInput();
        }
    }

    public function store(Request $request, Team $current_team)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:255', new PublicUrl],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $project = $current_team->projects()->create([
            'name' => $request->name,
            'url' => $request->url,
            'api_token' => Str::random(32),
            'uptime_check_interval' => 60,
        ]);

        if ($request->hasFile('logo')) {
            $project->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        // Create Default Email Integration
        $integration = $project->integrations()->create([
            'name' => 'Default Email',
            'type' => 'email',
            'data' => ['email' => $request->user()->email],
            'is_enabled' => true,
        ]);

        // Create Default Alert Rules
        $rules = [
            [
                'name' => 'Critical Exceptions',
                'event_type' => 'new_exception',
                'settings' => ['frequency' => 'immediate'],
            ],
            [
                'name' => 'Site DOWN Alert',
                'event_type' => 'uptime_down',
                'settings' => ['frequency' => 'immediate'],
            ],
            [
                'name' => 'Error Spike Detected',
                'event_type' => 'error_spike',
                'settings' => ['threshold' => 50, 'period' => 1], // 50 errors in 1 minute
            ],
            [
                'name' => 'Background Job Failed',
                'event_type' => 'heartbeat_failed',
                'settings' => ['frequency' => 'immediate'],
            ],
        ];

        foreach ($rules as $ruleData) {
            $rule = $project->alertRules()->create($ruleData + ['is_enabled' => true]);
            $rule->integrations()->attach($integration->id);
        }

        return redirect()->route('dashboard', [
            'current_team' => $current_team->slug,
            'project' => $project->slug,
        ]);
    }
}
