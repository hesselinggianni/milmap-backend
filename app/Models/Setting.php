<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Simple JSON key/value settings store. Values are JSON-encoded on write and
 * decoded on read, so arrays/scalars round-trip transparently.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Read a setting, decoding the stored JSON. Returns $default when unset.
     */
    public static function get(string $key, $default = null)
    {
        $row = static::find($key);
        if (! $row) {
            return $default;
        }

        $decoded = json_decode((string) $row->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
    }

    /**
     * Write a setting (JSON-encoded). Creates or updates the row.
     */
    public static function put(string $key, $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value)]
        );
    }
}
