<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kosten/financiën-grootboek voor de admin. Elke post is een inkomst (+) of
 * kost (−), eenmalig of terugkerend (maand/jaar). Losstaand van Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_entries', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            // 'income' (+) of 'expense' (−)
            $table->string('direction')->default('expense');
            // Altijd positief opgeslagen; 'direction' bepaalt het teken.
            $table->decimal('amount', 12, 2)->default(0);
            // 'once' | 'monthly' | 'yearly'
            $table->string('recurrence')->default('once');
            $table->string('category')->nullable();
            // Eenmalig: datum van de betaling. Terugkerend: startdatum.
            $table->date('incurred_on');
            // Optionele einddatum voor terugkerende posten (leeg = doorlopend).
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('direction');
            $table->index('recurrence');
            $table->index('incurred_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_entries');
    }
};
