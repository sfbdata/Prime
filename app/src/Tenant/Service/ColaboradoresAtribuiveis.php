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
 * que os dados e o histórico continuam guardados). `doEscritorio` monta a lista do seletor; `resolver` e
 * `resolverVarios` conferem, na gravação, o id que voltou do navegador com a mesma regra (spec
 * docs/specs/colaboradores-atribuiveis.md).
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

    /**
     * Quem pode ser gravado no registro: aprovado no escritório, ou já atribuído nele (mesmo suspenso).
     *
     * @param iterable<User|null> $atuais
     */
    public function resolver(int $id, Tenant $tenant, iterable $atuais = []): ?User
    {
        foreach ($atuais as $usuario) {
            if ($usuario instanceof User && $usuario->getId() === $id) {
                return $usuario;
            }
        }

        return $this->usuarios->findColaboradorAtivoPorIdETenant($id, $tenant);
    }

    /**
     * Os ids que podem ser gravados, sem repetir; o que não pode (de outro escritório, suspenso novo) fica de fora.
     *
     * @param list<int>           $ids
     * @param iterable<User|null> $atuais
     *
     * @return list<User>
     */
    public function resolverVarios(array $ids, Tenant $tenant, iterable $atuais = []): array
    {
        $atuais = [...$atuais];
        $porId  = [];
        foreach (array_unique($ids) as $id) {
            $usuario = $this->resolver($id, $tenant, $atuais);
            if ($usuario !== null) {
                $porId[$id] = $usuario;
            }
        }

        return array_values($porId);
    }
}
