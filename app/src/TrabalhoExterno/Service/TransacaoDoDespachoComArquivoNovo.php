<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;

/** {@see TransacaoDoDespacho} pela `TransacaoComArquivoNovo` da casa. */
final class TransacaoDoDespachoComArquivoNovo implements TransacaoDoDespacho
{
    public function __construct(
        private readonly TransacaoComArquivoNovo $transacao,
    ) {
    }

    public function executar(\Closure $passos, \Closure $arquivosNovos): mixed
    {
        return $this->transacao->executar($passos, $arquivosNovos, 'DespacharNaDemandaUseCase: anexos do despacho da Central');
    }
}
