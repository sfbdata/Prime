<?php

declare(strict_types=1);

namespace App\Tests\Backup\Unit;

use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DonoDoEscritorio::class)]
final class DonoDoEscritorioTest extends TestCase
{
    private static function usuario(?int $id): User
    {
        $u = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    #[TestDox('O criador do escritório é o dono, mesmo sendo outra instância com o mesmo id')]
    public function testCriadorEDono(): void
    {
        $tenant = (new Tenant())->setCriadoPor(self::usuario(7));

        self::assertTrue((new DonoDoEscritorio())->ehDono(self::usuario(7), $tenant));
    }

    #[TestDox('Outra pessoa não é dono')]
    public function testOutraPessoaNaoE(): void
    {
        $tenant = (new Tenant())->setCriadoPor(self::usuario(7));

        self::assertFalse((new DonoDoEscritorio())->ehDono(self::usuario(8), $tenant));
    }

    #[TestDox('Escritório sem criador: ninguém é dono')]
    public function testSemCriadorNinguemE(): void
    {
        self::assertFalse((new DonoDoEscritorio())->ehDono(self::usuario(7), new Tenant()));
    }

    #[TestDox('Criador e usuário sem id (não gravados): ninguém é dono')]
    public function testSemIdNaoE(): void
    {
        $semId = self::usuario(null);
        $tenant = (new Tenant())->setCriadoPor($semId);

        self::assertFalse((new DonoDoEscritorio())->ehDono($semId, $tenant));
    }
}
