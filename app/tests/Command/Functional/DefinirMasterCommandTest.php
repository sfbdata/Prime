<?php

declare(strict_types=1);

namespace App\Tests\Command\Functional;

use App\Command\DefinirMasterCommand;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:master:definir <email> [--confirmar]`: define o Master primário (ROLE_SUPER_ADMIN e
 * ROLE_MASTER_PRIMARIO); os demais Masters perdem os dois papéis (spec perfis-1.2-masters §4.2, M4).
 */
#[CoversClass(DefinirMasterCommand::class)]
final class DefinirMasterCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('sem --confirmar só mostra o plano (quem ganha, quem perde) e não grava nada')]
    public function testSemConfirmarNaoGrava(): void
    {
        $atual = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $novo  = $this->criarUsuario([]);

        $tester = $this->tester();
        $tester->execute(['email' => strtoupper((string) $novo->getEmail())]);
        $tester->assertCommandIsSuccessful();

        $saida = $tester->getDisplay();
        self::assertStringContainsString('Ganha o papel de Master primário: ' . $novo->getEmail(), $saida);
        self::assertStringContainsString('Perde o papel de Master: ' . $atual->getEmail(), $saida);
        self::assertStringContainsString('--confirmar', $saida);

        $this->em->clear();
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($atual)->getRoles());
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($novo)->getRoles());
        self::assertNotContains('ROLE_MASTER_PRIMARIO', $this->recarregar($novo)->getRoles());
    }

    #[TestDox('com --confirmar o do e-mail vira o Master primário; o primário anterior e os secundários perdem os dois papéis e mantêm os outros')]
    public function testConfirmarDeixaUmUnicoMaster(): void
    {
        $atual      = $this->criarUsuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO', 'ROLE_ALGUM_OUTRO']);
        $secundario = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $novo       = $this->criarUsuario([]);

        $tester = $this->tester();
        $tester->execute(['email' => $novo->getEmail(), '--confirmar' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $masters = static::getContainer()->get(UserRepository::class)->findComPapelGlobal('ROLE_SUPER_ADMIN');
        self::assertSame([$novo->getId()], array_map(static fn (User $u): ?int => $u->getId(), $masters));
        $primarios = static::getContainer()->get(UserRepository::class)->findComPapelGlobal('ROLE_MASTER_PRIMARIO');
        self::assertSame([$novo->getId()], array_map(static fn (User $u): ?int => $u->getId(), $primarios));
        self::assertSame(['ROLE_ALGUM_OUTRO', 'ROLE_USER'], array_values($this->recarregar($atual)->getRoles()));
        self::assertSame(['ROLE_USER'], array_values($this->recarregar($secundario)->getRoles()));

        // Idempotente: rodar de novo não muda nada.
        $tester->execute(['email' => $novo->getEmail(), '--confirmar' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Nada a fazer', $tester->getDisplay());
    }

    #[TestDox('Master secundário escolhido vira o primário; o primário anterior perde os dois papéis')]
    public function testSecundarioViraPrimario(): void
    {
        $atual      = $this->criarUsuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $secundario = $this->criarUsuario(['ROLE_SUPER_ADMIN']);

        $tester = $this->tester();
        $tester->execute(['email' => $secundario->getEmail(), '--confirmar' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Ganha o papel de Master primário: ' . $secundario->getEmail(), $tester->getDisplay());

        $this->em->clear();
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO', 'ROLE_USER'], array_values($this->recarregar($secundario)->getRoles()));
        self::assertSame(['ROLE_USER'], array_values($this->recarregar($atual)->getRoles()));
    }

    #[TestDox('e-mail inexistente: falha sem mexer em ninguém')]
    public function testEmailInexistente(): void
    {
        $atual = $this->criarUsuario(['ROLE_SUPER_ADMIN']);

        $tester = $this->tester();
        $codigo = $tester->execute(['email' => 'ninguem-' . uniqid() . '@test.com', '--confirmar' => true]);

        self::assertSame(Command::FAILURE, $codigo);
        $this->em->clear();
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($atual)->getRoles());
    }

    #[TestDox('conta desativada não vira Master')]
    public function testContaDesativada(): void
    {
        $inativo = $this->criarUsuario([], false);

        $codigo = $this->tester()->execute(['email' => $inativo->getEmail(), '--confirmar' => true]);

        self::assertSame(Command::FAILURE, $codigo);
        $this->em->clear();
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($inativo)->getRoles());
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:master:definir'));
    }

    /** @param list<string> $papeis */
    private function criarUsuario(array $papeis, bool $ativo = true): User
    {
        $user = new User();
        $user->setEmail(uniqid('master_') . '@test.com');
        $user->setFullName('Usuário Master Teste');
        $user->setRoles($papeis);
        $user->setIsActive($ativo);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function recarregar(User $user): User
    {
        $recarregado = $this->em->find(User::class, $user->getId());
        self::assertNotNull($recarregado);

        return $recarregado;
    }
}
