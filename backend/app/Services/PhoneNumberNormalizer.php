<?php

namespace App\Services;

class PhoneNumberNormalizer
{
    public function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        $length = strlen($digits);

        if (! str_starts_with($digits, '62') || $length < 10 || $length > 15) {
            return null;
        }

        return $digits;
    }

    public function toWaId(string $normalizedPhone): string
    {
        return "{$normalizedPhone}@s.whatsapp.net";
    }
}
