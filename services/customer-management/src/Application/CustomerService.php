<?php

namespace App\Application;

use App\Domain\Address;
use App\Domain\Customer;
use App\Domain\CustomerException;
use App\Domain\CustomerRepository;
use App\Domain\Email;

/** Application-сервіс: сценарії роботи з клієнтом (без знання про транспорт/SOAP). */
final class CustomerService
{
    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    public function register(string $email, string $password, ?string $fullName): string
    {
        $emailVo = new Email($email);
        if ($this->customers->byEmail($emailVo->value) !== null) {
            throw CustomerException::emailAlreadyUsed($emailVo->value);
        }

        $customer = Customer::register($emailVo, $password, $fullName);
        $this->customers->save($customer);

        return $customer->id();
    }

    public function authenticate(string $email, string $password): Customer
    {
        $customer = $this->customers->byEmail($email);
        if ($customer === null || !$customer->verifyPassword($password)) {
            throw CustomerException::invalidCredentials();
        }

        return $customer;
    }

    public function get(string $id): Customer
    {
        return $this->customers->byId($id) ?? throw CustomerException::notFound($id);
    }

    public function addAddress(string $id, Address $address): Customer
    {
        $customer = $this->get($id);
        $customer->addAddress($address);
        $this->customers->save($customer);

        return $customer;
    }
}
