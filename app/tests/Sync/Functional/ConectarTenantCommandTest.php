<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Repository\TenantRepository;
use App\Sync\Command\ConectarTenantCommand;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Service\CifradorDeSegredo;
use App\Sync\UseCase\ConectarDriveUseCase;
use App\Sync\UseCase\DefinirRootFolderDriveUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(ConectarTenantCommand::class)]
final class ConectarTenantCommandTest extends KernelTestCase
{
    use Factories;

    private function comando(string $refreshToken, string $rootFolderId): ConectarTenantCommand
    {
        $c = self::getContainer();

        return new ConectarTenantCommand(
            $c->get(EntityManagerInterface::class),
            $c->get(TenantRepository::class),
            $c->get(ConectarDriveUseCase::class),
            $c->get(DefinirRootFolderDriveUseCase::class),
            $refreshToken,
            $rootFolderId,
        );
    }

    #[TestDox('semeia a conexão do tenant, ativa, com o token cifrado')]
    public function testSemeiaConexao(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        $tester = new CommandTester($this->comando('refresh-real', '1WBY-hLuad3weKWPBM0f297_RtWTJo0_R'));
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $conexao = $em->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant->getId()]);
        self::assertNotNull($conexao);
        self::assertTrue($conexao->isAtivo());
        self::assertSame('1WBY-hLuad3weKWPBM0f297_RtWTJo0_R', $conexao->getRootFolderId());

        $cifrador = self::getContainer()->get(CifradorDeSegredo::class);
        self::assertSame('refresh-real', $cifrador->decifrar($conexao->getRefreshTokenCifrado()));
    }

    #[TestDox('sem credenciais de ambiente falha com mensagem clara')]
    public function testSemCredenciaisFalha(): void
    {
        self::bootKernel();

        $tester = new CommandTester($this->comando('', ''));
        $tester->execute(['--tenant-id' => '1', '--usuario-id' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN', $tester->getDisplay());
    }

    #[TestDox('rodar duas vezes é idempotente — atualiza a mesma conexão')]
    public function testIdempotente(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        $args = ['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()];
        (new CommandTester($this->comando('tok', 'root-abcdefghij')))->execute($args);
        (new CommandTester($this->comando('tok', 'root-abcdefghij')))->execute($args);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $conexoes = $em->getRepository(TenantDriveConexao::class)->findBy(['tenant' => $tenant->getId()]);
        self::assertCount(1, $conexoes, 'upsert por tenant — nunca duplica');
    }
}
