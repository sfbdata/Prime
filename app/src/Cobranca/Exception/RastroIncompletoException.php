<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Uma correção foi aplicada sem o evento de histórico correspondente (INV-R3, SPEC §17.11).
 *
 * 🔑 **Lançada DENTRO da transação, antes do flush** — e essa posição é o achado de uma revisão. A
 * primeira versão conferia isto no relatório do comando, **depois** de `confirmar()` retornar: naquele
 * ponto o `wrapInTransaction` já havia comitado, e a mensagem dizia "a transação foi revertida" sobre
 * dinheiro que estava gravado. Conferir depois do commit não é conferir; é narrar.
 *
 * Sem evento não há como achar nem desfazer a correção depois — e uma reconciliação de dinheiro sem
 * rastro é pior do que reconciliação nenhuma, porque parece feita.
 */
final class RastroIncompletoException extends \RuntimeException
{
    public function __construct(
        public readonly int $casosCorrigidos,
        public readonly int $eventosRegistrados,
    ) {
        parent::__construct(sprintf(
            'RASTRO INCOMPLETO: %d caso(s) corrigido(s) e só %d evento(s) de histórico. Sem o evento a '
            . 'correção não teria como ser achada nem desfeita. Nada foi gravado.',
            $casosCorrigidos,
            $eventosRegistrados,
        ));
    }
}
