<?php

namespace App\Security;

use App\Gateway\InspectedMessage;

/**
 * ESB як Policy Enforcement Point (Task 27 §06): перевіряє <wsse:Security> —
 * токен ідентичності від IAM + свіжість wsu:Timestamp — ЄДИНИЙ раз, на вході.
 *
 * Спрощення (задокументоване): XML-DSig не робимо, автентичність повідомлення
 * підтверджує підписаний токен, а не підпис самого XML.
 */
final class SecurityPolicy
{
    private const CLOCK_SKEW = 300;

    /** Операції, доступні без токена: без них його ніде було б узяти. */
    private const PUBLIC_OPERATIONS = [
        'Authenticate',
        'RegisterCustomer',
        'SearchProducts',
        'GetProduct',
        'CheckStock',
    ];

    public function __construct(private readonly TokenVerifier $tokens)
    {
    }

    /** @return array<string, mixed> claims виклику (порожні для публічних операцій) */
    public function enforce(InspectedMessage $message): array
    {
        if (\in_array($message->operation, self::PUBLIC_OPERATIONS, true)) {
            return [];
        }

        if ($message->token === null) {
            throw new SecurityPolicyViolation(sprintf(
                'Operation "%s" requires a <wsse:Security> header with an identity token',
                $message->operation,
            ));
        }

        $this->assertFreshTimestamp($message);

        return $this->tokens->verify($message->token);
    }

    private function assertFreshTimestamp(InspectedMessage $message): void
    {
        if ($message->createdAt === null) {
            throw new SecurityPolicyViolation('<wsu:Timestamp> with <wsu:Created> is required');
        }

        $created = strtotime($message->createdAt);

        if ($created === false) {
            throw new SecurityPolicyViolation('<wsu:Created> is not a valid timestamp');
        }
        if (abs(time() - $created) > self::CLOCK_SKEW) {
            throw new SecurityPolicyViolation('Message timestamp is outside the allowed window (replay protection)');
        }

        $expires = $message->expiresAt === null ? null : strtotime($message->expiresAt);

        if ($expires !== false && $expires !== null && time() > $expires) {
            throw new SecurityPolicyViolation('Message has expired (<wsu:Expires>)');
        }
    }
}
