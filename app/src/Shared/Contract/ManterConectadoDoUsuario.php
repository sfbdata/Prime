<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/** Encerra o "Manter conectado" (todas as séries do cookie) de uma pessoa, junto com a gravação que pede isso. */
interface ManterConectadoDoUsuario
{
    /**
     * Apaga as séries do usuário e, em seguida, roda `$gravar`, na MESMA transação.
     *
     * @template T
     *
     * @param callable(): T $gravar
     *
     * @return T
     */
    public function encerrarJunto(string $identificador, callable $gravar): mixed;
}
