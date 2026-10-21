<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\DTO\AlterarLimitesDeIndicacaoInput;
use App\Indicacao\DTO\LimitesDeIndicacaoOutput;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Service\GuardaDoMaster;
use Psr\Clock\ClockInterface;

/** O Master altera os limites por dia, por mês e o alerta de 24h (contrato com a 5d). Todos >= 1. */
final class AlterarLimitesDeIndicacaoUseCase
{
    public function __construct(
        private readonly IndicacaoConfigRepository $configs,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(AlterarLimitesDeIndicacaoInput $input, User $master): LimitesDeIndicacaoOutput
    {
        GuardaDoMaster::exigir($master);
        $config = $this->configs->obter();
        $config->alterarLimites($input->porDia, $input->porMes, $input->alertaEm24h, $master, $this->relogio->now());
        $this->configs->salvar($config, flush: true);

        return LimitesDeIndicacaoOutput::fromEntity($config);
    }
}
