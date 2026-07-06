<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIngestedRecords;
use App\Models\Project;
use App\Rules\PublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IngestController extends Controller
{
    /**
     * Handle incoming data from monitored projects.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');

        $data = $request->has('records') ? $request->input('records') : $request->all();

        if (! is_array($data)) {
            return response()->json(['message' => 'Invalid payload structure.'], 422);
        }

        // Support both single record and collection of records
        $records = isset($data[0]) ? $data : [$data];

        // Auto-update project URL if not set
        if ($request->has('app_url') && ! $project->url) {
            $validator = Validator::make(
                ['app_url' => $request->input('app_url')],
                ['app_url' => ['url', new PublicUrl]]
            );

            if ($validator->passes()) {
                $project->update(['url' => $request->input('app_url')]);
            }
        }

        ProcessIngestedRecords::dispatch($project, $records);

        return response()->json(['message' => 'Data ingested successfully.'], 200);
    }
}
