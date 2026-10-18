<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * PNG-kaarttegel → WebP, met de eerste beschikbare encoder:
 *
 *   1. GD met WebP-ondersteuning (php-gd op Debian/Ubuntu heeft dat standaard)
 *   2. Imagick met WEBP-delegate
 *   3. het `cwebp`-commando van libwebp (apt install webp / brew install webp),
 *      pad via PDOK_CWEBP_BIN
 *   4. python3 met Pillow (laatste redmiddel, o.a. de lokale XAMPP-PHP heeft
 *      een GD zonder WebP)
 *
 * Is er géén encoder, dan blijft de tegel PNG (de proxy serveert wat er op
 * schijf staat) en loggen we dat één keer per proces. Kwaliteit uit
 * config('services.pdok.webp_quality'); 100 = lossless.
 */
final class TileWebp
{
    private static ?string $encoder = null;

    /** Python-commando waarmee Pillow werkt (zie probePillow). */
    private static array $python = ['python3'];

    private static bool $probed = false;

    /** 'gd' | 'imagick' | 'cwebp' | null */
    public static function encoder(): ?string
    {
        if (self::$probed) {
            return self::$encoder;
        }
        self::$probed = true;

        if (function_exists('imagewebp') && ! empty(gd_info()['WebP Support'])) {
            return self::$encoder = 'gd';
        }
        if (class_exists(\Imagick::class)) {
            try {
                if (\Imagick::queryFormats('WEBP')) {
                    return self::$encoder = 'imagick';
                }
            } catch (Throwable) {
                // geen WEBP-delegate
            }
        }
        $bin = (string) config('services.pdok.cwebp', 'cwebp');
        try {
            if ($bin !== '' && Process::run([$bin, '-version'])->successful()) {
                return self::$encoder = 'cwebp';
            }
        } catch (Throwable) {
            // niet gevonden / niet uitvoerbaar
        }

        if (self::probePillow()) {
            return self::$encoder = 'pillow';
        }

        Log::warning('PDOK-tegels: geen WebP-encoder gevonden (GD zonder WebP, geen Imagick, geen cwebp, geen Pillow) — tegels blijven PNG.');

        return self::$encoder = null;
    }

    public static function available(): bool
    {
        return self::encoder() !== null;
    }

    /** @return string|null WebP-bytes, of null als het (nu) niet lukt. */
    public static function encode(string $png, ?int $quality = null): ?string
    {
        $quality = max(1, min(100, $quality ?? (int) config('services.pdok.webp_quality', 85)));

        try {
            return match (self::encoder()) {
                'gd'      => self::viaGd($png, $quality),
                'imagick' => self::viaImagick($png, $quality),
                'cwebp'   => self::viaCwebp($png, $quality),
                'pillow'  => self::viaPillow($png, $quality),
                default   => null,
            };
        } catch (Throwable $e) {
            Log::warning('PDOK-tegel WebP-conversie mislukt: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Pillow met WebP via python3. Een x86_64-PHP (XAMPP onder Rosetta) start
     * python3 ook als x86_64 en kan dan een arm64-Pillow niet laden; vandaar
     * als tweede poging `arch -arm64 python3`. Op Linux-servers is dit alleen
     * een laatste redmiddel — daar zijn GD of cwebp de normale route.
     */
    private static function probePillow(): bool
    {
        $check = 'from PIL import features; import sys; sys.exit(0 if features.check("webp") else 1)';

        foreach ([['python3'], ['/usr/bin/arch', '-arm64', '/Applications/Xcode.app/Contents/Developer/usr/bin/python3'], ['/usr/bin/arch', '-arm64', '/usr/bin/python3']] as $python) {
            try {
                // cwd = de tegelmap, zodat python geen module uit een projectmap oppikt.
                if (Process::path(self::safeCwd())->run([...$python, '-c', $check])->successful()) {
                    self::$python = $python;

                    return true;
                }
            } catch (Throwable) {
                // niet beschikbaar — volgende kandidaat
            }
        }

        return false;
    }

    private static function viaGd(string $png, int $quality): ?string
    {
        $im = @imagecreatefromstring($png);
        if ($im === false) {
            return null;
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);

        // PHP ≥ 8.1: IMG_WEBP_LOSSLESS als kwaliteit = lossless.
        $q = $quality >= 100 && defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : $quality;

        ob_start();
        $ok = imagewebp($im, null, $q);
        $out = ob_get_clean();
        imagedestroy($im);

        return $ok && $out !== '' ? $out : null;
    }

    private static function viaImagick(string $png, int $quality): ?string
    {
        $im = new \Imagick();
        $im->readImageBlob($png);
        $im->setImageFormat('webp');
        if ($quality >= 100) {
            $im->setOption('webp:lossless', 'true');
        } else {
            $im->setImageCompressionQuality($quality);
        }
        $im->setOption('webp:method', '4');
        $out = $im->getImageBlob();
        $im->clear();

        return $out !== '' ? $out : null;
    }

    private static function viaPillow(string $png, int $quality): ?string
    {
        // PNG via stdin, WebP via stdout. Geen -I (Pillow staat vaak in de
        // user-site), wel cwd = tegelmap zodat er geen projectmodule geladen wordt.
        $script = 'import sys; from PIL import Image; im = Image.open(sys.stdin.buffer); '
            . 'im = im.convert("RGBA" if im.mode in ("P", "LA", "RGBA") else "RGB"); '
            . 'q = int(sys.argv[1]); '
            . 'im.save(sys.stdout.buffer, "WEBP", **({"lossless": True} if q >= 100 else {"quality": q}), method=4)';
        $result = Process::path(self::safeCwd())->timeout(20)->input($png)
            ->run([...self::$python, '-c', $script, (string) $quality]);
        $out = $result->output();

        return $result->successful() && $out !== '' ? $out : null;
    }

    private static function safeCwd(): string
    {
        $dir = storage_path('app/tiles');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) ? $dir : sys_get_temp_dir();
    }

    private static function viaCwebp(string $png, int $quality): ?string
    {
        $bin = (string) config('services.pdok.cwebp', 'cwebp');
        $in  = tempnam(sys_get_temp_dir(), 'tile-') . '.png';
        $out = substr($in, 0, -4) . '.webp';

        try {
            file_put_contents($in, $png);
            $args = $quality >= 100
                ? [$bin, '-quiet', '-lossless', '-m', '4', $in, '-o', $out]
                : [$bin, '-quiet', '-q', (string) $quality, '-m', '4', '-alpha_q', '100', $in, '-o', $out];
            $result = Process::timeout(20)->run($args);
            if (! $result->successful() || ! is_file($out)) {
                return null;
            }
            $bytes = file_get_contents($out);

            return $bytes !== false && $bytes !== '' ? $bytes : null;
        } finally {
            @unlink($in);
            @unlink($out);
            @unlink(substr($in, 0, -4)); // tempnam-bestand zonder extensie
        }
    }
}
