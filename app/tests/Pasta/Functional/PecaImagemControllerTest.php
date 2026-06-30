<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PecaImagemController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * M5 — a imagem do editor de peças (`/uploads/pastas/<nome>`) passa a ser isolada por tenant:
 * o arquivo mora em `pastas/<tenantId>/` e o controller só resolve sob a subpasta do tenant da
 * SESSÃO. Um logado do escritório A não baixa a imagem de B mesmo sabendo o nome hex (404).
 * Sem tenant na sessão (super-admin) → 404 (fail-closed). O firewall (^/ ROLE_USER) barra o anônimo.
 * Documentos (.pdf/.html) NÃO casam a rota (têm rotas próprias por entidade, com tenant/posse).
 */
#[CoversClass(PecaImagemController::class)]
final class PecaImagemControllerTest extends JusPrimeWebTestCase
{
    /** @var list<string> */
    private array $arquivosTemp = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosTemp as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
            // Higiene: remove a subpasta de tenant se ficou vazia (evita acúmulo entre execuções).
            $dir = dirname($arquivo);
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->arquivosTemp = [];
        parent::tearDown();
    }

    #[TestDox('Anônimo é bloqueado pelo firewall (redirect p/ login), nunca recebe o arquivo')]
    public function testAnonimoBloqueado(): void
    {
        $client = static::createClient();
        $client->request('GET', '/uploads/pastas/qualquer.jpg');

        self::assertResponseStatusCodeSame(302, 'sem login o firewall deve redirecionar, não servir');
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Autenticado recebe a imagem da PRÓPRIA subpasta de tenant (200, inline)')]
    public function testAutenticadoServeImagemDaPropriaSubpasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $nome = $this->criarArquivoNaSubpasta((int) $tenant->getId(), '.png');
        $client->request('GET', '/uploads/pastas/' . $nome);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('inline', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    #[TestDox('Cross-tenant: imagem na subpasta de B não é servida a um logado de A (404) — vetor M5')]
    public function testCrossTenantNaoServe(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarUsuarioComTenant();
        [, $tenantB]       = $this->criarUsuarioComTenant();

        // Arquivo de B existe na subpasta de B e TAMBÉM solto no flat (resíduo legado de B) —
        // a dupla presença faz este teste matar também a regressão "servir do flat".
        $nome = $this->criarArquivoNaSubpasta((int) $tenantB->getId(), '.png');
        $this->criarArquivoNoFlat('.png', $nome);

        // Sessão logada no tenant A pede o nome do arquivo de B.
        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', '/uploads/pastas/' . $nome);

        self::assertResponseStatusCodeSame(404, 'imagem de outro escritório não pode ser servida');
    }

    #[TestDox('Arquivo solto no diretório plano (legado/não-isolado) NÃO é servido — só a subpasta do tenant conta')]
    public function testArquivoNoFlatNaoServe(): void
    {
        // Trava o escopo: mesmo com o arquivo existindo no diretório PLANO (resíduo legado antes do
        // move de deploy), o serve só olha `pastas/<tenantId>/`. Mata a regressão "servir do flat".
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();

        $nome = $this->criarArquivoNoFlat('.png');

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/uploads/pastas/' . $nome);

        self::assertResponseStatusCodeSame(404, 'arquivo plano (fora da subpasta do tenant) não pode ser servido');
    }

    #[TestDox('Sem tenant na sessão (super-admin) → 404 (fail-closed), mesmo com o arquivo no disco')]
    public function testSemTenantNaSessaoRetorna404(): void
    {
        // Só o super-admin alcança o controller sem tenant na sessão; o usuário comum é desviado
        // antes para /escritorio/selecionar (TenantContextValidatorListener). É o vetor fail-closed.
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant(['ROLE_SUPER_ADMIN', 'ROLE_USER']);

        $nome = $this->criarArquivoNaSubpasta((int) $tenant->getId(), '.png');

        // Loga SEM setar current_tenant_id na sessão (apenas o termo aceito).
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/uploads/pastas/' . $nome);

        self::assertResponseStatusCodeSame(404, 'sem tenant na sessão não há subpasta resolvível → 404');
    }

    #[TestDox('Autenticado + arquivo inexistente na própria subpasta retorna 404')]
    public function testAutenticadoInexistente404(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/uploads/pastas/naoexiste_' . uniqid() . '.jpg');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Extensão não-imagem (.pdf) não casa a rota — documentos não são servidos por aqui')]
    public function testExtensaoNaoImagemNaoCasa(): void
    {
        // Trava a garantia de segurança: só imagens passam. Documentos/peças (.pdf/.html/.docx)
        // têm rotas próprias por entidade (com tenant/posse). Mesmo logado e com o arquivo no
        // disco, a requirement da rota rejeita a extensão → 404 (rota não encontrada).
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $nome = $this->criarArquivoNaSubpasta((int) $tenant->getId(), '.pdf');
        $client->request('GET', '/uploads/pastas/' . $nome);

        self::assertResponseStatusCodeSame(404, 'extensão não-imagem não pode ser servida pela rota de imagem');
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param list<string> $roles
     *
     * @return array{0: User, 1: Tenant}
     */
    private function criarUsuarioComTenant(array $roles = ['ROLE_USER']): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant PecaImg ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('peca_img_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Peça Imagem');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return [$user, $tenant];
    }

    private function criarArquivoNaSubpasta(int $tenantId, string $ext): string
    {
        return $this->gravarArquivo($this->baseUploads() . '/' . $tenantId, $ext);
    }

    private function criarArquivoNoFlat(string $ext, ?string $nome = null): string
    {
        return $this->gravarArquivo($this->baseUploads(), $ext, $nome);
    }

    private function baseUploads(): string
    {
        return rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/');
    }

    private function gravarArquivo(string $dir, string $ext, ?string $nome = null): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $nome ??= 'testfile_' . uniqid() . $ext;
        $caminho = $dir . '/' . $nome;
        file_put_contents($caminho, 'conteudo-de-teste');
        $this->arquivosTemp[] = $caminho;

        return $nome;
    }
}
