<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Services\IssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    protected IssueService $issueService;

    public function __construct(IssueService $issueService)
    {
        $this->issueService = $issueService;
    }

    /**
     * Display a listing of project issues.
     */
    public function index(Request $request, Team $current_team, Project $project): Response
    {
        $filters = $request->only(['status', 'search']);

        return Inertia::render('projects/issues/index', [
            'issues' => $this->issueService->getPaginatedIssues($project, $filters),
            'filters' => array_merge(['status' => 'open'], $filters),
            'counts' => $this->issueService->getIssueCounts($project),
            'performance' => $this->issueService->getPerformanceStats($project),
            'team_members' => $current_team->members,
        ]);
    }

    /**
     * Display the specified issue.
     */
    public function show(Team $current_team, Project $project, Issue $issue): Response
    {
        $this->ensureBelongsToProject($project, $issue);

        $issue->load(['assignee', 'records' => fn ($q) => $q->latest()->limit(1), 'activities.user']);

        return Inertia::render('projects/issues/show', [
            'issue' => $issue,
            'team_members' => $current_team->members,
        ]);
    }

    /**
     * Update the specified issue (status, priority, assignment).
     */
    public function update(Request $request, Team $current_team, Project $project, Issue $issue): RedirectResponse
    {
        $this->ensureBelongsToProject($project, $issue);

        $validated = $request->validate([
            'status' => 'sometimes|string|in:open,resolved,ignored',
            'priority' => 'sometimes|string|in:none,low,medium,high,critical',
            'assigned_to' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where(
                fn ($query) => $query->whereIn('id', $current_team->members()->pluck('users.id'))
            )],
        ]);

        $this->issueService->updateIssue($issue, $validated);

        return back()->with('success', 'Issue updated successfully.');
    }

    /**
     * Add a comment/activity to the issue.
     */
    public function comment(Request $request, Team $current_team, Project $project, Issue $issue): RedirectResponse
    {
        $this->ensureBelongsToProject($project, $issue);

        $validated = $request->validate(['comment' => 'required|string']);

        $this->issueService->addComment($issue, $validated['comment']);

        return back()->with('success', 'Comment added.');
    }
}
