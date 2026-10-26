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

        return self::montar($tenantId, $bytesUsados, $espaco, $espaco?->getPlano() ?? $this->planos->free(), $this->configuracoes->atual());
    }

    /**
     * Vários escritórios de uma vez (painel do Master, b5): uma consulta para os espaços, o Free e a
     * configuração lidos uma vez só, em vez de N a 2N consultas.
     *
     * @param array<int, int> $bytesPorTenant id do escritório → bytes usados
     *
     * @return array<int, RetratoDoEspaco> na mesma ordem das chaves recebidas
     */
    public function retratarVarios(array $bytesPorTenant): array
    {
        if ($bytesPorTenant === []) {
            return [];
        }
        $espacos = $this->espacos->dosTenants(array_keys($bytesPorTenant));
        $free = null;
        $configuracao = $this->configuracoes->atual();
        $retratos = [];
        foreach ($bytesPorTenant as $tenantId => $bytes) {
            $espaco = $espacos[$tenantId] ?? null;
            $plano = $espaco?->getPlano() ?? ($free ??= $this->planos->free());
            $retratos[$tenantId] = self::montar($tenantId, $bytes, $espaco, $plano, $configuracao);
        }

        return $retratos;
    }

    private static function montar(int $tenantId, int $bytesUsados, ?EspacoDoEscritorio $espaco, Plano $plano, ConfiguracaoDoEspaco $configuracao): RetratoDoEspaco
    {
        [$bytesLimite, $origem] = self::limiteEmBytes($espaco, $plano);
        $percentual = $bytesLimite === null ? null : (int) round($bytesUsados / $bytesLimite * 100);
        [$limiteUsuarios, $origemDoLimiteDeUsuarios] = self::limiteDeUsuarios($espaco, $plano);
        $bytesBloqueio = $bytesLimite === null ? null : intdiv($bytesLimite * $configuracao->getFaixaBloqueio(), 100);

        return new RetratoDoEspaco(
            $tenantId,
            $bytesUsados,
            $bytesLimite,
            $percentual,
            self::faixa($percentual, $configuracao, $bytesBloqueio !== null && $bytesUsados >= $bytesBloqueio),
            $origem,
            $plano->getCodigo(),
            $plano->getNome(),
            $limiteUsuarios,
            $origemDoLimiteDeUsuarios,
            $configuracao->isBloqueioAtivo(),
            $bytesBloqueio,
        );
    }

    /**
     * "Sem limite" dispensa as duas checagens, espaço e usuários (decisão do dono, 19/10/2026: o
     * escritório do dono do sistema tem mais usuários que qualquer plano).
     *
     * @return array{0: ?int, 1: OrigemDoLimite}
     */
    private static function limiteDeUsuarios(?EspacoDoEscritorio $espaco, Plano $plano): array
    {
        if ($espaco?->isSemLimite() === true) {
            return [null, OrigemDoLimite::SemLimite];
        }
        if ($espaco?->getLimiteUsuarios() !== null) {
            return [$espaco->getLimiteUsuarios(), OrigemDoLimite::Master];
        }

        // D5 revogada (dono, 26/10/2026): o plano não limita usuários. A coluna do plano fica como está; só o limite
        // próprio que o Master define para a conta ainda vale.
        return [null, OrigemDoLimite::Plano];
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

    /**
     * A faixa Bloqueio vem dos bytes exatos, como o bloqueio em si: 99,5% arredonda para 100 mas ainda
     * aceita envio, então ainda é Crítico. As demais faixas vêm do percentual arredondado do desenho.
     */
    private static function faixa(?int $percentual, ConfiguracaoDoEspaco $c, bool $atingiuBloqueio): FaixaDoEspaco
    {
        return match (true) {
            $percentual === null => FaixaDoEspaco::Livre,
            $atingiuBloqueio => FaixaDoEspaco::Bloqueio,
            $percentual >= $c->getFaixaCritico() => FaixaDoEspaco::Critico,
            $percentual >= $c->getFaixaAlerta() => FaixaDoEspaco::Alerta,
            $percentual >= $c->getFaixaAtencao() => FaixaDoEspaco::Atencao,
            default => FaixaDoEspaco::Livre,
        };
    }
}
