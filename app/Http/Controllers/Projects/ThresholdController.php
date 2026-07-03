<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Team;
use App\Models\Threshold;
use Illuminate\Http\Request;

class ThresholdController extends Controller
{
    public function store(Request $request, Team $current_team, Project $project)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:route,job,command,scheduled-task',
            'key' => 'required|string|max:255',
            'value' => 'required|integer|min:1',
        ]);

        $project->thresholds()->create($validated);

        return back()->with('success', 'Threshold created successfully.');
    }

    public function destroy(Team $current_team, Project $project, Threshold $threshold)
    {
        $this->ensureBelongsToProject($project, $threshold);

        $threshold->delete();

        return back()->with('success', 'Threshold deleted successfully.');
    }
}
