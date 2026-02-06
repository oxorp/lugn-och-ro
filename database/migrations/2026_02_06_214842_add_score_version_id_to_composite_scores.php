<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('composite_scores', function (Blueprint $table) {
            $table->foreignId('score_version_id')->nullable()->constrained()->after('id');
        });

        // Drop old unique constraint and add one that includes version
        Schema::table('composite_scores', function (Blueprint $table) {
            $table->dropUnique(['deso_code', 'year']);
            $table->unique(['deso_code', 'year', 'score_version_id']);
        });
    }

    public function down(): void
    {
        Schema::table('composite_scores', function (Blueprint $table) {
            $table->dropUnique(['deso_code', 'year', 'score_version_id']);
            $table->unique(['deso_code', 'year']);
            $table->dropConstrainedForeignId('score_version_id');
        });
    }
};
