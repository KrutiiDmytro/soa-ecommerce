<?php

namespace App\Soap;

use App\Application\CustomerService;
use App\Domain\Address;
use App\Domain\CustomerException;
use App\Iam\TokenIssuer;

/**
 * SOAP-обробник: методи відповідають операціям WSDL (document/literal wrapped).
 * PHP SoapServer передає один об'єкт-запит; повертаємо масив, що відповідає *Response.
 * Доменні винятки мапляться на SOAP Fault.
 */
final class CustomerSoapHandler
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly TokenIssuer $tokens,
    ) {
    }

    public function RegisterCustomer(object $request): array
    {
        return $this->guard(fn (): array => [
            'customerId' => $this->customers->register(
                $request->email,
                $request->password,
                $request->fullName ?? null,
            ),
        ]);
    }

    public function Authenticate(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $customer = $this->customers->authenticate($request->email, $request->password);

            return [
                'customerId' => $customer->id(),
                'token' => $this->tokens->issue($customer->id(), $customer->email()),
            ];
        });
    }

    public function GetCustomer(object $request): array
    {
        return $this->guard(fn (): array => [
            'customer' => $this->customers->get($request->customerId)->toCanonical(),
        ]);
    }

    public function UpdateAddress(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $a = $request->address;
            $address = new Address($a->line1, $a->line2 ?? null, $a->city, $a->postalCode, $a->country);
            $customer = $this->customers->addAddress($request->customerId, $address);

            return [
                'customerId' => $customer->id(),
                'addressCount' => \count($customer->addresses()),
            ];
        });
    }

    /**
     * @param callable():array $operation
     *
     * @return array
     */
    private function guard(callable $operation): array
    {
        try {
            return $operation();
        } catch (CustomerException $e) {
            throw new \SoapFault('Client', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            throw new \SoapFault('Client', $e->getMessage());
        }
    }
}
