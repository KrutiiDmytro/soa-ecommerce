<?php

namespace App\Iam;

/** Токен ідентичності не пройшов перевірку (формат/підпис/термін дії). */
final class InvalidTokenException extends \RuntimeException
{
}
