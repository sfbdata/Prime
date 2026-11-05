<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tenant\Service\RegistroDeAcessoNegadoAoEscritorio;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Achado 2.03 da auditoria: na edição legada do escritório (app_tenant_edit) os dados do escritório (nome, CNPJ,
 * endereço) seguem a D1 do Meu Escritório: só o primário ou o Master alteram. O secundário com
 * admin.tenant.settings.manage recebe 403 e a tentativa vai para o audit_log; cargos e lotações seguem com ele.
 */
#[CoversClass(TenantController::class)]
final class EditarEscritorioDadosSoPrimarioTest extends JusPrimeWebTestCase
{
    private const NOME_ORIGINAL = 'Escritório Original';
    private const CNPJ_ORIGINAL = '11222333000181';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $primario;
    private User $secundario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName(self::NOME_ORIGINAL);
        $this->tenant->setCnpj(self::CNPJ_ORIGINAL);
        $this->em->persist($this->tenant);

        $perfilPrimario   = $this->criarPerfil('Administrador do Escritório', true);
        $perfilSecundario = $this->criarPerfil('Gerente', false);

        $this->primario   = $this->criarUsuario('Ana Primária', $perfilPrimario);
        $this->secundario = $this->criarUsuario('Bruno Gerente', $perfilSecundario);
        $this->tenant->setCriadoPor($this->primario);
        $this->em->flush();

        $this->darPermissao($perfilSecundario, 'admin.tenant.settings.manage');
    }

    #[TestDox('Secundário com settings.manage que manda o POST dos dados recebe 403, nada muda e a tentativa fica na trilha')]
    public function testSecundarioNaoAlteraOsDados(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $url = $this->urlEditar();

        $this->client->request('POST', $url, ['tenant_name' => [
            'name'   => 'Invasão Secreta',
            'cnpj'   => '00000000000191',
            '_token' => 'csrf-token',
        ]], [], ['HTTP_REFERER' => 'http://localhost' . $url]);

        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $recarregado = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertNotNull($recarregado);
        self::assertSame(self::NOME_ORIGINAL, $recarregado->getName());
        self::assertSame(self::CNPJ_ORIGINAL, $recarregado->getCnpj());

        $negados = $this->negados();
        self::assertCount(1, $negados);
        self::assertSame($this->secundario->getId(), $negados[0]->getActorUserId());
        self::assertSame('app_tenant_edit', $negados[0]->getRoute());
        self::assertStringNotContainsString('Invasão Secreta', json_encode($negados[0]->getChanges(), \JSON_UNESCAPED_UNICODE) ?: '');
    }

    #[TestDox('O secundário vê a aba Dados somente leitura (campos desabilitados, sem salvar), com cargos e lotações')]
    public function testSecundarioVeOsDadosSomenteLeitura(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlEditar());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#tab-dados-btn'));
        $dados = $crawler->filter('#tab-dados form[name=tenant_name]');
        self::assertCount(1, $dados);
        $campos = $dados->filter('input:not([type=hidden]), select, textarea');
        self::assertGreaterThan(0, $campos->count());
        self::assertSame($campos->count(), $dados->filter('input[disabled]:not([type=hidden]), select[disabled], textarea[disabled]')->count(), 'todo campo dos dados desabilitado');
        self::assertCount(0, $dados->filter('button'), 'sem botão de salvar');
        self::assertSame($this->tenant->getName(), $dados->filter('input[name="tenant_name[name]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('form[name=tenant_cargos]'));
        self::assertCount(1, $crawler->filter('form[name=tenant_lotacoes]'));
        self::assertSame([], $this->negados(), 'abrir a página não é tentativa');
    }

    #[TestDox('O mesmo secundário segue salvando cargos e lotações')]
    public function testSecundarioSalvaCargosELotacoes(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $url = $this->urlEditar();

        $crawler = $this->client->request('GET', $url);
        $valores = $crawler->filter('form[name=tenant_cargos]')->form()->getPhpValues();
        $valores['tenant_cargos']['cargos'] = [['nome' => 'Estagiário']];
        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Cargos atualizados com sucesso!');

        $crawler = $this->client->request('GET', $url);
        $valores = $crawler->filter('form[name=tenant_lotacoes]')->form()->getPhpValues();
        $valores['tenant_lotacoes']['lotacoes'] = [['nome' => 'Contencioso']];
        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);
        self::assertResponseRedirects();

        self::assertNotNull($this->em->getRepository(Cargo::class)->findOneBy(['nome' => 'Estagiário', 'tenant' => $this->tenant->getId()]));
        self::assertNotNull($this->em->getRepository(Lotacao::class)->findOneBy(['nome' => 'Contencioso', 'tenant' => $this->tenant->getId()]));
        self::assertSame([], $this->negados());
    }

    #[TestDox('O primário segue alterando os dados na edição legada')]
    public function testPrimarioAlteraOsDados(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $url     = $this->urlEditar();
        $crawler = $this->client->request('GET', $url);

        $valores = $crawler->filter('form[name=tenant_name]')->form()->getPhpValues();
        $valores['tenant_name']['name'] = 'Escritório Renomeado';
        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('Escritório Renomeado', $this->em->find(Tenant::class, $this->tenant->getId())?->getName());
    }

    // ── apoio ──────────────────────────────────────────────────────────────

    private function urlEditar(): string
    {
        return sprintf('/tenant/%d/edit', $this->tenant->getId());
    }

    /** @return list<AuditLog> */
    private function negados(): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy([
            'action'   => RegistroDeAcessoNegadoAoEscritorio::ACAO,
            'tenantId' => $this->tenant->getId(),
        ]);
    }

    private function darPermissao(TenantRole $perfil, string $codigo): void
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setGroup(explode('.', $codigo)[0]);
            $permissao->setDescription($codigo);
            $this->em->persist($permissao);
        }

        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($perfil);
        $vinculo->setPermission($permissao);
        $perfil->getTenantRolePermissions()->add($vinculo);
        $this->em->persist($vinculo);
        $this->em->flush();
    }

    private function criarPerfil(string $nome, bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName($nome);
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        return $perfil;
    }

    private function criarUsuario(string $nome, TenantRole $perfil): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(uniqid('dados_so_primario_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em->persist($user);

        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);

        return $user;
    }
}
