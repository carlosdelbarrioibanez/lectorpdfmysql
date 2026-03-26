<?php

declare(strict_types=1);

namespace App\Utils;

final class NumberNormalizer
{
    public static function normalizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        $normalized = preg_replace('/[^\d,\.\-]/u', '', $normalized) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === ',' || $normalized === '.') {
            return null;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $parts = explode('.', $normalized);
            if (count($parts) > 2) {
                $decimal = array_pop($parts);
                $normalized = implode('', $parts) . '.' . $decimal;
            }
        }

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }
}
