<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtHelper
{
    private static function secret(): string
    {
        return $_ENV['JWT_SECRET'] ?? 'vincula_secret_key';
    }

    public static function generate(array $payload): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + (60 * 60 * 24); // 24 horas

        return JWT::encode($payload, self::secret(), 'HS256');
    }

    public static function validate(string $token): object|false
    {
        try {
            return JWT::decode($token, new Key(self::secret(), 'HS256'));
        } catch (\Exception $e) {
            return false;
        }
    }
}