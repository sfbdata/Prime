<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\Repository\AcessoDiarioRepository;
use Psr\Clock\ClockInterface;

/**
 * Marca que a pessoa entrou no sistema hoje (linha "Acessos ao sistema" do gráfico Crescimento do Painel do
 * Master, desenho 05 - Perfis 1.2, VS 03.11.26). Uma linha por pessoa por dia: a segunda entrada do dia não
 * muda nada. O dia é o do relógio do sistema (fuso de Brasília).
 */
final class RegistrarAcessoDoDiaUseCase
{
    public function __construct(
        private readonly AcessoDiarioRepository $acessos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $usuario): void
    {
        $id = $usuario->getId();
        if ($id === null) {
            return;
        }

        $this->acessos->registrar($id, $this->relogio->now()->setTime(0, 0));
    }
}
