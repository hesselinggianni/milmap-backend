<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->longText('points_ciphertext')->nullable()->after('points');
            $table->longText('notes_ciphertext')->nullable()->after('notes');
            $table->decimal('start_lat', 10, 7)->nullable()->after('points_ciphertext');
            $table->decimal('start_lon', 10, 7)->nullable()->after('start_lat');
        });

        DB::table('activities')->orderBy('id')->chunkById(50, function ($rows) {
            foreach ($rows as $row) {
                $points = is_string($row->points) ? json_decode($row->points, true) : $row->points;
                DB::table('activities')->where('id', $row->id)->update([
                    'points_ciphertext' => is_array($points)
                        ? Crypt::encryptString(json_encode($points, JSON_THROW_ON_ERROR)) : null,
                    'notes_ciphertext' => $row->notes !== null ? Crypt::encryptString((string) $row->notes) : null,
                    'start_lat' => isset($points[0]['lat']) ? $points[0]['lat'] : null,
                    'start_lon' => isset($points[0]['lon']) ? $points[0]['lon'] : null,
                    'points' => null,
                    'notes' => null,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('activities')->orderBy('id')->chunkById(50, function ($rows) {
            foreach ($rows as $row) {
                $points = $row->points_ciphertext ? Crypt::decryptString($row->points_ciphertext) : null;
                $notes = $row->notes_ciphertext ? Crypt::decryptString($row->notes_ciphertext) : null;
                DB::table('activities')->where('id', $row->id)->update(['points' => $points, 'notes' => $notes]);
            }
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['points_ciphertext', 'notes_ciphertext', 'start_lat', 'start_lon']);
        });
    }
};
