<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Sync\Controller\DriveConexaoController;
use App\Sync\Entity\ReconhecimentoPendente;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Enum\EstadoReconhecimento;
use App\Sync\Message\ReconhecerPastasDoDrive;
use App\Sync\Service\CifradorDeSegredo;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\UseCase\DecidirReconhecimentoUseCase;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use App\Tests\Sync\Support\FakeGoogleDriveClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/** Tela "Conectar meu Drive": as dúvidas do reconhecimento e o pedido de reconhecer. */
#[CoversClass(DriveConexaoController::class)]
#[CoversClass(DecidirReconhecimentoUseCase::class)]
final class DriveReconhecimentoControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private FakeGoogleDriveClient $fake;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Reconhecimento ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('reconhecimento_' . uniqid() . '@test.com');
        $user->setFullName('Admin Drive');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador do Escritório');
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $user;
    }

    private function criarConexao(Tenant $tenant, User $user, bool $comPastaRaiz = true): void
    {
        $cifrador = static::getContainer()->get(CifradorDeSegredo::class);
        $conexao = new TenantDriveConexao($tenant);
        $conexao->registrarCredenciais($cifrador->cifrar('refresh'), 'dono@x.com', 'drive', $user);
        if ($comPastaRaiz) {
            $conexao->definirRootFolder('ROOT');
        }
        $this->em()->persist($conexao);
        $this->em()->flush();
    }

    /** @param list<int> $candidatos */
    private function pendencia(Tenant $tenant, string $folderId, string $nome, array $candidatos): int
    {
        $pendencia = new ReconhecimentoPendente($tenant, $folderId, $nome, $candidatos);
        $this->em()->persist($pendencia);
        $this->em()->flush();

        return (int) $pendencia->getId();
    }

    private function prepararContainer(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
        $this->fake = new FakeGoogleDriveClient();
        static::getContainer()->set(GoogleDriveClientFactoryInterface::class, new FakeGoogleDriveClientFactory($this->fake, 'ROOT'));
    }

    /** @return array{0: KernelBrowser, 1: Tenant, 2: User} */
    private function logado(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->prepararContainer();
        $tenant = $this->criarTenant();
        $user = $this->criarAdmin($tenant);
        $this->criarConexao($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $tenant, $user];
    }

    private function recarregarPendencia(int $id): ReconhecimentoPendente
    {
        $this->em()->clear();
        $pendencia = $this->em()->find(ReconhecimentoPendente::class, $id);
        self::assertNotNull($pendencia);

        return $pendencia;
    }

    private function folderDaPasta(int $pastaId): ?string
    {
        $this->em()->clear();

        return $this->em()->find(Pasta::class, $pastaId)?->getDriveFolderId();
    }

    #[TestDox('a tela lista a dúvida com o nome no Drive e o número e o cliente das candidatas')]
    public function testTelaListaPendencia(): void
    {
        [$client, $tenant] = $this->logado();
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '321', 'nomeCliente' => 'ANA PAULA']);
        $id = $this->pendencia($tenant, 'D-ANA', 'Ana Paula (antiga)', [(int) $pasta->getId()]);

        $client->request('GET', '/sync/drive/conexao');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tr[data-reconhecimento="' . $id . '"]', 'Ana Paula (antiga)');
        self::assertSelectorTextContains('tr[data-reconhecimento="' . $id . '"] option', '321');
        self::assertSelectorTextContains('tr[data-reconhecimento="' . $id . '"] option', 'ANA PAULA');
        self::assertSelectorExists('form[action="/sync/drive/conexao/reconhecer"]');
    }

    #[TestDox('ligar a uma candidata grava o vínculo, liga os arquivos e marca confirmado')]
    public function testLigarACandidata(): void
    {
        [$client, $tenant, $user] = $this->logado();
        $pastaId = (int) PastaFactory::createOne(['tenant' => $tenant, 'nup' => '11', 'nomeCliente' => 'BETO'])->getId();
        $id = $this->pendencia($tenant, 'D-BETO', 'Beto', [$pastaId]);

        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ligar', [
            '_token' => 'TOKEN_sync_drive_reconhecimento_' . $id,
            'pasta_id' => (string) $pastaId,
        ]);

        self::assertResponseRedirects('/sync/drive/conexao');
        self::assertSame('D-BETO', $this->folderDaPasta($pastaId));
        $pendencia = $this->recarregarPendencia($id);
        self::assertSame(EstadoReconhecimento::Confirmado, $pendencia->getEstado());
        self::assertSame($pastaId, $pendencia->getPastaLigadaId());
        self::assertSame($user->getId(), $pendencia->getDecididoPor()?->getId());
        self::assertSame([], $this->fake->pastas, 'ligar não cria nada no Drive');
    }

    #[TestDox('sem candidata, ligar pelo número digitado')]
    public function testLigarPeloNumero(): void
    {
        [$client, $tenant] = $this->logado();
        $pastaId = (int) PastaFactory::createOne(['tenant' => $tenant, 'nup' => '12B', 'nomeCliente' => 'CAIO'])->getId();
        $id = $this->pendencia($tenant, 'D-CAIO', 'Caio', []);

        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ligar', [
            '_token' => 'TOKEN_sync_drive_reconhecimento_' . $id,
            'nup' => ' 12b ',
        ]);

        self::assertSame('D-CAIO', $this->folderDaPasta($pastaId));
        self::assertSame(EstadoReconhecimento::Confirmado, $this->recarregarPendencia($id)->getEstado());
    }

    #[TestDox('pasta de outro escritório é recusada e a dúvida continua pendente')]
    public function testPastaDeOutroTenantRecusada(): void
    {
        [$client, $tenant] = $this->logado();
        $outroId = (int) PastaFactory::createOne(['tenant' => $this->criarTenant(), 'nup' => '900', 'nomeCliente' => 'ALHEIO'])->getId();
        $id = $this->pendencia($tenant, 'D-ALHEIO', 'Alheio', []);

        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ligar', [
            '_token' => 'TOKEN_sync_drive_reconhecimento_' . $id,
            'nup' => '900',
        ]);
        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ligar', [
            '_token' => 'TOKEN_sync_drive_reconhecimento_' . $id,
            'pasta_id' => (string) $outroId,
        ]);

        self::assertNull($this->folderDaPasta($outroId));
        self::assertSame(EstadoReconhecimento::Pendente, $this->recarregarPendencia($id)->getEstado());
    }

    #[TestDox('dúvida de outro escritório não é decidida por este')]
    public function testPendenciaDeOutroTenantIntocada(): void
    {
        [$client] = $this->logado();
        $id = $this->pendencia($this->criarTenant(), 'D-OUTRO', 'Outro', []);

        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ignorar', [
            '_token' => 'TOKEN_sync_drive_reconhecimento_' . $id,
        ]);

        // Sem o TenantFilter (a pendência é de outro escritório): direto no banco.
        self::assertSame('pendente', $this->em()->getConnection()->fetchOne('SELECT estado FROM sync_drive_reconhecimento WHERE id = :id', ['id' => $id]));
    }

    #[TestDox('ignorar marca a dúvida como ignorada')]
    public function testIgnorar(): void
    {
        [$client, $tenant] = $this->logado();
        $id = $this->pendencia($tenant, 'D-IGN', 'Ignorada', []);

        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ignorar', [
            '_token' => 'TOKEN_sync_drive_reconhecimento_' . $id,
        ]);

        self::assertResponseRedirects('/sync/drive/conexao');
        $pendencia = $this->recarregarPendencia($id);
        self::assertSame(EstadoReconhecimento::Ignorado, $pendencia->getEstado());
        self::assertNotNull($pendencia->getDecididoEm());
    }

    #[TestDox('CSRF errado nas ações da dúvida e no reconhecer devolve 403')]
    public function testCsrfErrado(): void
    {
        [$client, $tenant] = $this->logado();
        $id = $this->pendencia($tenant, 'D-CSRF', 'Csrf', []);

        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ignorar', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/sync/drive/conexao/reconhecimento/' . $id . '/ligar', ['_token' => 'errado', 'nup' => '1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/sync/drive/conexao/reconhecer', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(EstadoReconhecimento::Pendente, $this->recarregarPendencia($id)->getEstado());
    }

    /** @return list<ReconhecerPastasDoDrive> */
    private function pedidosDeReconhecimento(InMemoryTransport $async): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $async->getSent()),
            static fn (object $m): bool => $m instanceof ReconhecerPastasDoDrive,
        ));
    }

    #[TestDox('definir a pasta-raiz e o botão "Reconhecer de novo" pedem o reconhecimento ao worker')]
    public function testDefinirRaizEReconhecerDisparamOReconhecimento(): void
    {
        [$client, $tenant] = $this->logado();
        /** @var InMemoryTransport $async */
        $async = static::getContainer()->get('messenger.transport.async');

        $client->request('POST', '/sync/drive/conexao/pasta-raiz', [
            '_token' => 'TOKEN_sync_drive_pasta_raiz',
            'pasta_raiz' => 'https://drive.google.com/drive/folders/1WBY-hLuad3weKWPBM0f297_RtWTJo0_R',
        ]);
        $mensagens = $this->pedidosDeReconhecimento($async);
        $client->request('POST', '/sync/drive/conexao/reconhecer', ['_token' => 'TOKEN_sync_drive_reconhecer']);
        $mensagens = array_merge($mensagens, $this->pedidosDeReconhecimento($async));

        self::assertCount(2, $mensagens);
        self::assertSame($tenant->getId(), $mensagens[0]->tenantId);
    }

    #[TestDox('reconhecimento em falha: a tela avisa e "Reconhecer de novo" volta a aguardar, com a trava mantida')]
    public function testFalhaAvisaETentaDeNovo(): void
    {
        [$client, $tenant] = $this->logado();
        $conexao = $this->em()->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($conexao);
        $conexao->marcarFalhaDoReconhecimento();
        $this->em()->flush();

        $client->request('GET', '/sync/drive/conexao');
        self::assertSelectorTextContains('[data-reconhecimento-estado="falhou"]', 'Reconhecer de novo');

        $client->request('POST', '/sync/drive/conexao/reconhecer', ['_token' => 'TOKEN_sync_drive_reconhecer']);
        $this->em()->clear();
        $depois = $this->em()->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]);
        self::assertSame(TenantDriveConexao::RECONHECIMENTO_AGUARDANDO, $depois?->getReconhecimento());
    }
}
