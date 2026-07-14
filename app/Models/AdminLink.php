<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snelkoppeling naar een partner-/derdepartijsite in de admin-sidebar,
 * optioneel met inloggegevens.
 *
 * Beveiliging: het wachtwoord wordt met Laravels encrypted-cast opgeslagen
 * (AES-256-CBC met de APP_KEY). Er is bewust géén KeePass-integratie — dit is
 * een gemakskluisje voor partnerportalen; voor kritieke accounts blijft een
 * echte wachtwoordmanager het advies. Het wachtwoord gaat nooit mee in de
 * lijst-API; alleen het aparte reveal-endpoint ontsleutelt on-demand.
 */
class AdminLink extends Model
{
    protected $table = 'admin_links';

    protected $fillable = [
        'label', 'url', 'username', 'password', 'notes', 'position',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'position' => 'integer',
    ];

    /** Lijst-weergave zonder geheimen: alleen óf er een wachtwoord is. */
    public function toApiArray(): array
    {
        return [
            'id'           => $this->id,
            'label'        => $this->label,
            'url'          => $this->url,
            'username'     => $this->username,
            'has_password' => ! empty($this->password),
            'notes'        => $this->notes,
            'position'     => $this->position,
        ];
    }
}
