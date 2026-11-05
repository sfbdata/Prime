<?php

declare(strict_types=1);

namespace App\Tests\Security\Doubles;

use App\Shared\Contract\ManterConectadoDoEscritorio;

/** Roda a gravação e anota para qual escritório o "Manter conectado" seria encerrado. */
final class ManterConectadoDoEscritorioEspiao implements ManterConectadoDoEscritorio
{
    /** @var list<int> */
    public array $escritorios = [];

    public function encerrarJunto(int $tenantId, callable $gravar): mixed
    {
        $resultado = $gravar();
        $this->escritorios[] = $tenantId;

        return $resultado;
    }
}
