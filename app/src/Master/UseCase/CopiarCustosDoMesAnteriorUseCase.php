<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Master\Exception\CustoRecusado;
use App\Master\Repository\LancamentoDeCustoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * "Copiar do mês anterior" (desenho `csCopiar`, spec master-custos D6): leva os lançamentos do mês anterior para o mês
 * escolhido. O que era `medido` vira `estimado` (o valor deixou de ser medido); o resto mantém a origem. Devolve quantos
 * foram copiados; mês anterior sem lançamento é recusado.
 */
final class CopiarCustosDoMesAnteriorUseCase
{
    public function __construct(
        private readonly LancamentoDeCustoRepository $lancamentos,
        private readonly ClockInterface $clock,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(string $mes, ?User $ator): int
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) !== 1) {
            throw new CustoRecusado('Escolha o mês.');
        }
        $anterior = (new \DateTimeImmutable($mes . '-15'))->modify('-1 month')->format('Y-m');
        $origem = $this->lancamentos->doMes($anterior);
        if ($origem === []) {
            throw new CustoRecusado('Não há lançamentos no mês anterior.');
        }

        $agora = \DateTimeImmutable::createFromInterface($this->clock->now());
        $copias = array_map(fn (LancamentoDeCusto $l): LancamentoDeCusto => new LancamentoDeCusto(
            $mes,
            $l->getCategoria(),
            $l->getValorCentavos(),
            $l->getOrigem() === OrigemDoCusto::Medido ? OrigemDoCusto::Estimado : $l->getOrigem(),
            $l->getFornecedor(),
            $l->getObservacao(),
            $agora,
            $ator,
        ), $origem);
        $this->escopoDePlataforma->executar(fn () => $this->lancamentos->salvarVarios($copias));

        return count($origem);
    }
}
