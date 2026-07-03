<?php

namespace App\Console\Commands;

use App\Models\Heartbeat;
use App\Models\Project;
use App\Rules\PublicUrl;
use App\Services\AlertService;
use App\Services\IntegrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class CheckProjectUptime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:check-health';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Perform uptime and heartbeat health checks for all projects';

    /**
     * Execute the console command.
     */
    public function handle(AlertService $alertService)
    {
        // 1. Check Uptime
        $this->performUptimeChecks($alertService);

        // 2. Check Heartbeats
        $this->checkHeartbeats($alertService);

        // 3. Support sub-minute intervals (30s)
        if (Project::where('uptime_check_interval', '<', 60)->whereNotNull('url')->exists()) {
            $this->info('Waiting 30 seconds for next sub-minute check...');
            sleep(30);
            $this->performUptimeChecks($alertService);
        }

        $this->info('Health checks completed.');
    }

    protected function performUptimeChecks(AlertService $alertService)
    {
        $projects = Project::whereNotNull('url')->get()->filter(function ($project) {
            if (is_null($project->last_uptime_check_at)) {
                return true;
            }

            return $project->last_uptime_check_at->addSeconds($project->uptime_check_interval)->isPast();
        });

        foreach ($projects as $project) {
            $this->checkUptime($project, $alertService);
        }
    }

    protected function checkUptime(Project $project, AlertService $alertService)
    {
        $validator = Validator::make(['url' => $project->url], ['url' => ['url', new PublicUrl]]);

        if ($validator->fails()) {
            $this->warn("Skipping uptime check for {$project->name}: URL is not a valid public address.");

            return;
        }

        $start = microtime(true);
        $status = 'up';
        $statusCode = 0;
        $error = null;

        try {
            $response = Http::retry(2, 1000, throw: false)->timeout(10)->get($project->url);
            $statusCode = $response->status();

            if ($response->failed()) {
                $status = 'down';
                $error = "HTTP error status: {$statusCode}";
            }
        } catch (\Exception $e) {
            $status = 'down';
            $error = $e->getMessage();
            $statusCode = 0;
        }

        $responseTime = round((microtime(true) - $start) * 1000); // in ms

        // Record the check
        $project->uptimeChecks()->create([
            'status' => $status,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'error' => $error,
            'checked_at' => now(),
        ]);

        $previousStatus = $project->last_uptime_status;

        // Update project state
        $project->update([
            'last_uptime_check_at' => now(),
            'last_uptime_status' => $status,
        ]);

        // Trigger Alert if status changed to 'down'
        if ($status === 'down' && $previousStatus !== 'down') {
            $this->warn("Project {$project->name} is DOWN!");
            $alertService->notifyUptimeDown($project, $statusCode, $error);
        }

        // Trigger Alert if status recovered to 'up'
        if ($status === 'up' && $previousStatus === 'down') {
            $this->info("Project {$project->name} is back UP.");
            $this->notifyRecovery($project, $alertService);
        }
    }

    protected function checkHeartbeats(AlertService $alertService)
    {
        $failingHeartbeats = Heartbeat::where('status', 'active')
            ->get()
            ->filter(fn ($h) => $h->isFailing());

        foreach ($failingHeartbeats as $heartbeat) {
            $heartbeat->update(['status' => 'failing']);
            $alertService->notifyHeartbeatFailed($heartbeat);
            $this->warn("Heartbeat '{$heartbeat->name}' for project '{$heartbeat->project->name}' failed.");
        }
    }

    protected function notifyRecovery(Project $project, AlertService $alertService)
    {
        $rules = $project->alertRules()
            ->where('event_type', 'uptime_down')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        foreach ($rules as $rule) {
            foreach ($rule->integrations as $integration) {
                if (! $integration->is_enabled) {
                    continue;
                }

                app(IntegrationService::class)->send(
                    $integration,
                    "✅ Project Back Online: {$project->name}",
                    'Your project is responding correctly again.',
                    [
                        'Project' => $project->name,
                        'URL' => $project->url,
                        'Status' => 'UP',
                    ],
                    config('app.url')."/projects/{$project->slug}"
                );
            }
        }
    }
}
