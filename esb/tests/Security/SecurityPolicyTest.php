<?php

namespace App\Tests\Security;

use App\Gateway\InspectedMessage;
use App\Security\SecurityPolicy;
use App\Security\SecurityPolicyViolation;
use App\Security\TokenVerifier;
use PHPUnit\Framework\TestCase;

/** ESB як Policy Enforcement Point: токен IAM + свіжий timestamp на бізнес-операціях. */
final class SecurityPolicyTest extends TestCase
{
    private const SECRET = 'test_iam_secret';

    private function policy(): SecurityPolicy
    {
        return new SecurityPolicy(new TokenVerifier(self::SECRET));
    }

    private function token(int $expiresIn = 3600, string $secret = self::SECRET): string
    {
        $b64 = static fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode(['sub' => 'customer-1', 'email' => 'buyer@example.com', 'exp' => time() + $expiresIn]));

        return $header.'.'.$payload.'.'.$b64(hash_hmac('sha256', $header.'.'.$payload, $secret, true));
    }

    private function message(string $operation, ?string $token, ?string $created = 'now'): InspectedMessage
    {
        return new InspectedMessage(
            namespace: 'urn:printzone:soa:esb-checkout:v1',
            operation: $operation,
            token: $token,
            username: null,
            createdAt: $created === 'now' ? gmdate('Y-m-d\TH:i:s\Z') : $created,
            expiresAt: null,
        );
    }

    public function testPublicOperationNeedsNoToken(): void
    {
        self::assertSame([], $this->policy()->enforce($this->message('SearchProducts', null, null)));
    }

    public function testBusinessOperationWithValidTokenPasses(): void
    {
        $claims = $this->policy()->enforce($this->message('Checkout', $this->token()));

        self::assertSame('customer-1', $claims['sub']);
        self::assertSame('buyer@example.com', $claims['email']);
    }

    public function testBusinessOperationWithoutTokenIsRejected(): void
    {
        $this->expectException(SecurityPolicyViolation::class);
        $this->policy()->enforce($this->message('Checkout', null));
    }

    public function testForeignSignatureIsRejected(): void
    {
        $this->expectException(SecurityPolicyViolation::class);
        $this->policy()->enforce($this->message('Checkout', $this->token(3600, 'someone_elses_secret')));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->expectException(SecurityPolicyViolation::class);
        $this->policy()->enforce($this->message('Checkout', $this->token(-10)));
    }

    public function testMissingTimestampIsRejected(): void
    {
        $this->expectException(SecurityPolicyViolation::class);
        $this->policy()->enforce($this->message('Checkout', $this->token(), null));
    }

    public function testStaleTimestampIsRejected(): void
    {
        $this->expectException(SecurityPolicyViolation::class);
        $this->policy()->enforce($this->message('Checkout', $this->token(), gmdate('Y-m-d\TH:i:s\Z', time() - 3600)));
    }
}
