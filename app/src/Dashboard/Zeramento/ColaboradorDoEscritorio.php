<?php

declare(strict_types=1);

namespace App\Dashboard\Zeramento;

use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;

/**
 * Guarda de posse do alvo de um zeramento (IDOR: o id chega pelo corpo do POST): só colaborador com
 * vínculo ATIVO no escritório da sessão, o mesmo universo da tabela. Fora disso,
 * ColaboradorForaDoEscritorioException (404). Sem id, o alvo é a equipe toda (null).
 */
final class ColaboradorDoEscritorio
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function buscar(Tenant $tenant, ?int $pessoaId): ?User
    {
        if ($pessoaId === null) {
            return null;
        }
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $colaborador) {
            if ($colaborador->getId() === $pessoaId) {
                return $colaborador;
            }
        }

        throw new ColaboradorForaDoEscritorioException('Colaborador não encontrado neste escritório.');
    }
}
