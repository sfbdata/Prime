<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use Psr\Clock\ClockInterface;

/** O Master escolhe o modelo da plataforma entre os do {@see ModeloDeIa}; vale a partir da próxima chamada. */
final class EscolherModeloDaIaUseCase
{
    public function __construct(
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(ModeloDeIa $modelo, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        $configuracao = $this->plataforma->obterParaAlterar();
        $configuracao->escolherModelo($modelo, $master, $this->relogio->now());
        $this->plataforma->salvar($configuracao, flush: true);
    }
}
