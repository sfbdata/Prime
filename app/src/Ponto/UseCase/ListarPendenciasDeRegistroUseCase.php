<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\PendenciaDeRegistroOutput;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use App\Ponto\Service\EsquecimentosRegistrados;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use Psr\Clock\ClockInterface;

/**
 * As pendências de registro da própria pessoa na tela do ponto (spec `ponto-esquecimento-nao-trava.md` R5, risco
 * ALTO): os dias dos últimos 30 com registro incompleto, neste escritório e nos outros em que ela tem vínculo ativo,
 * com o escritório ativo e o módulo de ponto. Só leitura; nenhuma pendência trava a batida (R1).
 *
 * Um dia entra quando teria o "+" da folha (`ponto-atalho-esquecimento-registro.md`): tem batida, é dia útil, não é
 * feriado do escritório, e a escolha da folha diz "registro incompleto" (`CalculadoraJornada::registroIncompletoDaEscolha`).
 * Falta a entrada ou a saída que não existe e, com metade do intervalo batido, a outra metade. O esquecimento já
 * pedido (pendente) ou deferido (abonado) daquele dia e batida, no mesmo escritório, não volta (`EsquecimentosRegistrados`).
 * Hoje fica fora: a jornada ainda está em curso (o ponto aberto hoje em outro escritório vira aviso na batida, R2).
 * O início da contagem não precisa de conferência: dia com batida nunca é anterior à primeira batida.
 *
 * Leitura de outro escritório: só da própria pessoa, com o escritório escrito em cada consulta e o filtro de escritório
 * suspenso dentro do repositório (`ConsultaSemFiltroDeEscritorio`, como no #317).
 */
final class ListarPendenciasDeRegistroUseCase
{
    public const JANELA_DIAS = 30;

    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly PermissionChecker $permissoes,
        private readonly RegistroPontoRepository $registros,
        private readonly JustificativaPontoRepository $justificativas,
        private readonly FeriadoRepository $feriados,
        private readonly EscolhaDasBatidasDoDia $escolha,
        private readonly CalculadoraJornada $calculadora,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<PendenciaDeRegistroOutput> do dia mais recente para o mais antigo; no mesmo dia, o escritório atual primeiro */
    public function executar(User $eu, Tenant $atual): array
    {
        $hoje = $this->clock->now()->setTime(0, 0, 0);
        $inicio = $hoje->modify(sprintf('-%d days', self::JANELA_DIAS));
        $fim = $hoje->modify('-1 second');

        $pendencias = [];
        foreach ($this->escritorios($eu, $atual) as $escritorio) {
            array_push($pendencias, ...$this->doEscritorio($eu, $escritorio, $escritorio->getId() === $atual->getId(), $inicio, $fim));
        }
        usort($pendencias, static fn (PendenciaDeRegistroOutput $a, PendenciaDeRegistroOutput $b): int => [$b->dia, $b->atual, $a->escritorio] <=> [$a->dia, $a->atual, $b->escritorio]);

        return $pendencias;
    }

    /** @return list<Tenant> o atual e os outros com vínculo ativo, escritório ativo e o módulo de ponto */
    private function escritorios(User $eu, Tenant $atual): array
    {
        $escritorios = [$atual];
        foreach ($this->vinculos->findActiveByUser($eu) as $vinculo) {
            $tenant = $vinculo->getTenant();
            if ($tenant->getId() !== $atual->getId() && $this->permissoes->canAccessModule($eu, $tenant, 'ponto')) {
                $escritorios[] = $tenant;
            }
        }

        return $escritorios;
    }

    /** @return list<PendenciaDeRegistroOutput> */
    private function doEscritorio(User $eu, Tenant $escritorio, bool $atual, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        $porDia = [];
        foreach ($this->registros->batidasDoPeriodoNoEscritorio($eu, $escritorio, $inicio, $fim) as $batida) {
            $porDia[$batida->getDataHora()->format('Y-m-d')][] = $batida;
        }
        if ($porDia === []) {
            return [];   // sem batida na janela: nem consulta feriado e esquecimentos
        }
        $feriados = $this->feriados->doEscritorio($escritorio);
        $jaPedidos = EsquecimentosRegistrados::dasJustificativas(
            $this->justificativas->esquecimentosDoPeriodoNoEscritorio($eu, $escritorio, $inicio, $fim),
        );

        $pendencias = [];
        foreach ($porDia as $chave => $batidas) {
            $dia = new \DateTimeImmutable($chave);
            if ((int) $dia->format('N') >= 6 || $this->calculadora->getFeriadoDoDia($dia, $feriados) !== null) {
                continue;
            }
            $escolhidas = $this->escolha->escolher($batidas, $dia);
            if (!$this->calculadora->registroIncompletoDaEscolha($escolhidas)) {
                continue;
            }
            foreach (self::faltantes($escolhidas) as $tipo) {
                if (!isset($jaPedidos[$chave . '|' . $tipo])) {
                    $pendencias[] = new PendenciaDeRegistroOutput($chave, $tipo, (int) $escritorio->getId(), (string) $escritorio->getName(), $atual);
                }
            }
        }

        return $pendencias;
    }

    /** @return list<string> as batidas que fecham o dia: entrada e saída que faltam e a metade do intervalo que falta */
    private static function faltantes(BatidasEscolhidas $escolhidas): array
    {
        $faltam = [];
        if ($escolhidas->entrada === null) {
            $faltam[] = RegistroPonto::TIPO_ENTRADA;
        }
        if ($escolhidas->repouso === null && $escolhidas->retorno !== null) {
            $faltam[] = RegistroPonto::TIPO_REPOUSO;
        }
        if ($escolhidas->retorno === null && $escolhidas->repouso !== null) {
            $faltam[] = RegistroPonto::TIPO_RETORNO;
        }
        if ($escolhidas->saida === null) {
            $faltam[] = RegistroPonto::TIPO_SAIDA;
        }

        return $faltam;
    }
}
