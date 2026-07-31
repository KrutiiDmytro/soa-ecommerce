<?php

namespace App\Domain;

/** Провайдер відмовив у платежі (очікувана ситуація, не збій інтеграції). */
final class PaymentDeclined extends \RuntimeException
{
}
