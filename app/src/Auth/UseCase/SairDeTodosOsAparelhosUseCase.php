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
 * botão continua (o controller a marca como entrada logo depois do corte) até ela sair ou fechar o navegador: o
 * "Manter conectado" dela também acaba.
 */
final class SairDeTodosOsAparelhosUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ManterConectadoDoUsuario $manterConectado,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return \DateTimeImmutable o instante do corte */
    public function executar(User $user): \DateTimeImmutable
    {
        // O corte é lido DEPOIS de apagar as séries, dentro da transação: um aparelho que voltou pelo "Manter conectado"
        // antes do DELETE entrou num segundo menor ou igual ao do corte, e o listener o derruba (empate também cai).
        return $this->manterConectado->encerrarJunto($user->getUserIdentifier(), function () use ($user): \DateTimeImmutable {
            $agora = $this->emSegundosInteiros($this->relogio->now());
            $user->encerrarSessoes($agora);
            $this->userRepository->salvar($user, flush: true);

            return $agora;
        });
    }

    /** A coluna guarda só segundos (o DBAL grava 'Y-m-d H:i:s'): sem a fração, o usuário em memória fica igual ao do banco. */
    private function emSegundosInteiros(\DateTimeImmutable $instante): \DateTimeImmutable
    {
        return $instante->setTime((int) $instante->format('H'), (int) $instante->format('i'), (int) $instante->format('s'));
    }
}
