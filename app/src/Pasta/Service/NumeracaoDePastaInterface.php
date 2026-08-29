<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;

/**
 * A sequência de números de pasta de um escritório.
 *
 * Existe como interface para os dois lados que precisam concordar sobre ela — o
 * `GerarNumeroDePasta`, que atribui o próximo número, e o `ExcluirPastaUseCase`, que decide se a
 * pasta sendo excluída é a última da fila — poderem ser testados no nível certo: a implementação
 * é provada contra o Postgres de verdade, e quem a consome é provado com dublê.
 */
interface NumeracaoDePastaInterface
{
    /**
     * Serializa quem mexe na sequência deste escritório até o fim da transação.
     *
     * @throws \LogicException          se não houver transação aberta (a trava só vale dentro dela)
     * @throws \InvalidArgumentException se o escritório não estiver persistido
     */
    public function travar(Tenant $tenant): void;

    /** O maior número já usado no escritório; 0 se não há nenhum número aproveitável. */
    public function maiorNumero(Tenant $tenant): int;

    /** Existe alguma pasta do escritório com número ESTRITAMENTE maior que o deste NUP? */
    public function existeNumeroMaiorQue(Tenant $tenant, ?string $nup): bool;
}
