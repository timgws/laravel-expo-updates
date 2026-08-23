<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('expo_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('expo_manifests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('platform');
            $table->string('runtime_version');
            $table->json('metadata')->nullable();
            $table->json('extra')->nullable();
            $table->uuid('launch_asset_id')->nullable();
            $table->timestamps();

            $table->foreign('project_id')
                ->references('id')
                ->on('expo_projects')
                ->onDelete('cascade');

            $table->index(['project_id', 'platform', 'runtime_version']);
        });
        
        Schema::create('expo_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('key');
            $table->string('content_type');
            $table->string('file_extension')->nullable();
            $table->string('path');
            $table->string('hash')->nullable();
            $table->string('url');
            $table->uuid('manifest_id')->nullable();
            $table->timestamps();

            $table->foreign('project_id')
                ->references('id')
                ->on('expo_projects')
                ->onDelete('cascade');

            $table->foreign('manifest_id')
                ->references('id')
                ->on('expo_manifests')
                ->onDelete('cascade');

            $table->unique(['project_id', 'key']);
        });

        Schema::create('expo_update_stats', function (Blueprint $table) {
            $table->id();

            $table->uuid('project_id');

            $table->string('platform');
            $table->string('runtime_version');
            // 'request' or 'upgrade'
            $table->string('type');
            $table->integer('count')->default(1);
            $table->date('date');
            $table->timestamps();

            $table->foreign('project_id')
                ->references('id')
                ->on('expo_projects')
                ->cascadeOnDelete();

            // Ensure we only have one stat per project/platform/version/type/date
            $table->unique(['project_id', 'platform', 'runtime_version', 'type', 'date'], 'expo_stats_unique_index');
        });


    }

    public function down()
    {
        Schema::dropIfExists('expo_update_stats');
        Schema::dropIfExists('expo_assets');
        Schema::dropIfExists('expo_manifests');
        Schema::dropIfExists('expo_projects');
    }
}; 
