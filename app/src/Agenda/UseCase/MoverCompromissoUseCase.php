<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\Evento;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * História: na grade da Agenda, a pessoa arrasta um compromisso para outro dia (Mês) ou outra hora
 * (Semana, Dia), como fazia no calendário antigo. A duração continua a mesma, contada em segundos (um
 * intervalo em meses mudaria de tamanho de um mês para outro). Regras da agenda antiga:
 * só o criador ou quem administra usuários; compromisso que se repete não é arrastado (edita-se a série).
 */
final class MoverCompromissoUseCase
{
    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function executar(int $id, string $dia, ?string $hora, User $eu, Tenant $tenant): string
    {
        $evento = $this->editavel($id, $eu, $tenant);
        if ($evento->isRecorrente()) {
            throw new \DomainException('Compromisso que se repete não pode ser arrastado: edite a série.');
        }
        $inicio = $evento->getDataInicio() ?? new \DateTimeImmutable();
        $segundos = ($evento->getDataFim() ?? $inicio)->getTimestamp() - $inicio->getTimestamp();
        $horario = null === $hora || $evento->isDiaInteiro() ? $inicio->format('H:i:s') : $hora . ':00';
        $novoInicio = new \DateTimeImmutable($dia . ' ' . $horario);
        $evento->setDataInicio($novoInicio);
        $evento->setDataFim($novoInicio->modify('+' . $segundos . ' seconds'));
        $this->eventos->salvar($evento);

        return $novoInicio->format('Y-m-d');
    }

    private function editavel(int $id, User $eu, Tenant $tenant): Evento
    {
        $evento = $this->eventos->visivelPara($id, $tenant, $eu) ?? throw new CompromissoNaoEncontradoException();
        if ($evento->getCriador()?->getId() !== $eu->getId() && !$this->permissoes->canAdminister($eu, $tenant, 'admin.users.manage')) {
            throw new SemPermissaoNoCompromissoException();
        }

        return $evento;
    }
}
