<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/** Encerra o "Manter conectado" (todas as séries do cookie) de uma pessoa, junto com a gravação que pede isso. */
interface ManterConectadoDoUsuario
{
    /**
     * Roda `$gravar` e apaga as séries do usuário na MESMA transação.
     *
     * @template T
     *
     * @param callable(): T $gravar
     *
     * @return T
     */
    public function encerrarJunto(string $identificador, callable $gravar): mixed;
}
