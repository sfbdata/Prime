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
     * escritório do dono do sistema tem mais usuários que qualquer plano). O limite do Master segue a regra
     * do espaço: sempre no Free; num plano pago, só quando é maior (plano sem limite continua sem limite).
     *
     * @return array{0: ?int, 1: OrigemDoLimite}
     */
    private static function limiteDeUsuarios(?EspacoDoEscritorio $espaco, Plano $plano): array
    {
        if ($espaco?->isSemLimite() === true) {
            return [null, OrigemDoLimite::SemLimite];
        }
        $doPlano = $plano->getLimiteUsuarios();
        $doMaster = $espaco?->getLimiteUsuarios();
        if ($doMaster !== null && ($plano->ehGratuito() || ($doPlano !== null && $doMaster > $doPlano))) {
            return [$doMaster, OrigemDoLimite::Master];
        }

        return [$doPlano, OrigemDoLimite::Plano];
    }

    /**
     * O limite do Master vale sempre no Free. Num plano PAGO (só a assinatura põe o escritório nele) vale
     * só quando é maior que o do plano: quem paga nunca fica com menos do que pagou (C3, 19/10: um limite
     * próprio de 24 GB não pode engolir os 50 GB de uma assinatura).
     *
     * @return array{0: ?int, 1: OrigemDoLimite}
     */
    private static function limiteEmBytes(?EspacoDoEscritorio $espaco, Plano $plano): array
    {
        if ($espaco?->isSemLimite() === true) {
            return [null, OrigemDoLimite::SemLimite];
        }
        $doPlano = $plano->getCotaGb();
        $doMaster = $espaco?->getCotaGb();
        if ($doMaster !== null && ($plano->ehGratuito() || $doMaster > $doPlano)) {
            return [$doMaster * RetratoDoEspaco::BYTES_POR_GB, OrigemDoLimite::Master];
        }

        return [$doPlano * RetratoDoEspaco::BYTES_POR_GB, OrigemDoLimite::Plano];
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
