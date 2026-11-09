<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\FeriadoDoPontoOutput;
use App\Ponto\DTO\SedeDoPontoOutput;
use App\Ponto\Repository\FeriadoRepository;
use App\Repository\LotacaoRepository;
use App\Repository\SedeRepository;

/**
 * O que as seções "Sedes e raio de registro" e "Feriados do escritório" da aba Configurações mostram (spec
 * `docs/specs/ponto-desenho-0811.md`, E6), sempre do escritório da sessão. Só leitura.
 */
final class SedesEFeriadosDoPonto
{
    public function __construct(
        private readonly SedeRepository $sedes,
        private readonly FeriadoRepository $feriados,
        private readonly LotacaoRepository $lotacoes,
    ) {
    }

    /** @return list<SedeDoPontoOutput> na ordem em que foram cadastradas (a nova entra no fim, como no desenho) */
    public function sedes(Tenant $escritorio): array
    {
        return array_map(
            static fn ($sede): SedeDoPontoOutput => SedeDoPontoOutput::daSede($sede),
            $this->sedes->findBy(['tenant' => $escritorio], ['id' => 'ASC']),
        );
    }

    /** @return list<Lotacao> as opções da abrangência, depois de "Todas as sedes" (P71 com o desvio da E6) */
    public function lotacoes(Tenant $escritorio): array
    {
        return $this->lotacoes->findBy(['tenant' => $escritorio], ['nome' => 'ASC']);
    }

    /**
     * Ordenados por mês e dia, como na página "Feriados e Folgas". Chame depois de `lotacoes()`: com as lotações já
     * carregadas, o nome da abrangência não volta ao banco a cada feriado.
     *
     * @return list<FeriadoDoPontoOutput>
     */
    public function feriados(Tenant $escritorio): array
    {
        return array_values(array_map(
            static fn ($feriado): FeriadoDoPontoOutput => FeriadoDoPontoOutput::doFeriado($feriado),
            $this->feriados->findByTenantOrdenado($escritorio),
        ));
    }
}
