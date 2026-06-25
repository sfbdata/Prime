<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Controller\ServiceDeskController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Anexos de chamado são servidos pela rota controlada (auth + tenant + posse), não como
 * arquivo público estático.
 */
#[CoversClass(ServiceDeskController::class)]
final class AnexoDownloadControllerTest extends JusPrimeWebTestCase
{
    /** @var string[] arquivos criados em disco, para limpeza */
    private array $arquivosCriados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        $this->arquivosCriados = [];

        parent::tearDown();
    }

    #[TestDox('Dono do chamado baixa o anexo pela rota controlada (200)')]
    public function testDonoBaixaAnexo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $dono = $this->criarUsuario($tenant, 'dono_' . uniqid() . '@test.com', false);
        $chamado = $this->criarChamado($tenant, $dono);
        $anexo = $this->criarAnexo($chamado, $dono);

        $this->logarComTenant($client, $dono, $tenant);
        $client->request('GET', "/servicedesk/{$chamado->getId()}/anexo/{$anexo->getId()}");

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertStringContainsString(
            'documento.txt',
            (string) $response->headers->get('content-disposition')
        );
        // Serve o arquivo certo de verdade (não só o header).
        self::assertSame('conteudo de teste', $response->getFile()->getContent());
    }

    #[TestDox('Gestor do mesmo tenant baixa anexo de chamado de outro usuário (200)')]
    public function testAdminDoMesmoTenantBaixa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, 'gestor_' . uniqid() . '@test.com', true);
        $dono = $this->criarUsuario($tenant, 'dono_' . uniqid() . '@test.com', false);
        $chamado = $this->criarChamado($tenant, $dono);
        $anexo = $this->criarAnexo($chamado, $dono);

        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('GET', "/servicedesk/{$chamado->getId()}/anexo/{$anexo->getId()}");

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Anexo de outro chamado do mesmo tenant é negado por posse (404)')]
    public function testAnexoDeOutroChamadoRetorna404(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $dono = $this->criarUsuario($tenant, 'dono_' . uniqid() . '@test.com', false);
        $chamadoA = $this->criarChamado($tenant, $dono);
        $chamadoB = $this->criarChamado($tenant, $dono);
        $anexoB = $this->criarAnexo($chamadoB, $dono);

        $this->logarComTenant($client, $dono, $tenant);
        // anexo de B pedido sob o chamado A → não pertence → 404
        $client->request('GET', "/servicedesk/{$chamadoA->getId()}/anexo/{$anexoB->getId()}");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Gestor de outro tenant não baixa o anexo (404)')]
    public function testCrossTenantRetorna404(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarUsuario($tenantA, 'gestorA_' . uniqid() . '@test.com', true);
        $donoB = $this->criarUsuario($tenantB, 'donoB_' . uniqid() . '@test.com', false);
        $chamadoB = $this->criarChamado($tenantB, $donoB);
        $anexoB = $this->criarAnexo($chamadoB, $donoB);

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/servicedesk/{$chamadoB->getId()}/anexo/{$anexoB->getId()}");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Usuário comum que não é o solicitante não baixa o anexo (403)')]
    public function testEstranhoNegado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $dono = $this->criarUsuario($tenant, 'dono_' . uniqid() . '@test.com', false);
        $estranho = $this->criarUsuario($tenant, 'estr_' . uniqid() . '@test.com', false);
        $chamado = $this->criarChamado($tenant, $dono);
        $anexo = $this->criarAnexo($chamado, $dono);

        $this->logarComTenant($client, $estranho, $tenant);
        $client->request('GET', "/servicedesk/{$chamado->getId()}/anexo/{$anexo->getId()}");

        self::assertResponseStatusCodeSame(403);
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $email, bool $isSistema): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName(($isSistema ? 'Gestor ' : 'Colaborador ') . uniqid());
        $role->setIsSystem($isSistema);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarChamado(Tenant $tenant, User $solicitante): Chamado
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $chamado = new Chamado();
        $chamado->setTitulo('Chamado com anexo ' . uniqid());
        $chamado->setDescricao('desc');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($solicitante);
        $em->persist($chamado);
        $em->flush();

        return $chamado;
    }

    private function criarAnexo(Chamado $chamado, User $enviadoPor): ChamadoAnexo
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $dir = static::getContainer()->getParameter('chamados_uploads_dir');

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $nomeArquivo = 'test_' . uniqid() . '.txt';
        $caminho = $dir . '/' . $nomeArquivo;
        file_put_contents($caminho, 'conteudo de teste');
        $this->arquivosCriados[] = $caminho;

        $anexo = new ChamadoAnexo();
        $anexo->setChamado($chamado);
        $anexo->setEnviadoPor($enviadoPor);
        $anexo->setNomeOriginal('documento.txt');
        $anexo->setNomeArquivo($nomeArquivo);
        $anexo->setMimeType('text/plain');
        $anexo->setTamanho(17);
        $chamado->addAnexo($anexo);

        $em->persist($anexo);
        $em->flush();

        return $anexo;
    }
}
