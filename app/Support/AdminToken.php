<?php

namespace App\Support;

class AdminToken
{
    public static function issue(): string
    {
        $payload = self::b64(json_encode(['exp' => time() + config('admin.token_ttl')]));

        return $payload . '.' . self::sign($payload);
    }

    public static function valid(?string $token): bool
    {
        if (!$token || substr_count($token, '.') !== 1) {
            return false;
        }

        [$payload, $signature] = explode('.', $token);

        if (!hash_equals(self::sign($payload), $signature)) {
            return false;
        }

        $data = json_decode(self::unb64($payload), true);

        return is_array($data) && isset($data['exp']) && $data['exp'] > time();
    }

    private static function sign(string $payload): string
    {
        return self::b64(hash_hmac('sha256', $payload, (string) config('app.key'), true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }
}