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

    #[TestDox('R3: com renomear=true, o novo nome do sistema chega ao Drive')]
    public function testHandlerRenomeiaNoDrive(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '1214', 'nomeCliente' => 'FULANO']);

        $fake = new FakeGoogleDriveClient();
        // Pasta já vinculada com o nome ANTIGO — é o estado real de quem editou no sistema.
        $fake->seedPasta('DRV-1214', '1214 - FULANO', 'RAIZ');
        $pasta->_real()->setDriveFolderId('DRV-1214');
        $this->em()->flush();

        $pasta->_real()->setNomeAcao('RESCISÃO CONTRATUAL');
        $this->em()->flush();

        $handler = new SincronizarPastaNoDriveHandler(
            $this->em(),
            new FakeGoogleDriveClientFactory($fake, 'RAIZ'),
            self::getContainer()->get(ReconciliadorDePasta::class),
            new NullLogger(),
        );

        $handler(new SincronizarPastaNoDrive($pasta->getId(), (int) $tenant->getId(), (int) $user->getId(), renomear: true));

        self::assertSame('1214 - FULANO - RESCISÃO CONTRATUAL', $fake->pastas['DRV-1214']['nome']);
        self::assertCount(1, $fake->renomeacoes);
    }

    #[TestDox('R3: sem renomear, o handler NÃO toca no nome da pasta no Drive')]
    public function testHandlerNaoRenomeiaSemPedido(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '1215', 'nomeCliente' => 'CICRANO']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-1215', 'NOME ANTIGO NO DRIVE', 'RAIZ');
        $pasta->_real()->setDriveFolderId('DRV-1215');
        $this->em()->flush();

        $handler = new SincronizarPastaNoDriveHandler(
            $this->em(),
            new FakeGoogleDriveClientFactory($fake, 'RAIZ'),
            self::getContainer()->get(ReconciliadorDePasta::class),
            new NullLogger(),
        );

        // Evento comum (documento anexado): não pede renomeação.
        $handler(new SincronizarPastaNoDrive($pasta->getId(), (int) $tenant->getId(), (int) $user->getId()));

        self::assertSame('NOME ANTIGO NO DRIVE', $fake->pastas['DRV-1215']['nome']);
        self::assertSame([], $fake->renomeacoes);
    }

    #[TestDox('mensagem ENFILEIRADA ANTES do deploy (sem o campo renomear) não derruba o worker')]
    public function testMensagemAntigaNaFilaNaoQuebra(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '1217', 'nomeCliente' => 'ANTIGA']);

        // Reproduz o MECANISMO da falha, não o byte a byte da fila: o PhpSerializer grava
        // `addslashes(serialize($envelope))` — um Envelope escapado, não a mensagem nua. O que
        // importa aqui é o estado que aquele payload produz ao desserializar: uma mensagem com
        // TRÊS propriedades, serializada antes de `renomear` existir, deixa `renomear` NÃO
        // INICIALIZADA. Ler direto lançaria Error e o worker cairia em retry/failed.
        $classe  = SincronizarPastaNoDrive::class;
        $payload = sprintf(
            'O:%d:"%s":3:{s:7:"pastaId";i:%d;s:8:"tenantId";i:%d;s:9:"usuarioId";i:%d;}',
            strlen($classe),
            $classe,
            $pasta->getId(),
            (int) $tenant->getId(),
            (int) $user->getId(),
        );

        $mensagemAntiga = unserialize($payload);
        self::assertInstanceOf(SincronizarPastaNoDrive::class, $mensagemAntiga);
        self::assertFalse(isset($mensagemAntiga->renomear), 'o payload de teste precisa estar SEM o campo novo');

        $fake    = new FakeGoogleDriveClient();
        $handler = new SincronizarPastaNoDriveHandler(
            $this->em(),
            new FakeGoogleDriveClientFactory($fake, 'RAIZ'),
            self::getContainer()->get(ReconciliadorDePasta::class),
            new NullLogger(),
        );

        $handler($mensagemAntiga); // não pode lançar

        $em = $this->em();
        $em->clear();
        self::assertNotNull(
            $em->find(Pasta::class, $pasta->getId())->getDriveFolderId(),
            'a mensagem antiga tinha de ser processada normalmente (envio), sem renomear',
        );
        self::assertSame([], $fake->renomeacoes);
    }

    #[TestDox('R2: o worker NÃO importa — arquivo que só existe no Drive não entra no sistema')]
    public function testHandlerNaoImportaDoDrive(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '1216']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-1216', '1216', 'RAIZ');
        $fake->seedArquivo('ARQ-SO-NO-DRIVE', 'intruso.pdf', 'DRV-1216');
        $pasta->_real()->setDriveFolderId('DRV-1216');
        $this->em()->flush();

        $handler = new SincronizarPastaNoDriveHandler(
            $this->em(),
            new FakeGoogleDriveClientFactory($fake, 'RAIZ'),
            self::getContainer()->get(ReconciliadorDePasta::class),
            new NullLogger(),
        );

        $handler(new SincronizarPastaNoDrive($pasta->getId(), (int) $tenant->getId(), (int) $user->getId()));

        $em = $this->em();
        $em->clear();
        self::assertNull(
            $em->getRepository(\App\Pasta\Entity\PastaDocumento::class)->findOneBy(['driveFileId' => 'ARQ-SO-NO-DRIVE']),
            'o worker baixou do Drive — o R2 não vale no caminho por evento',
        );
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
