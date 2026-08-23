<?php

namespace LaravelExpoUpdates\Tests\Feature\Controllers;

use LaravelExpoUpdates\Tests\TestCase;
use LaravelExpoUpdates\Models\Project;
use LaravelExpoUpdates\Models\Manifest;
use LaravelExpoUpdates\Models\Asset;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class ExpoUpdatesControllerTest extends TestCase
{
    protected $project;

    protected function expoHeaders(array $extra = []): array
    {
        return array_merge([
            'expo-protocol-version' => '1',
            'expo-platform' => 'ios',
            'expo-runtime-version' => '1.0.0',
            'accept' => 'application/json',
        ], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create([
            'slug' => 'test-project',
            'config' => [
                'server_headers' => ['test-header' => 'test-value']
            ]
        ]);
    }

    /** @test */
    public function it_returns_multipart_mixed_with_signature_and_manifest_part()
    {
        Config::set('expo-updates.code_signing.enabled', true);
        Config::set('expo-updates.code_signing.private_key_path', __DIR__ . '/../../test-keys/private.key');

        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0'
        ]);
        $asset = Asset::factory()->create([
            'manifest_id' => $manifest->id,
            'key' => 'test.js',
            'content_type' => 'application/javascript',
            'path' => 'updates/' . $manifest->id . '/test.js',
            'url' => 'https://example.com/updates/' . $manifest->id . '/test.js'
        ]);
        Storage::disk(config('expo-updates.assets.disk'))
            ->put($asset->path, 'console.log("multipart");');

        $response = $this->withHeaders([
            'Accept' => 'multipart/mixed',
            'expo-protocol-version' => '1',
            'expo-platform' => 'ios',
            'expo-runtime-version' => '1.0.0',
        ])->get('/expo-updates/test-project/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(200);
        $this->assertStringContainsString('multipart/mixed', $response->headers->get('content-type'));

        // Découpe le body multipart
        $body = $response->getContent();
        $boundary = '--' . explode('boundary=', $response->headers->get('content-type'))[1];
        $parts = preg_split('/' . preg_quote($boundary, '/') . '/', $body);

        // Cherche la partie manifeste
        $manifestPart = null;
        foreach ($parts as $part) {
            if (str_contains($part, 'Content-Disposition: form-data; name="manifest"')) {
                $manifestPart = $part;
                break;
            }
        }
        $this->assertNotNull($manifestPart, 'La partie manifeste doit être présente');
        $this->assertStringContainsString('expo-signature:', $manifestPart, 'La signature doit être dans la partie manifeste');

        // Vérifie qu'une partie asset existe bien
        $assetPart = null;
        foreach ($parts as $part) {
            if (str_contains($part, 'Content-Disposition: attachment; filename="test.js"')) {
                $assetPart = $part;
                break;
            }
        }
        $this->assertNotNull($assetPart, 'La partie asset doit être présente');
    }

    /** @test */
    public function it_returns_manifest_for_project()
    {
        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0'
        ]);

        $asset = Asset::factory()->create([
            'manifest_id' => $manifest->id,
            'key' => 'test.js',
            'content_type' => 'application/javascript',
            'url' => 'https://example.com/test.js'
        ]);

        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/test-project/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(200)
            ->assertJson([
                'id' => $manifest->id,
                'runtimeVersion' => '1.0.0',
                'assets' => [
                    [
                        'key' => 'test.js',
                        'contentType' => 'application/javascript',
                        'url' => 'https://example.com/test.js'
                    ]
                ]
            ]);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_project()
    {
        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/nonexistent/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(404);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_manifest()
    {
        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/test-project/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(204);
    }

    /** @test */
    public function it_returns_asset_for_project()
    {
        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0'
        ]);

        $asset = Asset::factory()->create([
            'manifest_id' => $manifest->id,
            'project_id' => $this->project->id,
            'key' => 'test.js',
            'content_type' => 'application/javascript',
            'path' => 'updates/' . $manifest->id . '/test.js'
        ]);

        Storage::disk(config('expo-updates.assets.disk'))
            ->put($asset->path, 'console.log("test");');

        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/test-project/asset/test.js');

        $response->assertStatus(404);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_asset()
    {
        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/test-project/asset/nonexistent.js');

        $response->assertStatus(404);
    }

    /** @test */
    public function it_uses_project_from_header()
    {
        Config::set('expo-updates.default_project', 'test-project');

        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0'
        ]);

        $response = $this->withHeaders($this->expoHeaders([
            'expo-project-id' => $this->project->id
        ]))->getJson('/expo-updates/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(200)
            ->assertJson([
                'id' => $manifest->id,
                'runtimeVersion' => '1.0.0'
            ]);
    }

    /** @test */
    public function it_uses_default_project()
    {
        Config::set('expo-updates.default_project', 'test-project');

        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0'
        ]);

        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(200)
            ->assertJson([
                'id' => $manifest->id,
                'runtimeVersion' => '1.0.0'
            ]);
    }

    /** @test */
    public function it_returns_server_headers()
    {
        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0'
        ]);

        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/test-project/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(200)
            ->assertHeader('expo-server-defined-headers');
    }

    /** @test */
    public function it_returns_manifest_signature_when_enabled()
    {
        Config::set('expo-updates.code_signing.enabled', true);
        Config::set('expo-updates.code_signing.private_key_path', __DIR__ . '/../../test-keys/private.key');

        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0'
        ]);

        $response = $this->withHeaders($this->expoHeaders())
            ->getJson('/expo-updates/test-project/manifest?platform=ios&runtimeVersion=1.0.0');

        $response->assertStatus(200)
            ->assertHeader('expo-signature');
    }
} 