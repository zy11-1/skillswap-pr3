<?php
declare(strict_types=1);

namespace App\Utils;

use Exception;

/**
 * Minimal JWT (JSON Web Token) implementation using HS256.
 *
 * In a typical Composer-based Slim project you would normally just
 * `composer require firebase/php-jwt` and use that library instead.
 * We implement it by hand here so the project has zero external
 * dependencies and is easy to run anywhere PHP is installed.
 *
 * The public encode()/decode() signatures intentionally mirror
 * firebase/php-jwt so this class can be swapped out later with
 * minimal changes if you want to use the "real" library.
 */
class Jwt
{
    /**
     * Create a signed JWT.
     *
     * @param array $payload Claims to embed (e.g. user_id, role, exp)
     * @param string $secret Shared secret key
     * @return string The encoded JWT (header.payload.signature)
     */
    public static function encode(array $payload, string $secret): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];

        $segments = [];
        $segments[] = self::base64UrlEncode((string) json_encode($header));
        $segments[] = self::base64UrlEncode((string) json_encode($payload));

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Verify and decode a JWT.
     *
     * @param string $jwt The encoded token
     * @param string $secret Shared secret key
     * @return array The decoded payload
     * @throws Exception if the token is malformed, the signature is
     *                    invalid, or the token has expired
     */
    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new Exception('Malformed token');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $signingInput = $headerB64 . '.' . $payloadB64;
        $expectedSignature = hash_hmac('sha256', $signingInput, $secret, true);
        $actualSignature = self::base64UrlDecode($signatureB64);

        // Constant-time comparison to avoid timing attacks
        if (!hash_equals($expectedSignature, $actualSignature)) {
            throw new Exception('Invalid token signature');
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new Exception('Invalid token payload');
        }

        if (isset($payload['exp']) && time() >= $payload['exp']) {
            throw new Exception('Token has expired');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return base64_decode(strtr($padded, '-_', '+/')) ?: '';
    }
}
