<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/** O webhook não trouxe a credencial do gateway (token, assinatura HMAC): é recusado sem processar nada. */
final class AvisoNaoAutenticado extends \RuntimeException
{
}
