<?php

declare(strict_types=1);

namespace App\ServiceDesk\Service;

/**
 * Fronteira de transação do razão do saldo: o UseCase trava, muda e grava dentro dela, e os testes
 * unitários trocam por uma que só executa o closure.
 */
interface TransacaoDoRazao
{
    public function emTransacao(\Closure $trabalho): mixed;
}
