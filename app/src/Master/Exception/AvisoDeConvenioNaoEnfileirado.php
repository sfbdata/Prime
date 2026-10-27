<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Os e-mails de aviso do convênio OAB não entraram na fila: a mudança foi desfeita (mesma transação). O controller
 * devolve 500 com a mensagem do catálogo.
 */
final class AvisoDeConvenioNaoEnfileirado extends \RuntimeException
{
}
