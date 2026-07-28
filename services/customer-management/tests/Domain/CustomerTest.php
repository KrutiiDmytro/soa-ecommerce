<?php

namespace App\Tests\Domain;

use App\Domain\Address;
use App\Domain\Customer;
use App\Domain\Email;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    public function testRegisterCreatesIdentifiableCustomer(): void
    {
        $customer = Customer::register(new Email('Jane@Example.com'), 'secret123', 'Jane Doe');

        self::assertNotEmpty($customer->id());
        self::assertSame('jane@example.com', $customer->email());
        self::assertSame('Jane Doe', $customer->fullName());
    }

    public function testPasswordVerification(): void
    {
        $customer = Customer::register(new Email('a@b.com'), 'secret123');

        self::assertTrue($customer->verifyPassword('secret123'));
        self::assertFalse($customer->verifyPassword('wrong'));
    }

    public function testAddAddressAndCanonical(): void
    {
        $customer = Customer::register(new Email('a@b.com'), 'secret123', 'A B');
        $customer->addAddress(new Address('Main 1', null, 'Kyiv', '01001', 'UA'));

        self::assertCount(1, $customer->addresses());

        $canonical = $customer->toCanonical();
        self::assertSame('a@b.com', $canonical['email']);
        self::assertSame('A B', $canonical['fullName']);
        self::assertCount(1, $canonical['address']);
        self::assertSame('Kyiv', $canonical['address'][0]['city']);
        self::assertArrayNotHasKey('line2', $canonical['address'][0]);
    }

    public function testInvalidEmailRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Email('not-an-email');
    }

    public function testShortPasswordRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Customer::register(new Email('a@b.com'), '123');
    }
}
