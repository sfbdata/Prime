<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Voto\DTO\EstatisticasDoVotoOutput;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Service\EstatisticasDasVotacoes;
use App\Voto\Service\GuardaDoMasterDoVoto;

/**
 * "Estatísticas de todas as votações" da aba "Voto" do Master (spec voto-equipe-e-master-1611, seção 3). Só leitura,
 * só números agregados, entre escritórios: por isso exige o Master de novo, mesmo com o controller já exigindo.
 */
final class ObterEstatisticasDoVotoUseCase
{
    public function __construct(
        private readonly EstatisticasDasVotacoes $estatisticas,
    ) {
    }

    /** @param ?CenarioDaAssembleia $categoria null = todas as categorias */
    public function executar(User $master, ?CenarioDaAssembleia $categoria = null): EstatisticasDoVotoOutput
    {
        GuardaDoMasterDoVoto::exigir($master);

        return $this->estatisticas->calcular($categoria);
    }
}
