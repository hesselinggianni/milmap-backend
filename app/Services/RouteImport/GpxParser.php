<?php

namespace App\Services\RouteImport;

use RuntimeException;

class GpxParser
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @return array<int, array<int, array{0: float, 1: float}>> segments of [lon, lat]
     */
    public function parse(string $path): array
    {
        if (filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('GPX-bestand is te groot');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException('Ongeldige GPX');
        }

        $xml->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
        $segments = [];

        foreach ($xml->xpath('//g:trkseg') ?: [] as $segment) {
            $segment->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
            $points = [];

            foreach ($segment->xpath('g:trkpt') ?: [] as $point) {
                $lat = (float) $point['lat'];
                $lon = (float) $point['lon'];

                if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                    throw new RuntimeException('Coördinaat buiten bereik');
                }

                $points[] = [$lon, $lat];
            }

            if (count($points) >= 2) {
                $segments[] = $points;
            }
        }

        if ($segments === []) {
            throw new RuntimeException('Geen trackpunten gevonden');
        }

        return $segments;
    }
}
