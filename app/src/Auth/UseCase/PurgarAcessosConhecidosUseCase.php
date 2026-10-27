<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Repository\AcessoConhecidoRepository;
use App\Shared\Contract\ConfiguracaoDaPlataforma;

/**
 * Retenção dos acessos conhecidos (spec `login-aviso-novo.md`): apaga os não vistos há `retencao` dias, a chave do
 * catálogo do Master (365 padrão). Valor ilegível ou fora da faixa do catálogo vale o padrão. Tabela global, sem tenant.
 */
final class PurgarAcessosConhecidosUseCase
{
    private const PADRAO_DIAS = 365;
    private const MINIMO_DIAS = 30;

    public function __construct(
        private readonly AcessoConhecidoRepository $repository,
        private readonly ConfiguracaoDaPlataforma $configuracao,
    ) {
    }

    /** Em dry-run só conta. Devolve as linhas apagadas (ou que seriam). */
    public function executar(\DateTimeImmutable $agora, bool $dryRun): int
    {
        $limite = $agora->modify(sprintf('-%d days', $this->dias()));

        return $dryRun ? $this->repository->contarNaoVistosDesde($limite) : $this->repository->purgarNaoVistosDesde($limite);
    }

    private function dias(): int
    {
        $bruto = $this->configuracao->valor('retencao');

        return ctype_digit($bruto) && (int) $bruto >= self::MINIMO_DIAS ? (int) $bruto : self::PADRAO_DIAS;
    }
}
