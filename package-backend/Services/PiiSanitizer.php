<?php

namespace App\Services;

class PiiSanitizer
{
    /**
     * Sanitizes sensitive Personally Identifiable Information (PII) from text.
     * Masked items include: KTP/NIK, Indonesian phone numbers, emails, credit cards, PIN/passwords.
     */
    public static function sanitize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // 1. Password / PIN / OTP / Token / Secrets
        $text = preg_replace_callback(
            '/\b(?:pin|otp|password|kata\s*sandi|token|secret)\s*[:=]\s*(\S+)/i',
            function ($m) {
                return str_replace($m[1], '[PIN/PASSWORD_DISAMARKAN]', $m[0]);
            },
            $text
        );

        // 2. KTP / NIK (16 digit angka, format 4x4 atau 16 digit berurutan)
        $text = preg_replace('/\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{4}\b|\b\d{16}\b/', '[NOMOR_KTP_DISAMARKAN]', $text);

        // 3. Nomor Telepon / HP (+62, 62, 08xx...)
        $text = preg_replace('/(?:\+?62|0)8[0-9]{1,3}[ -]?[0-9]{3,4}[ -]?[0-9]{3,5}\b/', '[NOMOR_HP_DISAMARKAN]', $text);

        // 4. Email
        $text = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '[EMAIL_DISAMARKAN]', $text);

        // 5. Kartu Kredit / Debit (13 to 19 digit)
        $text = preg_replace('/\b(?:\d{4}[ -]?){3}\d{4}\b/', '[KARTU_KREDIT_DISAMARKAN]', $text);

        return $text;
    }

    /**
     * Checks if the given text contains any sensitive PII.
     */
    public static function containsPii(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        return self::sanitize($text) !== $text;
    }

    /**
     * Recursively sanitizes strings in an array or object.
     */
    public static function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $data[$key] = self::sanitize($value);
            }
        }
        return $data;
    }
}
