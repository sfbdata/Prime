<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use App\Entity\Auth\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Quem vê (e dispensa) o aviso de falha das tarefas agendadas (spec scheduler-tarefas-agendadas, "Aviso
 * de falha"): o Master (ROLE_SUPER_ADMIN) ou a pessoa cujo e-mail é um dos endereços de
 * AGENDADOR_ALERTA_EMAIL (sem diferença de maiúsculas). Variável vazia: só o Master.
 *
 * Regra única para a moldura (o aviso) e para a rota de dispensar (403 para quem não passa).
 */
final class QuemVeAvisoDoAgendador
{
    public function __construct(
        #[Autowire('%env(string:default::AGENDADOR_ALERTA_EMAIL)%')]
        private readonly string $alertaEmail,
    ) {
    }

    public function podeVer(?UserInterface $usuario): bool
    {
        if (!$usuario instanceof User) {
            return false;
        }

        return in_array('ROLE_SUPER_ADMIN', $usuario->getRoles(), true)
            || $this->ehEnderecoDeAlerta((string) $usuario->getEmail());
    }

    private function ehEnderecoDeAlerta(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        foreach (explode(',', $this->alertaEmail) as $endereco) {
            if (mb_strtolower(trim($endereco)) === $email) {
                return true;
            }
        }

        return false;
    }
}
