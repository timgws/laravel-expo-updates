<?php

namespace LaravelExpoUpdates\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelExpoUpdates\Services\StatsService;
use LaravelExpoUpdates\Models\Project;

/**
 * Middleware for tracking update requests.
 */
class TrackUpdateRequests
{
    protected $statsService;

    /**
     * Create a new middleware instance.
     *
     * @param StatsService $statsService
     */
    public function __construct(StatsService $statsService)
    {
        $this->statsService = $statsService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only track manifest requests
        if ($request->is('*/manifest')) {
            $project = $request->route('projectSlug')
                ? Project::where('slug', $request->route('projectSlug'))->first()
                : Project::find($request->header('expo-project-id'));

            if ($project) {
                // Get platform and runtime version from headers (Expo protocol)
                $platform = $request->header('expo-platform');
                $runtimeVersion = $request->header('expo-runtime-version');
                
                // Extract manifest ID from response
                $manifestId = null;
                if ($response->getStatusCode() === 200) {
                    $contentType = $response->headers->get('Content-Type', '');
                    $content = $response->getContent();
                    
                    // For multipart/mixed responses, extract JSON part
                    if (str_contains($contentType, 'multipart/mixed')) {
                        // Find the manifest ID in the JSON
                        // Pattern 1: With signature: expo-signature, blank line, then {"id":"...
                        // Pattern 2: Without signature: headers end with blank line, then {"id":"...
                        if (preg_match('/\r?\n\r?\n(\{"id":"([a-f0-9\-]+)")/i', $content, $matches)) {
                            $manifestId = $matches[2];
                        }
                    } else {
                        // For JSON responses
                        $manifestData = json_decode($content, true);
                        $manifestId = $manifestData['id'] ?? null;
                    }
                }
                
                $this->statsService->recordRequest(
                    $project,
                    $platform,
                    $runtimeVersion,
                    $manifestId
                );
            }
        }

        return $response;
    }
} 