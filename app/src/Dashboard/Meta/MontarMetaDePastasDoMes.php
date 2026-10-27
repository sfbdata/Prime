<?php

declare(strict_types=1);

namespace App\Dashboard\Meta;

use App\Agenda\Service\DiasUteisNacionais;
use App\Dashboard\DTO\MetaDePastasDoMesOutput;
use App\Dashboard\Repository\DashboardMetaPastasRepository;
use App\Entity\Tenant\Tenant;

/**
 * Monta a meta de pastas do mês para o card "Pastas criadas" (DASH-016; desenho `nivelador`).
 *
 * Sempre devolve o estado do mês corrente (o editor do botão direito funciona em qualquer recorte), mas só
 * marca `visivel` quando o painel está no mês inteiro, sem responsável nem cargo (`mesLimpo` do desenho): com
 * outro recorte o número do card não é o do escritório e a meta não se compara a ele.
 *
 * O realizado é o número do próprio card (soma das linhas), recebido pronto: a regra de contagem não se
 * duplica aqui. O ritmo conta dias úteis do `DiasUteisNacionais` (fonte única do sistema: fim de semana,
 * feriado nacional e recesso de 20/12 a 20/01); decisão PROVISÓRIA do coordenador (o desenho conta dias
 * corridos), o dono pode rever. Mês sem nenhum dia útil (não ocorre; só por garantia)
 * cai nos dias corridos.
 */
// Não-final: os testes de UseCase dublam este montador.
class MontarMetaDePastasDoMes
{
    public function __construct(
        private readonly DashboardMetaPastasRepository $repository,
        private readonly DiasUteisNacionais $diasUteis,
    ) {
    }

    /**
     * @param array<string, mixed> $filtros os do painel (data_de, data_ate, responsavel, cargo)
     */
    public function montar(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros, int $realizado): MetaDePastasDoMesOutput
    {
        $vigente = $this->repository->buscarVigenteEm($tenant, $referencia);
        $meta    = $vigente !== null && $vigente->getValor() > 0 ? $vigente : null;

        [$total, $decorridos] = $this->contarDias($referencia);

        return new MetaDePastasDoMesOutput(
            $meta?->getValor(),
            $meta?->getMes()->format('Y-m'),
            $referencia->format('Y-m'),
            $realizado,
            $total,
            $decorridos,
            $this->ehMesLimpo($referencia, $filtros),
        );
    }

    /**
     * A mesma meta vista de outro "hoje" (simulação de dia do painel de Inteligência): só os dias úteis
     * decorridos mudam; o realizado e a meta seguem os de verdade. Dia fora do mês da meta não simula.
     */
    public function comDiaSimulado(MetaDePastasDoMesOutput $meta, \DateTimeImmutable $dia): MetaDePastasDoMesOutput
    {
        if ($dia->format('Y-m') !== $meta->mes) {
            return $meta;
        }

        [$total, $decorridos] = $this->contarDias($dia);

        return new MetaDePastasDoMesOutput($meta->meta, $meta->desde, $meta->mes, $meta->realizado, $total, $decorridos, $meta->visivel);
    }

    /** @param array<string, mixed> $filtros */
    private function ehMesLimpo(\DateTimeImmutable $referencia, array $filtros): bool
    {
        return ($filtros['data_de'] ?? '') === $referencia->format('Y-m-01')
            && ($filtros['data_ate'] ?? '') === $referencia->format('Y-m-t')
            && trim((string) ($filtros['responsavel'] ?? '')) === ''
            && trim((string) ($filtros['cargo'] ?? '')) === '';
    }

    /** @return array{0: int, 1: int} dias úteis do mês e dias úteis até hoje (hoje incluído) */
    private function contarDias(\DateTimeImmutable $referencia): array
    {
        $hoje       = $referencia->setTime(0, 0);
        $ultimo     = (int) $referencia->format('t');
        $total      = 0;
        $decorridos = 0;
        for ($d = 1; $d <= $ultimo; ++$d) {
            $dia = $hoje->setDate((int) $hoje->format('Y'), (int) $hoje->format('n'), $d);
            if (!$this->diasUteis->ehDiaUtil($dia)) {
                continue;
            }
            ++$total;
            if ($dia <= $hoje) {
                ++$decorridos;
            }
        }

        if ($total === 0) {
            return [$ultimo, (int) $hoje->format('j')];
        }

        return [$total, $decorridos];
    }
}
