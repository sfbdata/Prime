<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\DTO\DataDoSistemaOutput as D;
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
    ) {
    }

    /** @return array{ufs: list<string>, datas: list<D>} */
    public function executar(Tenant $tenant, ?string $nomeDaLotacao, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $ufs = $this->ufsDaAgenda->resolver($nomeDaLotacao, $tenant->getEstado());
        $datas = $this->calendario->doPeriodo($de, $ate, $ufs);
        $doPonto = $this->feriadosDoPontoNoPeriodo($tenant, $de, $ate);

        return ['ufs' => $ufs, 'datas' => $this->juntarComPonto($datas, $doPonto)];
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

    /** @return array<string, string> data (Y-m-d) => nome, só as que caem no período */
    private function feriadosDoPontoNoPeriodo(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $saida = [];
        foreach ($this->feriadoRepository->findByTenant($tenant) as $feriado) {
            foreach ($this->ocorrencias($feriado, $de, $ate) as $data) {
                $saida[$data] ??= (string) $feriado->getNome();
            }
        }

        return $saida;
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
