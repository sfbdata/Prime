<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O bloco `duplicadoDe[]` do JSON de `POST /pasta/{id}/peticionar/upload` — a rota que a aba
 * Documentos usa — e o `sha256` gravado no upload.
 *
 * Isolamento provado pelos dois lados: o mesmo conteúdo em OUTRO escritório não aparece, e o
 * mesmo conteúdo numa pasta que o usuário NÃO pode ver não aparece — e, para o verde não estar
 * provando outra barreira, cada caso tem o irmão que APARECE (outra pasta do mesmo escritório;
 * a mesma pasta depois de o acesso ser concedido).
 *
 * O storage é o dublê em memória: os documentos "já existentes" são só linhas com `sha256`,
 * e o upload novo não deixa arquivo no disco de teste.
 */
#[CoversClass(PeticionarController::class)]
final class PeticionarUploadDuplicadoControllerTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
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

    #[TestDox('upload grava o sha256 do arquivo e, sem outro igual, duplicadoDe vem vazio')]
    public function testUploadGravaOSha256ESemIgualListaVazia(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, isSystem: true);
        $pasta  = $this->criarPasta($tenant, 'NUP-A');
        $idA    = (int) $pasta->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $data = $this->enviar($client, $idA, self::PDF, 'procuracao.pdf');

        self::assertSame([], $data['duplicadoDe']);

        $docs = $this->documentosDaPasta($idA);
        self::assertCount(1, $docs);
        self::assertSame(hash('sha256', self::PDF), $docs[0]->getSha256());
    }

    #[TestDox('mesmo conteúdo em outra pasta (e nesta) do MESMO escritório: duplicadoDe lista pasta e título')]
    public function testAvisaDuplicadoNoMesmoEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, isSystem: true);
        $pastaA = $this->criarPasta($tenant, 'NUP-A');
        $pastaB = $this->criarPasta($tenant, 'NUP-B');
        $idA    = (int) $pastaA->getId();
        $idB    = (int) $pastaB->getId();
        $this->documentoExistente($pastaA, $tenant, self::PDF, 'Já nesta pasta');
        $this->documentoExistente($pastaB, $tenant, self::PDF, 'Contrato antigo');
        $this->documentoExistente($pastaB, $tenant, self::PDF . '% outro', 'Conteúdo diferente');
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $data = $this->enviar($client, $idA, self::PDF, 'procuracao.pdf');

        self::assertEqualsCanonicalizing(
            [
                ['pastaId' => $idA, 'pastaNup' => $pastaA->getNup(), 'titulo' => 'JÁ NESTA PASTA'],
                ['pastaId' => $idB, 'pastaNup' => $pastaB->getNup(), 'titulo' => 'CONTRATO ANTIGO'],
            ],
            $data['duplicadoDe'],
        );
        self::assertCount(2, $this->documentosDaPasta($idA), 'o aviso não bloqueia: o documento novo foi gravado');
    }

    #[TestDox('mesmo conteúdo em OUTRO escritório não aparece — enquanto o do próprio escritório aparece')]
    public function testNaoAvisaDuplicadoDeOutroEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenantA = $this->criarTenant();
        $tenantC = $this->criarTenant();
        $gestorA = $this->criarUsuario($tenantA, isSystem: true);
        $pastaA  = $this->criarPasta($tenantA, 'NUP-A');
        $pastaA2 = $this->criarPasta($tenantA, 'NUP-A2');
        $pastaC  = $this->criarPasta($tenantC, 'NUP-C');
        $idA     = (int) $pastaA->getId();
        $idA2    = (int) $pastaA2->getId();
        $idC     = (int) $pastaC->getId();
        $this->documentoExistente($pastaC, $tenantC, self::PDF, 'Do outro escritório');
        $this->documentoExistente($pastaA2, $tenantA, self::PDF, 'Do próprio escritório');
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $data = $this->enviar($client, $idA, self::PDF, 'procuracao.pdf');

        $pastas = array_column($data['duplicadoDe'], 'pastaId');
        self::assertSame([$idA2], $pastas, 'só a pasta do próprio escritório');
        self::assertNotContains($idC, $pastas);
        self::assertSame('DO PRÓPRIO ESCRITÓRIO', $data['duplicadoDe'][0]['titulo']);
    }

    #[TestDox('mesmo conteúdo numa pasta que o usuário NÃO pode ver não aparece; concedido o acesso, aparece')]
    public function testNaoAvisaDuplicadoEmPastaQueNaoPodeVer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuario($tenant, isSystem: false);
        $pastaA  = $this->criarPasta($tenant, 'NUP-A');
        $pastaB  = $this->criarPasta($tenant, 'NUP-B');
        $idA     = (int) $pastaA->getId();
        $idB     = (int) $pastaB->getId();
        $this->concederAcesso($usuario, $tenant, $idA, view: true, edit: true);
        $this->documentoExistente($pastaB, $tenant, self::PDF, 'Restrito');
        $this->limparIdentityMap();

        $this->logarComTenant($client, $usuario, $tenant);
        $data = $this->enviar($client, $idA, self::PDF, 'procuracao.pdf');

        self::assertSame([], $data['duplicadoDe'], 'pasta sem permissão de ver não é revelada');

        // O irmão: concedido o acesso de VER a pasta B, o mesmo upload passa a avisar.
        $this->concederAcesso($usuario, $tenant, $idB, view: true, edit: false);
        $this->limparIdentityMap();

        $data = $this->enviar($client, $idA, self::PDF, 'procuracao2.pdf');

        $pastas = array_column($data['duplicadoDe'], 'pastaId');
        self::assertContains($idB, $pastas, 'a barreira era a permissão: concedida, a pasta aparece');
        self::assertContains($idA, $pastas, 'o primeiro upload, na própria pasta, também é igual');
    }

    // ----------------------------------------------------------------- helpers

    /** @return array<string, mixed> JSON decodificado da resposta 200 */
    private function enviar(KernelBrowser $client, int $pastaId, string $conteudo, string $nome): array
    {
        $origem = sys_get_temp_dir() . '/petic_dup_' . bin2hex(random_bytes(6));
        file_put_contents($origem, $conteudo);
        $this->arquivosCriados[] = $origem;

        $client->request(
            'POST',
            "/pasta/{$pastaId}/peticionar/upload",
            ['_token' => 'TOKEN_peticionar_upload_' . $pastaId, 'categoria' => PastaDocumento::CATEGORIA_DEMAIS],
            ['arquivo' => new UploadedFile($origem, $nome, 'application/pdf', null, true)],
        );

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('duplicadoDe', $data, 'o bloco existe sempre, mesmo vazio');
        self::assertIsArray($data['duplicadoDe']);

        return $data;
    }

    /** @return list<PastaDocumento> */
    private function documentosDaPasta(int $pastaId): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em->getRepository(PastaDocumento::class)->findBy(['pasta' => $pastaId], ['id' => 'ASC']);
    }

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant DUP ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** Papel de sistema = gestor (vê tudo do escritório); sem ele, o acesso é por ResourceAccess. */
    private function criarUsuario(Tenant $tenant, bool $isSystem): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('dup_' . uniqid() . '@test.com');
        $user->setFullName('Usuário DUP ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem($isSystem);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $nup): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup . '-' . uniqid()); // nup é único no banco; a asserção usa o getter
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /** Linha de documento já existente, só com o hash — não precisa de arquivo para a busca. */
    private function documentoExistente(Pasta $pasta, Tenant $tenant, string $conteudo, string $titulo): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($pasta)
            ->setTitulo($titulo)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo('legado-' . bin2hex(random_bytes(8)) . '.pdf')
            ->setNomeOriginal($titulo . '.pdf')
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(\strlen($conteudo))
            ->setSha256(hash('sha256', $conteudo));
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function concederAcesso(User $user, Tenant $tenant, int $pastaId, bool $view, bool $edit): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setTenant($tenant);
        $ra->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $ra->setResourceId($pastaId);
        $ra->setCanView($view);
        $ra->setCanEdit($edit);
        $em->persist($ra);
        $em->flush();
    }
}
