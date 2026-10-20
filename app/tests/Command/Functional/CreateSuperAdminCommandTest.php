<?php

declare(strict_types=1);

namespace App\Tests\Command\Functional;

use App\Command\CreateSuperAdminCommand;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:create-super-admin` cria só o PRIMEIRO Master, já primário (spec perfis-1.2-masters §4.2).
 * Havendo Master, recusa: secundário se promove pela tela e a troca do primário é do `app:master:definir`.
 */
#[CoversClass(CreateSuperAdminCommand::class)]
final class CreateSuperAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('com um Master já existente, recusa e aponta o app:master:definir')]
    public function testRecusaSegundoMaster(): void
    {
        $existente = new User();
        $existente->setEmail(uniqid('master_') . '@test.com');
        $existente->setFullName('Master Atual');
        $existente->setRoles(['ROLE_SUPER_ADMIN']);
        $existente->setIsActive(true);
        $existente->setPassword('x');
        $this->em->persist($existente);
        $this->em->flush();

        $email  = uniqid('novo_') . '@test.com';
        $tester = $this->tester();
        $codigo = $tester->execute(['email' => $email, 'password' => 'Senha#2026']);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('app:master:definir', $tester->getDisplay());
        self::assertStringNotContainsString('SUPER_ADMIN', $tester->getDisplay());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('sem nenhum Master, cria o Master')]
    public function testCriaQuandoNaoHaMaster(): void
    {
        // Rollback do DAMA desfaz: tira o papel de quem o tenha no banco de teste.
        $this->em->getConnection()->executeStatement(
            "UPDATE \"user\" SET roles = '[]' WHERE CAST(roles AS TEXT) LIKE '%\"ROLE_SUPER_ADMIN\"%' OR CAST(roles AS TEXT) LIKE '%\"ROLE_MASTER_PRIMARIO\"%'"
        );

        $email  = uniqid('novo_') . '@test.com';
        $tester = $this->tester();
        $tester->execute(['email' => $email, 'password' => 'Senha#2026']);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('Master primário criado', $tester->getDisplay());
        $criado = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($criado);
        self::assertContains('ROLE_SUPER_ADMIN', $criado->getRoles());
        self::assertContains('ROLE_MASTER_PRIMARIO', $criado->getRoles(), 'o primeiro Master já nasce primário');
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:create-super-admin'));
    }
}
