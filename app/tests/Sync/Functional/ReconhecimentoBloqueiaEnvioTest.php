<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Sync\Command\ReconciliarCommand;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use App\Sync\Entity\ReconhecimentoPendente;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use App\Tests\Sync\Support\FakeGoogleDriveClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Reconhecimento do Drive: enquanto a dúvida está pendente, o envio não cria pasta nova para as candidatas e a
 * importação não cria pasta no sistema a partir da subpasta (senão o caso fica em dobro).
 */
#[CoversClass(ReconciliadorDePasta::class)]
#[CoversClass(ReconciliarCommand::class)]
final class ReconhecimentoBloqueiaEnvioTest extends KernelTestCase
{
    use Factories;

    private const ROOT = 'ROOT';

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<int> $candidatos */
    private function pendencia(Tenant $tenant, string $folderId, array $candidatos): ReconhecimentoPendente
    {
        $pendencia = new ReconhecimentoPendente($tenant, $folderId, 'Nome no Drive', $candidatos);
        $this->em()->persist($pendencia);
        $this->em()->flush();

        return $pendencia;
    }

    #[TestDox('pasta citada por dúvida pendente não ganha pasta nova no Drive; depois de ignorada, ganha')]
    public function testPendenciaBloqueiaGarantirFolder(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $pastaId = (int) PastaFactory::createOne(['tenant' => $tenant, 'nup' => '80', 'nomeCliente' => 'ZECA'])->getId();
        $pendencia = $this->pendencia($tenant, 'D-ZECA', [999999, $pastaId]);
        $fake = new FakeGoogleDriveClient();
        $reconciliador = self::getContainer()->get(ReconciliadorDePasta::class);

        $r = new ResultadoReconciliacaoPasta();
        $reconciliador->garantirFolderDaPasta($pastaId, self::ROOT, $fake, false, $r);

        self::assertSame([], $fake->pastas, 'criou pasta no Drive com a dúvida pendente');
        self::assertSame(0, $r->criadasNoDrive);
        self::assertStringContainsString('[reconhecimento]', implode("\n", $r->mensagens));

        $pendencia->ignorar(null);
        $this->em()->flush();
        $reconciliador->garantirFolderDaPasta($pastaId, self::ROOT, $fake, false, $r);

        self::assertSame(1, $r->criadasNoDrive);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Pasta::class, $pastaId)?->getDriveFolderId());
    }

    #[TestDox('a importação (Drive para o sistema) não cria pasta a partir de subpasta com dúvida pendente')]
    public function testDriveParaSistemaPulaSubpastaPendente(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $user = UserFactory::createOne();
        $this->pendencia($tenant, 'D-PEND', []);
        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('D-PEND', '500 - FULANO', self::ROOT);
        $fake->seedPasta('D-LIVRE', '501 - CICRANO', self::ROOT);
        self::getContainer()->set(GoogleDriveClientFactoryInterface::class, new FakeGoogleDriveClientFactory($fake, self::ROOT));

        $tester = new CommandTester((new Application(self::$kernel))->find('app:sync:reconciliar'));
        $tester->execute([
            '--modo' => 'importar',
            '--tenant-id' => (string) $tenant->getId(),
            '--usuario-id' => (string) $user->getId(),
            '--skip-arquivos' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $this->em()->clear();
        $repo = $this->em()->getRepository(Pasta::class);
        self::assertNull($repo->findOneBy(['driveFolderId' => 'D-PEND']), 'criou pasta da subpasta com dúvida pendente');
        self::assertNotNull($repo->findOneBy(['driveFolderId' => 'D-LIVRE']), 'a importação parou de funcionar para as outras');
        self::assertStringContainsString('[reconhecimento]', $tester->getDisplay());
    }
}
