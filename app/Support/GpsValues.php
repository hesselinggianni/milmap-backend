<?php

namespace App\Support;

/**
 * iOS/Android geven heading = -1 en speed = -1 als de waarde onbekend is
 * (stilstand, geen kompas). Dat is geen ongeldige invoer maar "geen waarde";
 * zonder deze normalisatie weigerde de validatie de hele positie (422).
 * Niet-numerieke rommel blijft ongemoeid, zodat de validatie die weigert.
 */
final class GpsValues
{
    /** Negatief wordt null; 360 en meer wordt 0–360. */
    public static function heading(mixed $value): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }
        $heading = (float) $value;
        return $heading < 0 ? null : fmod($heading, 360);
    }

    public static function nonNegative(mixed $value): mixed
    {
        return is_numeric($value) && (float) $value < 0 ? null : $value;
    }

    /** Normaliseer heading/speed/accuracy in één punt, alleen als ze aanwezig zijn. */
    public static function normalize(array $point): array
    {
        if (array_key_exists('heading', $point)) $point['heading'] = self::heading($point['heading']);
        if (array_key_exists('speed', $point)) $point['speed'] = self::nonNegative($point['speed']);
        if (array_key_exists('accuracy', $point)) $point['accuracy'] = self::nonNegative($point['accuracy']);
        return $point;
    }
}
