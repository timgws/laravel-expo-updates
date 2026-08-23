<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expo_update_stats', function (Blueprint $table) {
            // Add manifest_id column
            $table->uuid('manifest_id')->nullable()->after('project_id');
            
            // Add index for performance
            $table->index('manifest_id');
            
            // Add foreign key constraint
            $table->foreign('manifest_id')
                ->references('id')
                ->on('expo_manifests')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expo_update_stats', function (Blueprint $table) {
            $table->dropForeign(['manifest_id']);
            $table->dropIndex(['manifest_id']);
            $table->dropColumn('manifest_id');
        });
    }
};
