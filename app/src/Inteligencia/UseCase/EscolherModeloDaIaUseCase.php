<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
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
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(ModeloDeIa $modelo, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        $configuracao = $this->plataforma->obterParaAlterar();
        $configuracao->escolherModelo($modelo, $master, $this->relogio->now());
        // Vale para todos os escritórios: a linha de auditoria fica sem escritório.
        $this->escopoDePlataforma->executar(fn () => $this->plataforma->salvar($configuracao, flush: true));
    }
}
