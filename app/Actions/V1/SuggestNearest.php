<?php

namespace App\Actions\V1;

class SuggestNearest
{
    /**
     * @param  list<string>  $allowed
     */
    public function handle(string $value, array $allowed): ?string
    {
        $value = str_replace([' ', '_'], '-', mb_strtolower($value));

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $nearest = null;
        $nearestDistance = PHP_INT_MAX;

        foreach ($allowed as $candidate) {
            $distance = levenshtein($value, $candidate);
            $threshold = mb_strlen($candidate) >= 5 ? 2 : 1;

            if ($distance <= $threshold && $distance < $nearestDistance) {
                $nearest = $candidate;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }
}
