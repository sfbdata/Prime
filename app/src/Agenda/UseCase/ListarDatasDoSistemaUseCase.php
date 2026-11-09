<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\DTO\DataDoSistemaOutput as D;
use App\Agenda\Entity\AjusteDeData;
use App\Agenda\Enum\TipoDeAjuste;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\UfsDaAgenda;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Repository\FeriadoRepository;

/**
 * História: a pessoa abre a Agenda e vê, sem ninguém cadastrar, os feriados nacionais e do seu
 * estado, o recesso, as datas dos tribunais e as comemorativas do período. Os feriados que o
 * escritório cadastrou em "Feriados e Folgas" (tabela `feriado` do Ponto) também aparecem, e cada
 * data diz se conta como feriado no ponto. Este caso de uso só LÊ o Ponto: nada aqui grava em
 * `feriado`, porque uma linha nova ali muda o banco de horas (spec agenda-vs-18-10-26, seção 5).
 *
 * Feriado de uma lotação (spec `ponto-desenho-0811.md`, E6): só entra para quem é daquela lotação, pelo id da lotação
 * do vínculo de quem vê (`$idDaLotacao`), nunca pelo nome. O de "Todas as sedes" entra para todos. Sem lotação (quem
 * está sem lotação, ou o escritório inteiro, como o dia útil da cobrança), só os de "Todas as sedes".
 */
final class ListarDatasDoSistemaUseCase
{
    /**
     * Só feriado nacional ou estadual absorve o do Ponto (fica com o nome do desenho e passa a contar
     * no ponto). Em data só de tribunal, o do Ponto ganha item próprio: desligar "Tribunais e
     * eleições" não pode esconder um feriado que conta no ponto.
     */
    private const CAL_COM_FERIADO = [D::CAL_NACIONAL, D::CAL_ESTADUAL];

    public function __construct(
        private readonly CalendarioDoSistema $calendario,
        private readonly UfsDaAgenda $ufsDaAgenda,
        private readonly FeriadoRepository $feriadoRepository,
        private readonly AjusteDeDataRepository $ajustes,
    ) {
    }

    /**
     * @param ?string $nomeDaLotacao a UF da lotação de quem vê entra nas UFs da Agenda
     * @param ?int    $idDaLotacao   a lotação do vínculo de quem vê, para o feriado da lotação; nula, só os de "Todas
     *                               as sedes" (pessoa sem lotação ou o escritório inteiro)
     *
     * @return array{ufs: list<string>, datas: list<D>, temAjustes: bool}
     */
    public function executar(Tenant $tenant, ?string $nomeDaLotacao, \DateTimeImmutable $de, \DateTimeImmutable $ate, ?int $idDaLotacao = null): array
    {
        $ufs = $this->ufsDaAgenda->resolver($nomeDaLotacao, $tenant->getEstado());
        $datas = $this->calendario->doPeriodo($de, $ate, $ufs);
        $doPonto = $this->feriadosDoPontoNoPeriodo($tenant, $idDaLotacao, $de, $ate);
        $ajustes = $this->ajustes->doEscritorio($tenant);
        $juntas = $this->aplicarAjustes($this->juntarComPonto($datas, $doPonto), $ajustes, $de, $ate);
        $temAjustes = [] !== array_filter($ajustes, static fn (AjusteDeData $a): bool => TipoDeAjuste::Acrescentar !== $a->getTipo());

        return ['ufs' => $ufs, 'datas' => $juntas, 'temAjustes' => $temAjustes];
    }

    /**
     * Ajustes do escritório (botão direito): tira as ocultas, troca os nomes e acrescenta as datas do
     * escritório que caem no período. Só a Agenda muda; o Ponto não é tocado.
     *
     * @param list<D>            $datas
     * @param list<AjusteDeData> $ajustes
     *
     * @return list<D>
     */
    private function aplicarAjustes(array $datas, array $ajustes, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $ocultas = [];
        $nomes = [];
        $saida = [];
        foreach ($ajustes as $a) {
            match ($a->getTipo()) {
                TipoDeAjuste::Ocultar     => $ocultas[(string) $a->getDataId()] = true,
                TipoDeAjuste::Renomear    => $nomes[(string) $a->getDataId()] = (string) $a->getTitulo(),
                TipoDeAjuste::Acrescentar => $saida[] = $this->acrescentada($a, $de, $ate),
            };
        }
        foreach ($datas as $d) {
            if (!isset($ocultas[$d->id])) {
                $saida[] = isset($nomes[$d->id]) ? $d->comTitulo($nomes[$d->id]) : $d;
            }
        }
        $saida = array_values(array_filter($saida));
        usort($saida, static fn (D $x, D $y): int => $x->data <=> $y->data);

        return $saida;
    }

    private function acrescentada(AjusteDeData $a, \DateTimeImmutable $de, \DateTimeImmutable $ate): ?D
    {
        $dia = $a->getData()?->format('Y-m-d');
        if (null === $dia || $dia < $de->format('Y-m-d') || $dia > $ate->format('Y-m-d')) {
            return null;
        }
        $fonte = $a->getFonte();

        return new D(
            'x-' . $a->getId(),
            $dia,
            (string) $a->getTitulo(),
            (string) $a->getCalendario(),
            D::TIPO_ACRESCENTADA,
            $fonte,
            null !== $fonte ? 'Fonte: ' . $fonte . '.' : 'Incluído manualmente.',
        );
    }

    /**
     * @param list<D>               $datas
     * @param array<string, string> $doPonto data => nome
     *
     * @return list<D>
     */
    private function juntarComPonto(array $datas, array $doPonto): array
    {
        $cobertas = [];
        foreach ($datas as $i => $d) {
            if (isset($doPonto[$d->data]) && in_array($d->calendario, self::CAL_COM_FERIADO, true)) {
                $datas[$i] = $d->comContaNoPonto();
                $cobertas[$d->data] = true;
            }
        }
        foreach ($doPonto as $data => $nome) {
            if (!isset($cobertas[$data])) {
                $datas[] = new D(
                    'esc-' . $data,
                    $data,
                    $nome,
                    D::CAL_NACIONAL,
                    D::TIPO_ESCRITORIO,
                    'Cadastrado pelo escritório em Feriados e Folgas',
                    null,
                    null,
                    true
                );
            }
        }
        usort($datas, static fn (D $a, D $b): int => $a->data <=> $b->data);

        return $datas;
    }

    /** @return array<string, string> data (Y-m-d) => nome, só as que caem no período e valem para a lotação */
    private function feriadosDoPontoNoPeriodo(Tenant $tenant, ?int $idDaLotacao, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $saida = [];
        foreach ($this->feriadoRepository->findByTenant($tenant) as $feriado) {
            if (!self::valeParaALotacao($feriado, $idDaLotacao)) {
                continue;
            }
            foreach ($this->ocorrencias($feriado, $de, $ate) as $data) {
                $saida[$data] ??= (string) $feriado->getNome();
            }
        }

        return $saida;
    }

    /** "Todas as sedes" vale para todos; o da lotação, só para quem tem aquela lotação (pelo id). */
    private static function valeParaALotacao(Feriado $feriado, ?int $idDaLotacao): bool
    {
        $lotacao = $feriado->getLotacao();
        if (null === $lotacao) {
            return true;
        }

        return null !== $idDaLotacao && $lotacao->getId() === $idDaLotacao;
    }

    /**
     * Mesma regra da CalculadoraJornada: recorrente compara só mês e dia; os outros, a data exata.
     *
     * @return list<string>
     */
    private function ocorrencias(Feriado $feriado, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $data = $feriado->getData();
        if (null === $data) {
            return [];
        }
        $candidatas = [$data->format('Y-m-d')];
        if ($feriado->isRecorrente()) {
            $candidatas = [];
            for ($ano = (int) $de->format('Y'); $ano <= (int) $ate->format('Y'); ++$ano) {
                if (checkdate((int) $data->format('m'), (int) $data->format('d'), $ano)) {
                    $candidatas[] = $ano . '-' . $data->format('m-d');
                }
            }
        }
        $ini = $de->format('Y-m-d');
        $fim = $ate->format('Y-m-d');

        return array_values(array_filter($candidatas, static fn (string $c): bool => $c >= $ini && $c <= $fim));
    }
}
