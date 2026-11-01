<?php

namespace App\Services\RouteImport;

class LineSimplifier
{
    /**
     * Douglas-Peucker, iteratief om stack-overflow bij lange tracks te voorkomen.
     *
     * @param array<int, array{0: float, 1: float}> $points
     * @return array<int, array{0: float, 1: float}>
     */
    public function simplify(array $points, float $tolerance): array
    {
        $count = count($points);

        if ($count < 3 || $tolerance <= 0) {
            return $points;
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = $keep[$count - 1] = true;
        $stack = [[0, $count - 1]];

        while ($stack) {
            [$start, $end] = array_pop($stack);
            $maxDistance = 0.0;
            $index = 0;

            for ($i = $start + 1; $i < $end; $i++) {
                $distance = $this->perpendicularDistance($points[$i], $points[$start], $points[$end]);

                if ($distance > $maxDistance) {
                    $maxDistance = $distance;
                    $index = $i;
                }
            }

            if ($maxDistance > $tolerance) {
                $keep[$index] = true;
                $stack[] = [$start, $index];
                $stack[] = [$index, $end];
            }
        }

        $result = [];
        foreach ($points as $i => $point) {
            if ($keep[$i]) {
                $result[] = [round($point[0], 6), round($point[1], 6)];
            }
        }

        return $result;
    }

    private function perpendicularDistance(array $point, array $lineStart, array $lineEnd): float
    {
        $dx = $lineEnd[0] - $lineStart[0];
        $dy = $lineEnd[1] - $lineStart[1];
        $lengthSquared = $dx * $dx + $dy * $dy;

        if ($lengthSquared == 0.0) {
            return hypot($point[0] - $lineStart[0], $point[1] - $lineStart[1]);
        }

        $t = max(0, min(1, (($point[0] - $lineStart[0]) * $dx + ($point[1] - $lineStart[1]) * $dy) / $lengthSquared));

        return hypot($point[0] - ($lineStart[0] + $t * $dx), $point[1] - ($lineStart[1] + $t * $dy));
    }
}
