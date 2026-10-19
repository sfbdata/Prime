<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;

/**
 * Monta o retrato do espaço de um escritório: uso medido, limite efetivo, faixa e plano.
 *
 * É a leitura pública do domínio (contrato com o Painel do Master, domínio Perfis): ninguém fora do
 * Contribua lê as tabelas de plano e espaço direto.
 */
final class SituacaoDoEspaco
{
    public function __construct(
        private readonly ConsumoDeArmazenamentoRepository $consumo,
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly PlanoRepository $planos,
        private readonly ConfiguracaoDoEspacoRepository $configuracoes,
    ) {
    }

    public function doEscritorio(int $tenantId): RetratoDoEspaco
    {
        return $this->retratar($tenantId, $this->consumo->bytesDoEscritorio($tenantId));
    }

    /** Mesmo retrato, com o uso já medido (o decorador mede uma vez e reaproveita). */
    public function retratar(int $tenantId, int $bytesUsados): RetratoDoEspaco
    {
        $espaco = $this->espacos->doTenant($tenantId);
        $plano = $espaco?->getPlano() ?? $this->planos->free();
        $configuracao = $this->configuracoes->atual();
        [$bytesLimite, $origem] = self::limiteEmBytes($espaco, $plano);
        $percentual = $bytesLimite === null ? null : (int) round($bytesUsados / $bytesLimite * 100);

        return new RetratoDoEspaco(
            $tenantId,
            $bytesUsados,
            $bytesLimite,
            $percentual,
            self::faixa($percentual, $configuracao),
            $origem,
            $plano->getCodigo(),
            $plano->getNome(),
            $espaco?->getLimiteUsuarios() ?? $plano->getLimiteUsuarios(),
            $espaco?->getLimiteUsuarios() !== null ? OrigemDoLimite::Master : OrigemDoLimite::Plano,
            $configuracao->isBloqueioAtivo(),
        );
    }

    /** @return array{0: ?int, 1: OrigemDoLimite} */
    private static function limiteEmBytes(?EspacoDoEscritorio $espaco, Plano $plano): array
    {
        if ($espaco?->isSemLimite() === true) {
            return [null, OrigemDoLimite::SemLimite];
        }
        if ($espaco?->getCotaGb() !== null) {
            return [$espaco->getCotaGb() * RetratoDoEspaco::BYTES_POR_GB, OrigemDoLimite::Master];
        }

        return [$plano->getCotaGb() * RetratoDoEspaco::BYTES_POR_GB, OrigemDoLimite::Plano];
    }

    private static function faixa(?int $percentual, ConfiguracaoDoEspaco $c): FaixaDoEspaco
    {
        return match (true) {
            $percentual === null => FaixaDoEspaco::Livre,
            $percentual >= $c->getFaixaBloqueio() => FaixaDoEspaco::Bloqueio,
            $percentual >= $c->getFaixaCritico() => FaixaDoEspaco::Critico,
            $percentual >= $c->getFaixaAlerta() => FaixaDoEspaco::Alerta,
            $percentual >= $c->getFaixaAtencao() => FaixaDoEspaco::Atencao,
            default => FaixaDoEspaco::Livre,
        };
    }
}
