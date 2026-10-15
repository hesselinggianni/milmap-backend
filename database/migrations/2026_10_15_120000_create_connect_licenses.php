<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};

return new class extends Migration {
    public function up(): void {
        Schema::create('connect_licenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained('connect_organizations')->cascadeOnDelete();
            $t->string('name', 160)->default('Enterprise-licentie');
            $t->unsignedInteger('seat_count');
            $t->unsignedBigInteger('monthly_price_cents')->default(0);
            $t->char('currency', 3)->default('EUR');
            $t->dateTime('starts_at'); $t->dateTime('ends_at');
            $t->string('source_reference')->nullable()->unique();
            $t->timestamps();
            $t->index(['organization_id', 'starts_at', 'ends_at']);
        });
        DB::table('connect_organizations')->orderBy('id')->chunkById(100, function ($organizations) {
            foreach ($organizations as $org) {
                if (!$org->starts_at || !$org->ends_at) continue;
                DB::table('connect_licenses')->insert([
                    'organization_id' => $org->id, 'name' => 'Enterprise-licentie',
                    'seat_count' => $org->seat_limit, 'monthly_price_cents' => $org->monthly_price_cents,
                    'currency' => $org->currency, 'starts_at' => $org->starts_at, 'ends_at' => $org->ends_at,
                    'source_reference' => 'legacy:'.$org->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
    public function down(): void { Schema::dropIfExists('connect_licenses'); }
};
