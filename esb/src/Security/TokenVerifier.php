<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перевірка токена ідентичності, який видав централізований IAM (Customer Management).
 * Спільний секрет IAM_TOKEN_SECRET; ESB лише ПЕРЕВІРЯЄ, видача лишається в CM.
 */
final class TokenVerifier
{
    public function __construct(
        #[Autowire('%env(IAM_TOKEN_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    /** @return array<string, mixed> claims */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (\count($parts) !== 3) {
            throw new SecurityPolicyViolation('Malformed identity token');
        }
        [$header, $payload, $signature] = $parts;

        if (!hash_equals(self::b64(hash_hmac('sha256', $header.'.'.$payload, $this->secret, true)), $signature)) {
            throw new SecurityPolicyViolation('Identity token signature is invalid');
        }

        $claims = json_decode(self::b64d($payload), true);

        if (!\is_array($claims)) {
            throw new SecurityPolicyViolation('Identity token payload is invalid');
        }
        if (isset($claims['exp']) && time() >= (int) $claims['exp']) {
            throw new SecurityPolicyViolation('Identity token has expired');
        }

        return $claims;
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
