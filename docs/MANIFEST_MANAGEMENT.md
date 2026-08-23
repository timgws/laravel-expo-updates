# Manifest Management Guide

## Overview

This package uses an **infinite retention policy** - all manifests are kept indefinitely. This allows:
- Complete deployment history
- Easy rollbacks to any previous version
- Analytics on update adoption rates
- Testing specific builds

## Displaying Manifests in Your Laravel App

### Basic Controller Example

```php
<?php

namespace App\Http\Controllers;

use LaravelExpoUpdates\Models\Manifest;
use LaravelExpoUpdates\Models\Project;
use Illuminate\Http\Request;

class ManifestController extends Controller
{
    /**
     * List all manifests for a project
     */
    public function index(Request $request, $projectSlug)
    {
        $project = Project::where('slug', $projectSlug)->firstOrFail();
        
        $manifests = Manifest::where('project_id', $project->id)
            ->with(['assets', 'launchAsset'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return view('manifests.index', compact('manifests', 'project'));
    }
    
    /**
     * Show a specific manifest
     */
    public function show($projectSlug, $manifestId)
    {
        $manifest = Manifest::with(['project', 'assets', 'launchAsset'])
            ->findOrFail($manifestId);
        
        return view('manifests.show', compact('manifest'));
    }
    
    /**
     * Get the latest manifest for a platform/version
     */
    public function latest($projectSlug)
    {
        $project = Project::where('slug', $projectSlug)->firstOrFail();
        $platform = request('platform', 'ios');
        $runtimeVersion = request('runtime_version', '1.0.0');
        
        $manifest = Manifest::where('project_id', $project->id)
            ->where('platform', $platform)
            ->where('runtime_version', $runtimeVersion)
            ->latest('created_at')
            ->firstOrFail();
        
        return view('manifests.show', compact('manifest'));
    }
}
```

## Advanced Queries

### Filter by Platform and Runtime Version
```php
$manifests = Manifest::where('project_id', $project->id)
    ->where('platform', 'ios')
    ->where('runtime_version', '1.0.0')
    ->orderBy('created_at', 'desc')
    ->get();
```

### Search by Commit Hash (Metadata)
```php
$manifests = Manifest::where('project_id', $project->id)
    ->where('metadata->commitHash', 'abc123def')
    ->get();
```

### Get Manifests from Last 7 Days
```php
$recentManifests = Manifest::where('project_id', $project->id)
    ->where('created_at', '>=', now()->subDays(7))
    ->orderBy('created_at', 'desc')
    ->get();
```

### Compare Two Manifests
```php
$manifest1 = Manifest::with('assets')->findOrFail($id1);
$manifest2 = Manifest::with('assets')->findOrFail($id2);

$added = $manifest2->assets->diff($manifest1->assets);
$removed = $manifest1->assets->diff($manifest2->assets);
```

### Group by Platform and Runtime Version
```php
$grouped = Manifest::where('project_id', $project->id)
    ->select('platform', 'runtime_version', DB::raw('count(*) as total'))
    ->groupBy('platform', 'runtime_version')
    ->get();
```

## Blade View Examples

### Manifest List (`resources/views/manifests/index.blade.php`)
```blade
<h1>Manifests for {{ $project->name }}</h1>

<table>
    <thead>
        <tr>
            <th>Created</th>
            <th>Platform</th>
            <th>Runtime Version</th>
            <th>Commit</th>
            <th>Assets</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($manifests as $manifest)
        <tr>
            <td>{{ $manifest->created_at->diffForHumans() }}</td>
            <td>{{ $manifest->platform }}</td>
            <td>{{ $manifest->runtime_version }}</td>
            <td>
                <code>{{ substr($manifest->metadata['commitHash'] ?? 'N/A', 0, 7) }}</code>
            </td>
            <td>{{ $manifest->assets->count() }}</td>
            <td>
                <a href="{{ route('manifests.show', [$project->slug, $manifest->id]) }}">
                    View
                </a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $manifests->links() }}
```

### Manifest Details (`resources/views/manifests/show.blade.php`)
```blade
<h1>Manifest {{ $manifest->id }}</h1>

<dl>
    <dt>Created</dt>
    <dd>{{ $manifest->created_at->toDateTimeString() }}</dd>
    
    <dt>Platform</dt>
    <dd>{{ $manifest->platform }}</dd>
    
    <dt>Runtime Version</dt>
    <dd>{{ $manifest->runtime_version }}</dd>
    
    <dt>Commit Hash</dt>
    <dd><code>{{ $manifest->metadata['commitHash'] ?? 'N/A' }}</code></dd>
    
    <dt>Commit Message</dt>
    <dd>{{ $manifest->metadata['commitMessage'] ?? 'N/A' }}</dd>
</dl>

<h2>Assets ({{ $manifest->assets->count() }})</h2>

<table>
    <thead>
        <tr>
            <th>Key</th>
            <th>Type</th>
            <th>Hash</th>
            <th>Path</th>
        </tr>
    </thead>
    <tbody>
        @foreach($manifest->assets as $asset)
        <tr>
            <td>{{ $asset->key }}</td>
            <td>{{ $asset->content_type }}</td>
            <td><code>{{ substr($asset->hash, 0, 16) }}...</code></td>
            <td>
                <code>{{ $asset->path }}</code>
                <a href="{{ $asset->url }}" target="_blank">Download</a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
```

## API Endpoints

### Route Setup (`routes/web.php`)
```php
Route::prefix('admin/expo')->group(function () {
    Route::get('projects/{project}/manifests', [ManifestController::class, 'index'])
        ->name('manifests.index');
    
    Route::get('projects/{project}/manifests/latest', [ManifestController::class, 'latest'])
        ->name('manifests.latest');
    
    Route::get('projects/{project}/manifests/{manifest}', [ManifestController::class, 'show'])
        ->name('manifests.show');
});
```

## Cleanup Strategy (Optional)

While retention is infinite by default, you may want to clean up old manifests:

### Example Cleanup Command
```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use LaravelExpoUpdates\Models\Manifest;
use Illuminate\Support\Facades\Storage;

class CleanupOldManifests extends Command
{
    protected $signature = 'expo:cleanup-old {--days=90}';
    protected $description = 'Delete manifests older than X days';

    public function handle()
    {
        $days = $this->option('days');
        $cutoff = now()->subDays($days);
        
        $manifests = Manifest::where('created_at', '<', $cutoff)->get();
        
        $this->info("Found {$manifests->count()} manifests older than {$days} days");
        
        if (!$this->confirm('Delete these manifests and their assets?')) {
            return;
        }
        
        foreach ($manifests as $manifest) {
            // Delete asset files from storage
            foreach ($manifest->assets as $asset) {
                Storage::disk(config('expo-updates.assets.disk'))->delete($asset->path);
            }
            
            // Delete manifest directory
            $manifestDir = "updates/{$manifest->id}";
            Storage::disk(config('expo-updates.assets.disk'))->deleteDirectory($manifestDir);
            
            // Delete database records
            $manifest->assets()->delete();
            $manifest->delete();
        }
        
        $this->info('Cleanup completed!');
    }
}
```

## Performance Considerations

For projects with thousands of manifests:

### Add Database Indexes
```php
Schema::table('expo_manifests', function (Blueprint $table) {
    $table->index(['project_id', 'platform', 'runtime_version', 'created_at']);
    $table->index(['created_at']);
});
```

### Use Pagination
Always paginate large result sets:
```php
$manifests = Manifest::where('project_id', $project->id)
    ->orderBy('created_at', 'desc')
    ->paginate(50); // Don't use ->get() on large datasets
```

### Eager Load Relationships
```php
// ❌ N+1 queries
$manifests = Manifest::all();
foreach ($manifests as $manifest) {
    echo $manifest->project->name; // Queries project each time
}

// ✅ Optimized
$manifests = Manifest::with('project', 'assets')->get();
```

## Analytics Examples

### Deployment Frequency
```php
$deploymentsPerDay = Manifest::where('project_id', $project->id)
    ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
    ->groupBy('date')
    ->orderBy('date', 'desc')
    ->limit(30)
    ->get();
```

### Platform Distribution
```php
$platformStats = Manifest::where('project_id', $project->id)
    ->selectRaw('platform, COUNT(*) as total')
    ->groupBy('platform')
    ->get();
```
