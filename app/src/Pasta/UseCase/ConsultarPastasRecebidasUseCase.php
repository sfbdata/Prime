<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\DTO\PastasRecebidasOutput;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\Service\CodigoPessoal;
use Psr\Clock\ClockInterface;

/**
 * A caixa "Recebidas" da pessoa (spec acesso-restrito-e-compartilhar §4.5, fatia 8; desenho L9 e L10): o código pessoal
 * (sorteado e gravado na primeira vez), quantos convites a aguardam e os convites de outras contas para ela, de
 * qualquer escritório. É da pessoa, não do escritório da sessão: só olha o `destino`.
 */
final class ConsultarPastasRecebidasUseCase
{
    public function __construct(
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly CodigoPessoal $codigoPessoal,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $pessoa): PastasRecebidasOutput
    {
        $hoje = $this->relogio->now();
        $itens = array_map(
            static fn ($c): array => PastasRecebidasOutput::item($c, $hoje),
            $this->compartilhamentos->recebidasPor($pessoa),
        );

        return new PastasRecebidasOutput(
            $this->codigoPessoal->garantir($pessoa),
            $this->compartilhamentos->pendentesPara($pessoa, $hoje),
            $itens,
        );
    }
}
