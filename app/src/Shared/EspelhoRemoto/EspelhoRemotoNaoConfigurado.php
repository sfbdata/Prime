<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

/** O worker ou a reconciliação foram chamados sem endpoint, bucket ou credencial do espelho. */
final class EspelhoRemotoNaoConfigurado extends \RuntimeException
{
}
