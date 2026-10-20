<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Entity\IndicacaoPausa;
use App\Indicacao\Exception\IndicadorNaoEncontrado;
use App\Indicacao\Repository\IndicacaoPausaRepository;
use App\Indicacao\Service\GuardaDoMaster;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * O Master pausa (ou retoma) os convites de uma pessoa (contrato com a 5d). Idempotente: pausar quem já está pausado
 * ou retomar quem não está não muda nada. Usuário inexistente: {@see IndicadorNaoEncontrado}.
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
        $user = $this->usuarios->find($userId) ?? throw new IndicadorNaoEncontrado('Usuário não encontrado.');
        $pausa = $this->pausas->doUsuario($userId);
        if ($pausar && $pausa === null) {
            $this->pausas->salvar(new IndicacaoPausa($user, $master, $this->relogio->now()), flush: true);
        }
        if (!$pausar && $pausa !== null) {
            $this->pausas->remover($pausa, flush: true);
        }
    }
}
