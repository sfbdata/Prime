<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Shared\Contract\ManterConectadoDoUsuario;
use Psr\Clock\ClockInterface;

/**
 * "Sair de todos os aparelhos" (Meu Perfil, aprovado pelo Samuel em 05/11/2026; sem desenho).
 *
 * Quem dispara: a própria pessoa, logada, depois de confirmar no modal. Objetivo: tirar o acesso de todo outro
 * aparelho onde ela entrou (inclusive os de "Manter conectado"), sem mexer na senha.
 * Fluxo: grava o corte (`sessoesEncerradasEm` = agora) e apaga todas as séries do "Manter conectado" dela, na mesma
 * transação. Pós-condições: cada outra sessão cai no pedido seguinte dela
 * (`SessaoEncerradaEmTodosOsAparelhosListener`); nenhum cookie antigo entra de novo. A sessão de quem apertou o
 * botão continua: o controller grava nela a entrada no mesmo instante do corte.
 *
 * @return \DateTimeImmutable o instante do corte
 */
final class SairDeTodosOsAparelhosUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ManterConectadoDoUsuario $manterConectado,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $user): \DateTimeImmutable
    {
        // Em segundos inteiros: a coluna é TIMESTAMP(0) e o Postgres ARREDONDA a fração (10:00:00.6 vira 10:00:01). Sem
        // cortar aqui, o corte relido do banco passaria a marca que o controller grava na sessão de quem apertou.
        $agora = $this->relogio->now();
        $agora = $agora->setTime((int) $agora->format('H'), (int) $agora->format('i'), (int) $agora->format('s'));
        $user->encerrarSessoes($agora);
        $this->manterConectado->encerrarJunto($user->getUserIdentifier(), fn () => $this->userRepository->salvar($user, flush: true));

        return $agora;
    }
}
