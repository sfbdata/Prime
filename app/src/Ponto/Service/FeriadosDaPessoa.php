<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Os feriados que valem para uma pessoa NUM DIA (spec `docs/specs/ponto-desenho-0811.md`, E6, P71 com o desvio da E6
 * e a lotação com vigência): a regra da abrangência mora só aqui.
 *
 * - feriado sem lotação ("Todas as sedes"): vale para todos, como sempre;
 * - feriado com lotação: vale só para quem tinha AQUELA lotação no escritório do feriado NAQUELE dia (o histórico da
 *   lotação do vínculo, `HistoricoDeLotacoes`; vínculo ativo ou não: a folha de quem saiu continua contando o feriado
 *   que valia para ela). Trocar a lotação vale a partir do dia da troca: a folha do passado não muda.
 *
 * Quem aplica: o `FolhaPontoBuilder` (folha na tela, ficha do colaborador, PDF, XLSX, saldo do ano, saldo até o mês,
 * horas pagas, "Regularizar ponto de ontem"), e a lista de pendências de registro, que lê o feriado sem a folha.
 *
 * Sem N+1: sem feriado por lotação (o caso de hoje), nenhuma consulta; com ele, uma por pessoa e escritório (a lotação
 * de hoje e as versões juntas), guardada até o fim do request ou da mensagem do worker (`kernel.reset`). O saldo do
 * ano monta a folha mês a mês e não volta ao banco a cada mês nem a cada dia.
 */
final class FeriadosDaPessoa implements ResetInterface
{
    /** @var array<string, HistoricoDeLotacoes> "pessoa:escritório" => o histórico da lotação da pessoa ali */
    private array $historicos = [];

    public function __construct(
        private readonly LotacaoComVigenciaRepository $lotacoes,
    ) {
    }

    /**
     * @param iterable<Feriado> $feriados os do escritório (ou mais de um escritório: cada feriado é julgado pelo seu)
     *
     * @return list<Feriado> os que valem para a pessoa no dia, na mesma ordem
     */
    public function filtrar(iterable $feriados, ?User $pessoa, \DateTimeInterface $dia): array
    {
        $valem = [];
        foreach ($feriados as $feriado) {
            if ($this->valePara($feriado, $pessoa, $dia)) {
                $valem[] = $feriado;
            }
        }

        return $valem;
    }

    public function valePara(Feriado $feriado, ?User $pessoa, \DateTimeInterface $dia): bool
    {
        $lotacao = $feriado->getLotacao();
        if ($lotacao === null) {
            return true;
        }
        $escritorio = $feriado->getTenant();
        if ($pessoa === null || $escritorio === null || $lotacao->getId() === null) {
            return false;
        }

        return $this->historico($pessoa, $escritorio)->lotacaoNoDia($dia) === $lotacao->getId();
    }

    /** Zera a memória entre requests e entre mensagens do worker: a lotação da pessoa pode mudar. */
    public function reset(): void
    {
        $this->historicos = [];
    }

    private function historico(User $pessoa, Tenant $escritorio): HistoricoDeLotacoes
    {
        if ($pessoa->getId() === null || $escritorio->getId() === null) {
            return HistoricoDeLotacoes::semVinculo();
        }
        $chave = $pessoa->getId() . ':' . $escritorio->getId();

        return $this->historicos[$chave] ??= $this->lotacoes->historicoDoVinculo($pessoa, $escritorio);
    }
}
