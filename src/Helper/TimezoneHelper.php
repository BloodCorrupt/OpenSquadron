<?php

namespace App\Helper;

class TimezoneHelper
{
    /**
     * Normalizes a timezone offset or name.
     * E.g. "+6" -> "+06:00", "-5" -> "-05:00", "6" -> "+06:00", "+5.5" -> "+05:30"
     * If it is a valid timezone name (like "Asia/Dhaka", "UTC"), returns it.
     * Returns "UTC" if invalid.
     */
    public static function normalizeTimezone(string $tzString): string
    {
        $tzString = trim($tzString);
        if ($tzString === '' || strcasecmp($tzString, 'UTC') === 0) {
            return 'UTC';
        }

        // Try parsing short/incorrect/correct offset formats first (e.g. +6, -5, 6, +6:00, 5.5, +5.5)
        if (preg_match('/^([+-]?)\s*(\d+(?:\.\d+)?|\d+:\d+)$/', $tzString, $matches)) {
            $sign = $matches[1] === '-' ? '-' : '+';
            $timePart = $matches[2];

            if (strpos($timePart, ':') !== false) {
                list($hours, $minutes) = explode(':', $timePart);
                $normalized = sprintf('%s%02d:%02d', $sign, (int)$hours, (int)$minutes);
            } else {
                $val = (float)$timePart;
                $hours = (int)floor($val);
                $minutes = (int)round(($val - $hours) * 60);
                $normalized = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
            }

            // Verify the normalized offset is valid
            try {
                new \DateTimeZone($normalized);
                return $normalized;
            } catch (\Exception $e) {
                // Invalid offset
            }
        }

        // Check if it is a valid standard timezone name (e.g. Asia/Dhaka)
        try {
            new \DateTimeZone($tzString);
            return $tzString;
        } catch (\Exception $e) {
            // Not a direct timezone name
        }

        return 'UTC';
    }

    /**
     * Returns a valid \DateTimeZone object for the given timezone string.
     */
    public static function getValidDateTimeZone(string $tzString): \DateTimeZone
    {
        return new \DateTimeZone(self::normalizeTimezone($tzString));
    }
}
