<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MailAccount — one IMAP/SMTP mailbox an admin can log into from the mail client.
 *
 * Credentials are stored AES-encrypted at rest via the `encrypted` cast and are
 * listed in $hidden so they are never serialised back to the client. Only the
 * server ever decrypts them (to open an IMAP/SMTP connection). Each inbox keeps
 * its own HTML signature so outgoing mail can be branded per-inbox.
 */
class MailAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'from_name',
        'email',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'imap_validate_cert',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'signature_html',
        'is_active',
        'last_synced_at',
    ];

    /**
     * Never leak the stored secrets through JSON. The frontend only learns
     * *whether* a password is set (see toClientArray()), never its value.
     */
    protected $hidden = [
        'imap_password',
        'smtp_password',
    ];

    protected function casts(): array
    {
        return [
            'imap_password'      => 'encrypted',
            'smtp_password'      => 'encrypted',
            'imap_validate_cert' => 'boolean',
            'is_active'          => 'boolean',
            'imap_port'          => 'integer',
            'smtp_port'          => 'integer',
            'last_synced_at'     => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The effective IMAP login. Falls back to the mailbox address when no
     * explicit username is configured (most providers accept the address).
     */
    public function imapLogin(): string
    {
        return $this->imap_username ?: $this->email;
    }

    public function smtpLogin(): string
    {
        return $this->smtp_username ?: $this->email;
    }

    /**
     * Safe representation for API responses — no secrets, just a flag telling
     * the UI whether each password is already stored so the edit form can show
     * a "leave blank to keep" placeholder.
     */
    public function toClientArray(): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'from_name'          => $this->from_name,
            'email'              => $this->email,
            'imap_host'          => $this->imap_host,
            'imap_port'          => $this->imap_port,
            'imap_encryption'    => $this->imap_encryption,
            'imap_username'      => $this->imap_username,
            'imap_validate_cert' => $this->imap_validate_cert,
            'smtp_host'          => $this->smtp_host,
            'smtp_port'          => $this->smtp_port,
            'smtp_encryption'    => $this->smtp_encryption,
            'smtp_username'      => $this->smtp_username,
            'signature_html'     => $this->signature_html,
            'is_active'          => $this->is_active,
            'has_imap_password'  => filled($this->imap_password),
            'has_smtp_password'  => filled($this->smtp_password),
            'last_synced_at'     => $this->last_synced_at?->toIso8601String(),
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
