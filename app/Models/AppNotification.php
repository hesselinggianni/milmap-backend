<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AppNotification extends Model
{
    protected $table = 'app_notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'payload', 'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'read_at' => 'datetime',
    ];

    /**
     * Create a notification for a single user. Best-effort: never throws, so a
     * notification failure can't break the request/job that triggered it.
     */
    public static function notifyUser(int $userId, string $type, string $title, ?string $body = null, array $payload = []): void
    {
        try {
            static::create([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[notify] notifyUser failed: ' . $e->getMessage());
        }
    }

    /**
     * Fan a notification out to every admin (users.is_admin). Used for events
     * that aren't tied to one account — new support tickets, feature requests.
     * Best-effort and de-duplicated per admin id.
     */
    public static function notifyAdmins(string $type, string $title, ?string $body = null, array $payload = []): void
    {
        try {
            $adminIds = User::where('is_admin', true)->pluck('id');
            foreach ($adminIds as $uid) {
                static::notifyUser((int) $uid, $type, $title, $body, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('[notify] notifyAdmins failed: ' . $e->getMessage());
        }
    }
}
