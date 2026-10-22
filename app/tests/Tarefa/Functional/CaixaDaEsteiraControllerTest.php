<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tarefa\Controller\CaixaDaEsteiraController;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\DesligaTenantFilterNaRequisicao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/** "Salvar" e "Restaurar padrão" do modal "Configurar caixa" da esteira (desenho 04 de 22/10). */
#[CoversClass(CaixaDaEsteiraController::class)]
#[Group('tarefa')]
final class CaixaDaEsteiraControllerTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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
    }

    #[TestDox('Primário salva a caixa: grava, volta para Minhas Metas com "Caixa salva."')]
    public function testPrimarioSalva(): void
    {
        $tenant   = $this->tenant();
        $primario = $this->usuario($tenant, true);
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->postar('protocolo', ['nome_da_caixa' => 'Fila do protocolo', 'nome_da_etapa' => '']);

        self::assertResponseRedirects('/tarefas/minhas');
        self::assertSame(['Caixa salva.'], $this->flash('success'));
        $caixa = $this->caixaGravada($tenant, EtapaDaPasta::Protocolo);
        self::assertSame('Fila do protocolo', $caixa?->getNomeDaCaixa());
        self::assertNull($caixa?->getNomeDaEtapa());
    }

    #[TestDox('Desenho 04 (dc L132-133): a janela "Configurar caixa" é a do desenho, sem o modal do Bootstrap, e o "Caixa salva." sai no aviso de baixo da página, sob .mm-body')]
    public function testJanelaEAvisoDoDesenho(): void
    {
        $tenant   = $this->tenant();
        $primario = $this->usuario($tenant, true);
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->postar('protocolo', ['nome_da_caixa' => 'Fila do protocolo', 'nome_da_etapa' => '']);
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('mm-body', (string) $crawler->filter('body')->attr('class'));
        self::assertSame('Caixa salva.', trim($crawler->filter('.app-content-header .alert.alert-success')->text()));
        $janela = $crawler->filter('#mm-cx-modal');
        self::assertCount(1, $janela);
        self::assertNotNull($janela->attr('hidden'), 'fechada até o clique');
        self::assertStringNotContainsString('modal fade', (string) $janela->attr('class'));
        self::assertCount(1, $crawler->filter('#mm-cx-modal > .mm-cx-fundo[data-mm-cx-fechar] + .mm-cx-centro > form.mm-cx-caixa[role="dialog"][data-mm-cx-form]'));
        self::assertCount(1, $crawler->filter('form.mm-cx-caixa > .mm-cx-cabecalho + .mm-cx-corpo + .mm-cx-rodape'));
        self::assertCount(0, $janela->filter('.modal-dialog, .modal-content, [data-bs-dismiss]'));
        self::assertCount(2, $janela->filter('.mm-cx-cabecalho > .mm-cx-fechar[data-mm-cx-fechar], .mm-cx-rodape > .mm-cx-cancelar[data-mm-cx-fechar]'));
    }

    #[TestDox('Com avisado da equipe, o aviso diz quantas pessoas serão avisadas')]
    public function testSalvaComAvisado(): void
    {
        $tenant   = $this->tenant();
        $primario = $this->usuario($tenant, true);
        $colega   = $this->usuario($tenant, false);
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->postar('juridico', ['nome_da_caixa' => '', 'nome_da_etapa' => '', 'avisados' => [(string) $colega->getId()]]);

        self::assertSame(['Caixa salva. 1 pessoa(s) serão avisadas quando chegar demanda.'], $this->flash('success'));
        $avisados = $this->caixaGravada($tenant, EtapaDaPasta::Juridico)?->getAvisados()->toArray() ?? [];
        self::assertSame([$colega->getId()], array_map(static fn (User $u): ?int => $u->getId(), $avisados));
    }

    #[TestDox('Avisado de outro escritório: flash de erro e nada gravado')]
    public function testAvisadoDeOutroEscritorioRecusado(): void
    {
        $tenant   = $this->tenant();
        $primario = $this->usuario($tenant, true);
        $deFora   = $this->usuario($this->tenant(), true);
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->postar('juridico', ['nome_da_caixa' => 'X', 'nome_da_etapa' => '', 'avisados' => [(string) $deFora->getId()]]);

        self::assertResponseRedirects('/tarefas/minhas');
        self::assertSame(['Só pessoas da equipe ativa do escritório podem ser avisadas.'], $this->flash('danger'));
        self::assertNull($this->caixaGravada($tenant, EtapaDaPasta::Juridico));
    }

    #[TestDox('Secundário sem administrar perfis: flash de erro e nada gravado')]
    public function testSecundarioNaoGrava(): void
    {
        $tenant     = $this->tenant();
        $secundario = $this->usuario($tenant, false);
        $this->logarComTenant($this->client, $secundario, $tenant);

        $this->postar('protocolo', ['nome_da_caixa' => 'Tentativa', 'nome_da_etapa' => '']);

        self::assertResponseRedirects('/tarefas/minhas');
        self::assertSame(
            ['Só o usuário primário e quem administra perfis configuram as caixas da esteira.'],
            $this->flash('danger'),
        );
        self::assertSame([], $this->flash('success'));
        self::assertNull($this->caixaGravada($tenant, EtapaDaPasta::Protocolo));
    }

    #[TestDox('Secundário com admin.roles.manage salva')]
    public function testSecundarioQueAdministraPerfisSalva(): void
    {
        $tenant = $this->tenant();
        $admin  = $this->usuario($tenant, false, ['admin.roles.manage']);
        $this->logarComTenant($this->client, $admin, $tenant);

        $this->postar('abertura', ['nome_da_caixa' => 'Entrada', 'nome_da_etapa' => '']);

        self::assertSame(['Caixa salva.'], $this->flash('success'));
        self::assertSame('Entrada', $this->caixaGravada($tenant, EtapaDaPasta::Abertura)?->getNomeDaCaixa());
    }

    #[TestDox('Token CSRF inválido: 403 e nada gravado')]
    public function testTokenInvalido(): void
    {
        $tenant   = $this->tenant();
        $primario = $this->usuario($tenant, true);
        $this->logarComTenant($this->client, $primario, $tenant);

        $this->client->request('POST', '/tarefas/minhas/caixas/protocolo', [
            '_token' => 'TOKEN_caixa_esteira_juridico',
            'nome_da_caixa' => 'X',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->caixaGravada($tenant, EtapaDaPasta::Protocolo));
    }

    #[TestDox('Restaurar padrão apaga a caixa do escritório e não toca na de outro, mesmo sem o TenantFilter')]
    public function testRestaurarRemove(): void
    {
        $tenant   = $this->tenant();
        $outro    = $this->tenant();
        $primario = $this->usuario($tenant, true);
        $this->gravarCaixa($tenant, EtapaDaPasta::Protocolo);
        $this->gravarCaixa($outro, EtapaDaPasta::Protocolo);
        $this->logarComTenant($this->client, $primario, $tenant);
        $this->desligarTenantFilterNaProximaRequisicao();

        $this->client->request('POST', '/tarefas/minhas/caixas/protocolo/restaurar', ['_token' => 'TOKEN_caixa_esteira_protocolo']);

        self::assertResponseRedirects('/tarefas/minhas');
        self::assertTrue($this->tenantFilterFoiDesligado);
        self::assertNull($this->caixaGravada($tenant, EtapaDaPasta::Protocolo));
        self::assertNotNull($this->caixaGravada($outro, EtapaDaPasta::Protocolo));
    }

    #[TestDox('Restaurar pelo secundário: flash de erro e a caixa fica')]
    public function testRestaurarSemPermissao(): void
    {
        $tenant     = $this->tenant();
        $secundario = $this->usuario($tenant, false);
        $this->gravarCaixa($tenant, EtapaDaPasta::Protocolo);
        $this->logarComTenant($this->client, $secundario, $tenant);

        $this->client->request('POST', '/tarefas/minhas/caixas/protocolo/restaurar', ['_token' => 'TOKEN_caixa_esteira_protocolo']);

        self::assertResponseRedirects('/tarefas/minhas');
        self::assertCount(1, $this->flash('danger'));
        self::assertNotNull($this->caixaGravada($tenant, EtapaDaPasta::Protocolo));
    }

    /** @param array<string, mixed> $campos */
    private function postar(string $etapa, array $campos): void
    {
        $this->client->request('POST', '/tarefas/minhas/caixas/' . $etapa, $campos + ['_token' => 'TOKEN_caixa_esteira_' . $etapa]);
    }

    /** @return list<string> */
    private function flash(string $tipo): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($tipo);
    }

    private function caixaGravada(Tenant $tenant, EtapaDaPasta $etapa): ?CaixaDaEsteira
    {
        // Lê o banco de verdade: depois da requisição o TenantFilter fica ligado no tenant da sessão.
        $em = $this->em();
        $em->clear();
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }

        return $em->getRepository(CaixaDaEsteira::class)->findOneBy(['tenant' => $tenant->getId(), 'etapa' => $etapa]);
    }

    private function gravarCaixa(Tenant $tenant, EtapaDaPasta $etapa): void
    {
        $caixa = new CaixaDaEsteira($tenant, $etapa);
        $caixa->configurar('Configurada', null, []);
        $this->em()->persist($caixa);
        $this->em()->flush();
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Esteira ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /**
     * Primário = perfil de sistema. Secundário = perfil comum com o módulo Tarefas e, se pedido, outras permissões.
     *
     * @param list<string> $extras
     */
    private function usuario(Tenant $tenant, bool $primario, array $extras = []): User
    {
        $em = $this->em();
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName(($primario ? 'Administrador ' : 'Secundário ') . uniqid());
        $role->setIsSystem($primario);
        $em->persist($role);
        if (!$primario) {
            $this->conceder($role, ['modules.tarefas.view', ...$extras]);
        }

        $user = new User();
        $user->setEmail('esteira_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    /** @param list<string> $codigos */
    private function conceder(TenantRole $role, array $codigos): void
    {
        $em = $this->em();
        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
