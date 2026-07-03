<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    /**
     * Abort with a 404 unless the given model belongs to the given project.
     *
     * Route-model-bound resources (issues, integrations, alert rules, etc.) are looked
     * up by their global primary key, so this guards against cross-project/tenant access.
     */
    protected function ensureBelongsToProject(Project $project, Model $model): void
    {
        abort_unless($model->project_id === $project->id, 404);
    }
}
