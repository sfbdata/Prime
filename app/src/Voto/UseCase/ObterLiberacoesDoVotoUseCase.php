<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Voto\DTO\EscritorioNaLiberacaoDoVotoOutput;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Service\GuardaDoMasterDoVoto;

/**
 * A grade "Liberação por escritório" da aba "Voto" do Master: os escritórios ativos (nem suspensos nem excluídos), cada
 * um com o nome e se o Voto está liberado para ele. Só leitura; visão de plataforma, por isso exige o Master de novo.
 */
final class ObterLiberacoesDoVotoUseCase
{
    public function __construct(
        private readonly LiberacaoDoVotoRepository $liberacoes,
    ) {
    }

    /** @return list<EscritorioNaLiberacaoDoVotoOutput> */
    public function executar(User $master): array
    {
        GuardaDoMasterDoVoto::exigir($master);

        return array_map(
            EscritorioNaLiberacaoDoVotoOutput::daLinha(...),
            $this->liberacoes->listarEscritoriosParaOMaster(),
        );
    }
}
