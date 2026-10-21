<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;

/**
 * Ponto único de "quem pode ser escolhido como responsável ou participante" (spec docs/specs/equipe-da-conta.md §2;
 * decisão da coordenação em 19/10/2026): só quem está APROVADO no escritório entra como opção nova, e quem já estava
 * no registro continua na lista mesmo suspenso ou em análise, para editar não apagar a atribuição (o desenho promete
 * que os dados e o histórico continuam guardados). Os formulários de outros domínios passam a usar este serviço em
 * PR próprio.
 */
final class ColaboradoresAtribuiveis
{
    public function __construct(
        private readonly UserRepository $usuarios,
    ) {
    }

    /**
     * @param iterable<User|null> $atuais quem já está atribuído no registro que vai ser editado
     *
     * @return list<User> por nome, sem repetir
     */
    public function doEscritorio(Tenant $tenant, iterable $atuais = []): array
    {
        $porId = [];
        foreach ([...$this->usuarios->findColaboradoresAtivosPorTenant($tenant), ...$atuais] as $usuario) {
            if ($usuario instanceof User && $usuario->getId() !== null) {
                $porId[$usuario->getId()] = $usuario;
            }
        }
        $lista = array_values($porId);
        usort($lista, static fn (User $a, User $b): int => strcasecmp((string) $a->getFullName(), (string) $b->getFullName()));

        return $lista;
    }
}
