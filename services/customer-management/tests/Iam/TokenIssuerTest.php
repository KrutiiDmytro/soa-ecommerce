<?php

namespace App\Tests\Iam;

use App\Iam\InvalidTokenException;
use App\Iam\TokenIssuer;
use PHPUnit\Framework\TestCase;

final class TokenIssuerTest extends TestCase
{
    public function testIssueAndVerifyRoundTrip(): void
    {
        $issuer = new TokenIssuer('test-secret');
        $token = $issuer->issue('cust-1', 'a@b.com');

        $claims = $issuer->verify($token);

        self::assertSame('cust-1', $claims['sub']);
        self::assertSame('a@b.com', $claims['email']);
        self::assertSame('customer-management', $claims['iss']);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = (new TokenIssuer('secret-a'))->issue('cust-1', 'a@b.com');

        $this->expectException(InvalidTokenException::class);
        (new TokenIssuer('secret-b'))->verify($token);
    }
}
