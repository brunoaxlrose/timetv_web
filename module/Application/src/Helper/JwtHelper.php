<?php

namespace Application\Helper;

class JwtHelper {
    public static function encode(array $payload, string $secret, int $ttlSeconds): string {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttlSeconds;
        $payloadPart = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $header . '.' . $payloadPart, $secret, true);

        return $header . '.' . $payloadPart . '.' . self::base64UrlEncode($signature);
    }

    public static function decode(string $jwt, string $secret): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($data)) {
            return null;
        }

        if (!empty($data['exp']) && (int)$data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
