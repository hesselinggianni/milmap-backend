<?php

use App\Support\TrailRoutePresenter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Routes ontdekken" liet bij uitzoomen per verzoek de volledige geometry van elke route
 * in beeld laden en decoderen (±1 s en 48 MB voor ~1200 routes) om er 40 punten uit te
 * halen. Die preview staat nu in een eigen kolom; TrailRoute houdt hem bij het opslaan bij.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trail_routes', function (Blueprint $table) {
            $table->json('preview')->nullable()->after('geometry');
            $table->unsignedInteger('point_count')->default(0)->after('preview');
        });

        // Bestaande routes krijgen hun preview in één keer, in kleine porties (de geometry is zwaar).
        DB::table('trail_routes')->select('id', 'geometry')->orderBy('id')->chunkById(50, function ($rows) {
            foreach ($rows as $row) {
                $computed = TrailRoutePresenter::previewFor(json_decode((string) $row->geometry, true));

                DB::table('trail_routes')->where('id', $row->id)->update([
                    'preview' => json_encode($computed['preview']),
                    'point_count' => $computed['point_count'],
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('trail_routes', function (Blueprint $table) {
            $table->dropColumn(['preview', 'point_count']);
        });
    }
};
