<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * História: no detalhe do compromisso, quem o criou (ou um administrador de usuários do escritório)
 * clica em "Excluir" e confirma. O compromisso some da agenda de todos, como no "Excluir" da agenda
 * antiga. Quem não vê o compromisso recebe 404; quem vê mas não pode alterar, 403.
 */
final class ExcluirCompromissoUseCase
{
    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function executar(int $id, User $eu, Tenant $tenant): void
    {
        $evento = $this->eventos->visivelPara($id, $tenant, $eu) ?? throw new CompromissoNaoEncontradoException();
        $ehCriador = $evento->getCriador()?->getId() === $eu->getId();
        if (!$ehCriador && !$this->permissoes->canAdminister($eu, $tenant, 'admin.users.manage')) {
            throw new SemPermissaoNoCompromissoException();
        }
        $this->eventos->remover($evento);
    }
}
