<?php

declare(strict_types=1);

namespace App\Tenant\Exception;

/** O vínculo pedido não é deste escritório (ou não existe): a rota responde 404, sem revelar nada. */
final class MembroDaEquipeNaoEncontrado extends \RuntimeException
{
}
