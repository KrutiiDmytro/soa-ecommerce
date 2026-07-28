<?php

namespace App\Iam;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Централізований IAM: видача та перевірка підписаного токена ідентичності (JWT HS256).
 * Секрет спільний (env IAM_TOKEN_SECRET) — ESB перевіряє токен тим самим ключем.
 * Реалізація self-contained, щоб не тягнути зовнішню JWT-залежність.
 */
final class TokenIssuer
{
    private const TTL = 3600;

    public function __construct(
        #[Autowire('%env(IAM_TOKEN_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    public function issue(string $customerId, string $email): string
    {
        $now = time();
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode([
            'iss' => 'customer-management',
            'sub' => $customerId,
            'email' => $email,
            'iat' => $now,
            'exp' => $now + self::TTL,
        ], JSON_THROW_ON_ERROR));
        $signature = self::sign($header.'.'.$payload);

        return $header.'.'.$payload.'.'.$signature;
    }

    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            throw new InvalidTokenException('Malformed token');
        }
        [$header, $payload, $signature] = $parts;

        if (!hash_equals(self::sign($header.'.'.$payload), $signature)) {
            throw new InvalidTokenException('Invalid signature');
        }

        $claims = json_decode(self::b64d($payload), true);
        if (!\is_array($claims)) {
            throw new InvalidTokenException('Invalid payload');
        }
        if (isset($claims['exp']) && time() >= (int) $claims['exp']) {
            throw new InvalidTokenException('Token expired');
        }

        return $claims;
    }

    private function sign(string $data): string
    {
        return self::b64(hash_hmac('sha256', $data, $this->secret, true));
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64d(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
