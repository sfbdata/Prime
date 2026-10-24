<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Zeramento;

use App\Entity\Auth\User;

/** User com id (a entidade não tem setId) e conta ativa, para os testes do "Zerar". */
final class PessoaDeTeste
{
    /** @param list<string> $roles */
    public static function criar(int $id, array $roles = ['ROLE_USER'], string $nome = ''): User
    {
        $u = new User();
        $u->setFullName($nome !== '' ? $nome : 'Pessoa ' . $id)->setEmail('p' . $id . '@teste.com')->setIsActive(true)->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
