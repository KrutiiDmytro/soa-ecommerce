<?php

namespace App\Security;

/** Порушення політики безпеки на вході в ESB (мапиться на SOAP Fault). */
final class SecurityPolicyViolation extends \RuntimeException
{
}
