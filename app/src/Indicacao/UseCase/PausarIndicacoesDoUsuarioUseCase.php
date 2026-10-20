<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Exception\IndicadorNaoEncontrado;
use App\Indicacao\Repository\IndicacaoPausaRepository;
use App\Indicacao\Service\GuardaDoMaster;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * O Master pausa (ou retoma) os convites de uma pessoa (contrato com a 5d). Idempotente, inclusive sob corrida: dois
 * Masters pausando ao mesmo tempo gravam uma pausa só, sem erro (INSERT ... ON CONFLICT DO NOTHING no repositório);
 * retomar quem não está pausado não faz nada. Usuário inexistente: {@see IndicadorNaoEncontrado}.
 */
final class PausarIndicacoesDoUsuarioUseCase
{
    public function __construct(
        private readonly IndicacaoPausaRepository $pausas,
        private readonly UserRepository $usuarios,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $userId, bool $pausar, User $master): void
    {
        GuardaDoMaster::exigir($master);
        if ($this->usuarios->find($userId) === null) {
            throw new IndicadorNaoEncontrado('Usuário não encontrado.');
        }
        if ($pausar) {
            $this->pausas->pausar($userId, $master->getId(), $this->relogio->now());

            return;
        }
        $this->pausas->retomar($userId);
    }
}
