<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Entity\Pasta;
use App\Sync\Message\SincronizarPastaNoDrive;
use App\Sync\MessageHandler\SincronizarPastaNoDriveHandler;
use App\Sync\Service\GoogleDriveClientFactory;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use App\Tests\Sync\Support\FakeGoogleDriveClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(SincronizarPastaNoDriveHandler::class)]
final class SincronizarPastaNoDriveHandlerTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('handler sincroniza a pasta do evento (folder criado no Drive)')]
    public function testHandlerSincronizaPasta(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '900', 'nomeCliente' => 'CLIENTE']);

        $fake    = new FakeGoogleDriveClient();
        $handler = new SincronizarPastaNoDriveHandler(
            $this->em(),
            new FakeGoogleDriveClientFactory($fake, 'RAIZ'),
            self::getContainer()->get(ReconciliadorDePasta::class),
            new NullLogger(),
        );

        $handler(new SincronizarPastaNoDrive($pasta->getId(), (int) $tenant->getId(), (int) $user->getId()));

        $em = $this->em();
        $em->clear();
        $folderId = $em->find(Pasta::class, $pasta->getId())->getDriveFolderId();
        self::assertNotNull($folderId, 'a pasta do evento ganhou pasta no Drive');
        self::assertSame('900 - CLIENTE', $fake->pastas[$folderId]['nome']);
        self::assertSame('RAIZ', $fake->pastas[$folderId]['parent']);
    }

    #[TestDox('mensagem com pasta de OUTRO tenant é rejeitada — nada sincronizado (isolamento)')]
    public function testHandlerRejeitaPastaDeOutroTenant(): void
    {
        self::bootKernel();
        $tenantA = TenantFactory::createOne();
        $tenantB = TenantFactory::createOne();
        $user    = UserFactory::createOne();
        // A pasta é do tenant A…
        $pastaA  = PastaFactory::createOne(['tenant' => $tenantA, 'nup' => '910', 'nomeCliente' => 'A']);

        $fake    = new FakeGoogleDriveClient();
        $handler = new SincronizarPastaNoDriveHandler(
            $this->em(),
            new FakeGoogleDriveClientFactory($fake, 'RAIZ'),
            self::getContainer()->get(ReconciliadorDePasta::class),
            new NullLogger(),
        );

        // …mas a mensagem afirma o tenant B (adulterada). O guard tem de barrar.
        $handler(new SincronizarPastaNoDrive($pastaA->getId(), (int) $tenantB->getId(), (int) $user->getId()));

        $em = $this->em();
        $em->clear();
        self::assertNull($em->find(Pasta::class, $pastaA->getId())->getDriveFolderId(), 'pasta de A não pode ser sincronizada sob B');
        self::assertSame([], $fake->pastas, 'nada foi criado no Drive');
    }

    #[TestDox('tenant sem Drive conectado → handler é no-op (não cria nada, não lança)')]
    public function testHandlerNoOpSemConexao(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '901', 'nomeCliente' => 'SEM DRIVE']);

        // Fábrica REAL: sem TenantDriveConexao ativa, paraTenant lança TenantSemDriveException → no-op.
        $handler = new SincronizarPastaNoDriveHandler(
            $this->em(),
            self::getContainer()->get(GoogleDriveClientFactory::class),
            self::getContainer()->get(ReconciliadorDePasta::class),
            new NullLogger(),
        );

        $handler(new SincronizarPastaNoDrive($pasta->getId(), (int) $tenant->getId(), (int) $user->getId()));

        $em = $this->em();
        $em->clear();
        self::assertNull($em->find(Pasta::class, $pasta->getId())->getDriveFolderId(), 'nada sincronizado sem Drive');
    }
}
