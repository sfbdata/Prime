<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Service\GuardaDoMaster;
use Psr\Clock\ClockInterface;

/** O Master pausa (ou retoma) os convites de todo mundo (contrato com a 5d). */
final class PausarIndicacoesParaTodosUseCase
{
    public function __construct(
        private readonly IndicacaoConfigRepository $configs,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(bool $pausar, User $master): void
    {
        GuardaDoMaster::exigir($master);
        $config = $this->configs->obter();
        $config->pausarParaTodos($pausar, $master, $this->relogio->now());
        $this->configs->salvar($config, flush: true);
    }
}
