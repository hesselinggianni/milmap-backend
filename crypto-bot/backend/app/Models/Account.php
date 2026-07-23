<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['name', 'mode', 'base_currency', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function walletBalances(): HasMany
    {
        return $this->hasMany(WalletBalance::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function strategyConfigs(): HasMany
    {
        return $this->hasMany(StrategyConfig::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
    }
}
