<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Support;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;

/** Usuários e escritórios em memória para os testes unitários do Indicar o BlueJus (com id, sem banco). */
final class Pessoas
{
    /** @param list<string> $papeis */
    public static function usuario(int $id, string $nome = 'Ana Souza Lima', ?string $cpf = '123.456.789-09', array $papeis = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail(sprintf('pessoa%d@exemplo.com', $id));
        $user->setFullName($nome);
        $user->setRoles($papeis);
        self::definirId($user, $id);
        if ($cpf !== null) {
            $perfil = new UserProfile($user);
            $perfil->setCpf($cpf);
            $perfil->setTelefone('(61) 99999-0000');
            $user->setProfile($perfil);
        }

        return $user;
    }

    public static function master(int $id = 900): User
    {
        return self::usuario($id, 'Master do Sistema', null, ['ROLE_SUPER_ADMIN']);
    }

    public static function escritorio(int $id, string $nome = 'Escritório Alfa', ?User $criadoPor = null): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome);
        $tenant->setCriadoPor($criadoPor);
        self::definirId($tenant, $id);

        return $tenant;
    }

    private static function definirId(object $objeto, int $id): void
    {
        $prop = new \ReflectionProperty($objeto, 'id');
        $prop->setValue($objeto, $id);
    }
}
