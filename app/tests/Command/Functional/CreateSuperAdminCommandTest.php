<?php

declare(strict_types=1);

namespace App\Tests\Command\Functional;

use App\Auth\Service\ForcaDaSenha;
use App\Command\CreateSuperAdminCommand;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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

    #[TestDox('senha fraca: recusa com a régua do cadastro e não cria ninguém')]
    public function testRecusaSenhaFraca(): void
    {
        $this->semMaster();

        $email  = uniqid('novo_') . '@test.com';
        $tester = $this->tester();
        $codigo = $tester->execute(['email' => $email, 'password' => 'abcdefgh']);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString(ForcaDaSenha::ERRO, $tester->getDisplay());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('senha de exemplo (já publicada): recusa, sem diferenciar maiúsculas, e não cria ninguém')]
    public function testRecusaSenhaDeExemplo(): void
    {
        $this->semMaster();

        // O literal real não entra no teste: troca-se a lista por uma senha sorteada que passa na régua.
        $exemplo = 'Ex' . bin2hex(random_bytes(4)) . '#9';
        $comando = new CreateSuperAdminCommand(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
            static::getContainer()->get(UserRepository::class),
            [hash('sha256', mb_strtolower($exemplo))],
        );
        $email  = uniqid('novo_') . '@test.com';
        $tester = new CommandTester($comando);
        $codigo = $tester->execute(['email' => $email, 'password' => mb_strtoupper($exemplo)]);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('senha de exemplo', $tester->getDisplay());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('a lista de senhas recusadas traz a senha publicada no help antigo (conferida por hash, a mesma da guarda de segredos)')]
    public function testListaTrazASenhaPublicada(): void
    {
        self::assertContains(
            'eefd77d3820ea744f9271b91b52625cc3d1428fd50098ea2432584d6d41d35fd',
            CreateSuperAdminCommand::SENHAS_DE_EXEMPLO_SHA256,
        );
    }

    #[TestDox('sem o argumento da senha, pergunta sem eco e cria com a senha digitada')]
    public function testPerguntaASenhaQuandoAusente(): void
    {
        $this->semMaster();

        $email  = uniqid('novo_') . '@test.com';
        $tester = $this->tester();
        $tester->setInputs(['Senha#2026']);
        $tester->execute(['email' => $email], ['interactive' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringNotContainsString('Senha#2026', $tester->getDisplay());
        $criado = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($criado);
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($criado, 'Senha#2026'));
    }

    #[TestDox('sem o argumento da senha e sem terminal interativo: recusa')]
    public function testSemSenhaNaoInterativoRecusa(): void
    {
        $this->semMaster();

        $email  = uniqid('novo_') . '@test.com';
        $tester = $this->tester();
        $codigo = $tester->execute(['email' => $email], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $codigo);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('a ajuda não traz senha nem e-mail de exemplo')]
    public function testAjudaNeutra(): void
    {
        $definicao = (new Application(self::$kernel))->find('app:create-super-admin')->getDefinition();

        self::assertSame('Senha do Master', mb_substr($definicao->getArgument('password')->getDescription(), 0, 15));
        self::assertFalse($definicao->getArgument('password')->isRequired());
        self::assertStringNotContainsString('@', $definicao->getArgument('email')->getDescription());
    }

    private function semMaster(): void
    {
        // Rollback do DAMA desfaz: tira o papel de quem o tenha no banco de teste.
        $this->em->getConnection()->executeStatement(
            "UPDATE \"user\" SET roles = '[]' WHERE CAST(roles AS TEXT) LIKE '%\"ROLE_SUPER_ADMIN\"%' OR CAST(roles AS TEXT) LIKE '%\"ROLE_MASTER_PRIMARIO\"%'"
        );
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:create-super-admin'));
    }
}
