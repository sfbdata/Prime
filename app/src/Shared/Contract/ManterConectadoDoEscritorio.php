<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Encerra o "Manter conectado" (as séries do cookie) de todos os usuários vinculados a um escritório, junto com a
 * gravação que tira o escritório do ar: exclusão ou suspensão pelo Master, exclusão do escritório e o pedido de
 * exclusão do primário (auditoria 3.29, decisão do Coordenador de 05/11/2026). Quem também é de outro escritório só
 * entra com a senha de novo.
 */
interface ManterConectadoDoEscritorio
{
    /**
     * Roda `$gravar` e apaga as séries na MESMA transação: se a gravação falha, as séries ficam; se o apagar falha, a
     * gravação volta.
     *
     * @template T
     *
     * @param callable(): T $gravar
     *
     * @return T
     */
    public function encerrarJunto(int $tenantId, callable $gravar): mixed;
}
