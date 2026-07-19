<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All bot domain tables. Every monetary/quantity column is DECIMAL(30,10) and
 * is read back into brick/math BigDecimal at use-site — never a float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('mode', ['paper', 'live'])->default('paper');
            $table->string('base_currency', 10)->default('EUR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 20);
            $table->decimal('available', 30, 10)->default(0);
            $table->decimal('reserved', 30, 10)->default(0);
            $table->timestamps();
            $table->unique(['account_id', 'asset']);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('market', 20);
            $table->decimal('quantity', 30, 10)->default(0);
            $table->decimal('avg_entry_price', 30, 10)->default(0);
            $table->decimal('stop_loss_price', 30, 10)->nullable();
            $table->decimal('take_profit_price', 30, 10)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('realized_pnl', 30, 10)->default(0);
            $table->timestamps();
            $table->index(['account_id', 'market', 'status']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('market', 20);
            $table->enum('side', ['buy', 'sell']);
            $table->enum('type', ['market', 'limit'])->default('market');
            $table->decimal('requested_qty', 30, 10);
            $table->decimal('limit_price', 30, 10)->nullable();
            $table->enum('status', ['pending', 'filled', 'partially_filled', 'rejected', 'cancelled'])->default('pending');
            $table->string('exchange_order_id')->nullable();
            $table->string('client_order_id')->unique(); // idempotency key
            $table->string('reject_reason')->nullable();
            $table->foreignId('strategy_config_id')->nullable();
            $table->json('signal_meta')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });

        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('market', 20);
            $table->enum('side', ['buy', 'sell']);
            $table->decimal('price', 30, 10);
            $table->decimal('quantity', 30, 10);
            $table->decimal('fee', 30, 10)->default(0);
            $table->string('fee_asset', 20)->default('EUR');
            $table->timestamp('executed_at');
            $table->timestamps();
            $table->index(['account_id', 'executed_at']);
        });

        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->string('market', 20);
            $table->string('interval', 10);
            $table->unsignedBigInteger('open_time'); // unix ms
            $table->decimal('open', 30, 10);
            $table->decimal('high', 30, 10);
            $table->decimal('low', 30, 10);
            $table->decimal('close', 30, 10);
            $table->decimal('volume', 30, 10);
            $table->timestamps();
            $table->unique(['market', 'interval', 'open_time']);
        });

        Schema::create('strategy_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('strategy_key', 50);
            $table->string('market', 20);
            $table->json('params')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['account_id', 'is_active']);
        });

        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->decimal('equity_eur', 30, 10);
            $table->decimal('cash_eur', 30, 10);
            $table->decimal('positions_value_eur', 30, 10);
            $table->decimal('unrealized_pnl', 30, 10)->default(0);
            $table->decimal('realized_pnl_cum', 30, 10)->default(0);
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['account_id', 'captured_at']);
        });

        Schema::create('event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable();
            $table->string('type', 40);
            $table->enum('level', ['info', 'warning', 'critical'])->default('info');
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['account_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'event_logs', 'portfolio_snapshots', 'strategy_configs', 'candles',
            'trades', 'orders', 'positions', 'wallet_balances', 'accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
