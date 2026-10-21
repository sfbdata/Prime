<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;

/**
 * Convidados de um evento criado no Google que são da equipe do escritório (modelo do dono de 20/10/2026,
 * decisão a): o e-mail do convidado é o do cadastro de um colega ativo **ou** o da conta Google que ele ligou
 * naquele escritório. Convidado de fora é ignorado, e o e-mail dele não é guardado em lugar nenhum.
 */
final class ParticipantesDoGoogle
{
    public function __construct(
        private readonly UserRepository $usuarios,
        private readonly ConexaoGoogleAgendaRepository $conexoes,
    ) {
    }

    /**
     * @param list<string> $emails já em minúsculas
     *
     * @return list<User> colegas ativos do escritório, sem repetir e sem quem é dono da conta
     */
    public function daEquipe(Tenant $tenant, array $emails, User $dono): array
    {
        if ([] === $emails) {
            return [];
        }
        $porEmail = $this->contasDaEquipe($tenant);
        $achados = [];
        foreach ($emails as $email) {
            $pessoa = $porEmail[$email] ?? null;
            if (null !== $pessoa && $pessoa->getId() !== $dono->getId()) {
                $achados[(int) $pessoa->getId()] = $pessoa;
            }
        }

        return array_values($achados);
    }

    /** @return array<string, User> e-mail (minúsculo) => colega ativo, pelo cadastro e pela conta Google ligada */
    private function contasDaEquipe(Tenant $tenant): array
    {
        $ativos = [];
        foreach ($this->usuarios->findColaboradoresAtivosPorTenant($tenant) as $u) {
            $ativos[(int) $u->getId()] = $u;
        }
        $porEmail = [];
        foreach ($ativos as $u) {
            $porEmail[mb_strtolower(trim((string) $u->getEmail()))] = $u;
        }
        foreach ($this->conexoes->contasDoEscritorio($tenant) as $conta => $usuarioId) {
            if (isset($ativos[$usuarioId])) {
                $porEmail[$conta] ??= $ativos[$usuarioId];
            }
        }

        return $porEmail;
    }
}
