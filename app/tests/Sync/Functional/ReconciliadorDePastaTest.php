<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use Zenstruck\Foundry\Test\Factories;

/**
 * Testa o serviço por-pasta diretamente pelo ponto de entrada `sincronizarPasta` — exatamente como
 * o handler da Fase 2 o chamará (uma pasta, um client, uma raiz).
 */
#[CoversClass(ReconciliadorDePasta::class)]
final class ReconciliadorDePastaTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarDocumento(int $pastaId, string $nomeOriginal): void
    {
        $em          = $this->em();
        $pasta       = $em->find(Pasta::class, $pastaId);
        $storage     = self::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir  = (string) self::getContainer()->getParameter('uploads_dir');
        $nomeStorage = $storage->salvarConteudo('conteudo-' . $nomeOriginal, $uploadsDir, 'pdf');

        $doc = (new PastaDocumento())
            ->setTitulo($nomeOriginal)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($nomeStorage)
            ->setNomeOriginal($nomeOriginal)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setTenant($pasta->getTenant());
        $em->persist($doc);
        $em->flush();
    }

    #[TestDox('sincronizarPasta cria o folder no Drive e sobe o documento pendente')]
    public function testSincronizaUmaPastaPontaAPonta(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '777', 'nomeCliente' => 'FULANO']);
        $this->criarDocumento($pasta->getId(), 'peca.pdf');

        $fake = new FakeGoogleDriveClient();
        $reconciliador = self::getContainer()->get(ReconciliadorDePasta::class);

        $resultado = $reconciliador->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);

        // Folder criado sob a raiz e vínculo gravado.
        self::assertSame(1, $resultado->criadasNoDrive);
        self::assertSame(1, $resultado->arquivosEnviados);
        self::assertSame(0, $resultado->erros);
        self::assertFalse($resultado->fatal);

        $em = $this->em();
        $em->clear();
        $folderId = $em->find(Pasta::class, $pasta->getId())->getDriveFolderId();
        self::assertNotNull($folderId);
        self::assertSame('777 - FULANO', $fake->pastas[$folderId]['nome']);
        self::assertSame('RAIZ', $fake->pastas[$folderId]['parent']);

        // Documento subiu para a raiz do caso e ganhou drive_file_id.
        $driveFileId = $em->getConnection()->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE nome_original = :n', ['n' => 'peca.pdf']);
        self::assertNotFalse($driveFileId);
        self::assertNotNull($driveFileId);
        self::assertSame($folderId, $fake->arquivos[$driveFileId]['folder']);
    }

    #[TestDox('sincronizarPasta é idempotente — a 2ª chamada não recria nem re-sobe nada')]
    public function testIdempotente(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '888', 'nomeCliente' => 'X']);
        $this->criarDocumento($pasta->getId(), 'doc.pdf');

        $fake = new FakeGoogleDriveClient();
        $reconciliador = self::getContainer()->get(ReconciliadorDePasta::class);

        $reconciliador->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);
        $segundo = $reconciliador->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);

        self::assertSame(0, $segundo->criadasNoDrive, 'folder já existe → não recria');
        self::assertSame(0, $segundo->arquivosEnviados, 'documento já tem drive_file_id → não re-sobe');
        self::assertCount(1, $fake->arquivos, 'nenhum arquivo duplicado no Drive');
    }

    // ───────────────────────────────────────────────────────────────────────────────────────
    // R3 — renomearNoDrive. Os guards abaixo existem para NÃO estragar o nome que está no
    // Drive num caso de borda. Estavam escritos e não provados (achado da revisão).
    // ───────────────────────────────────────────────────────────────────────────────────────

    #[TestDox('R3: renomeia a pasta vinculada para o nome atual do sistema')]
    public function testRenomeiaPastaVinculada(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '1227', 'nomeCliente' => 'JORGE']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-X', 'NOME VELHO', 'RAIZ');
        $pasta->_real()->setDriveFolderId('DRV-X');
        $this->em()->flush();

        $r = new ResultadoReconciliacaoPasta();
        self::getContainer()->get(ReconciliadorDePasta::class)->renomearNoDrive($pasta->getId(), $fake, false, $r);

        self::assertSame('1227 - JORGE', $fake->pastas['DRV-X']['nome']);
        self::assertSame(1, $r->renomeadasNoDrive);
        self::assertSame(0, $r->erros);
    }

    #[TestDox('R3: pasta SEM vínculo no Drive é no-op silencioso (não inventa pasta)')]
    public function testRenomearSemVinculoEhNoOp(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '500']);

        $fake = new FakeGoogleDriveClient();
        $r    = new ResultadoReconciliacaoPasta();
        self::getContainer()->get(ReconciliadorDePasta::class)->renomearNoDrive($pasta->getId(), $fake, false, $r);

        self::assertSame([], $fake->renomeacoes);
        self::assertSame(0, $r->renomeadasNoDrive);
        self::assertSame(0, $r->erros, 'sem vínculo não é erro — o envio normal cria com o nome certo');
    }

    #[TestDox('R3: nome esperado VAZIO não apaga o nome que está no Drive')]
    public function testRenomearComNomeVazioNaoApagaNoDrive(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        // nup vazio + cliente/ação nulos → nomeEsperado() devolve ''. Renomear para '' deixaria
        // a pasta do Drive SEM NOME — perda de dado silenciosa, difícil de reverter à mão.
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '', 'nomeCliente' => null, 'nomeAcao' => null]);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-Y', 'NOME QUE NAO PODE SUMIR', 'RAIZ');
        $pasta->_real()->setDriveFolderId('DRV-Y');
        $this->em()->flush();

        $r = new ResultadoReconciliacaoPasta();
        self::getContainer()->get(ReconciliadorDePasta::class)->renomearNoDrive($pasta->getId(), $fake, false, $r);

        self::assertSame('NOME QUE NAO PODE SUMIR', $fake->pastas['DRV-Y']['nome']);
        self::assertSame([], $fake->renomeacoes);
    }

    #[TestDox('R3: dry-run conta mas NÃO escreve no Drive')]
    public function testRenomearDryRunNaoEscreve(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '600', 'nomeCliente' => 'TESTE']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-Z', 'INTOCADO', 'RAIZ');
        $pasta->_real()->setDriveFolderId('DRV-Z');
        $this->em()->flush();

        $r = new ResultadoReconciliacaoPasta();
        self::getContainer()->get(ReconciliadorDePasta::class)->renomearNoDrive($pasta->getId(), $fake, true, $r);

        self::assertSame('INTOCADO', $fake->pastas['DRV-Z']['nome']);
        self::assertSame(1, $r->renomeadasNoDrive, 'o dry-run precisa dizer o que FARIA');
        self::assertSame([], $fake->renomeacoes);
    }

    #[TestDox('R3: erro do Drive vira contador de erro, não exceção que derruba o worker')]
    public function testRenomearComErroDoDriveNaoLanca(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '700', 'nomeCliente' => 'X']);

        $fake = new FakeGoogleDriveClient();
        // Vínculo aponta para um folder que não existe no Drive (apagado à mão, por exemplo):
        // o fake lança, como a API real lançaria em 404.
        $pasta->_real()->setDriveFolderId('DRV-INEXISTENTE');
        $this->em()->flush();

        $r = new ResultadoReconciliacaoPasta();
        self::getContainer()->get(ReconciliadorDePasta::class)->renomearNoDrive($pasta->getId(), $fake, false, $r);

        self::assertSame(1, $r->erros);
        self::assertSame(0, $r->renomeadasNoDrive);
        self::assertStringContainsString('renomear no Drive', $r->mensagens[0]);
    }
}
