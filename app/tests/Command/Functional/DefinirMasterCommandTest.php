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
 * `app:master:definir <email> [--confirmar]`: deixa exatamente um ROLE_SUPER_ADMIN (spec D2, §7).
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
        self::assertStringContainsString('Ganha o papel de Master: ' . $novo->getEmail(), $saida);
        self::assertStringContainsString('Perde o papel de Master: ' . $atual->getEmail(), $saida);
        self::assertStringContainsString('--confirmar', $saida);

        $this->em->clear();
        self::assertContains('ROLE_SUPER_ADMIN', $this->recarregar($atual)->getRoles());
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->recarregar($novo)->getRoles());
    }

    #[TestDox('com --confirmar deixa EXATAMENTE um Master, o do e-mail, e preserva os outros papéis de quem perde')]
    public function testConfirmarDeixaUmUnicoMaster(): void
    {
        $atual = $this->criarUsuario(['ROLE_SUPER_ADMIN', 'ROLE_ALGUM_OUTRO']);
        $novo  = $this->criarUsuario([]);

        $tester = $this->tester();
        $tester->execute(['email' => $novo->getEmail(), '--confirmar' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $masters = static::getContainer()->get(UserRepository::class)->findComPapelGlobal('ROLE_SUPER_ADMIN');
        self::assertSame([$novo->getId()], array_map(static fn (User $u): ?int => $u->getId(), $masters));
        self::assertSame(['ROLE_ALGUM_OUTRO', 'ROLE_USER'], array_values($this->recarregar($atual)->getRoles()));

        // Idempotente: rodar de novo não muda nada.
        $tester->execute(['email' => $novo->getEmail(), '--confirmar' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Nada a fazer', $tester->getDisplay());
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
