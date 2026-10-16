<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantRoleController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Perfis 1.0 (spec docs/specs/perfis-master-1.0.md §4.1, §4.2, D2, D4, D5, D7).
 *
 * Cenário: escritório com o primário TITULAR (Tenant.criadoPor), um primário SÓCIO, um
 * secundário "Advogado" e um perfil secundário sem descrição.
 */
#[CoversClass(TenantRoleController::class)]
final class PerfisDoEscritorioControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private TenantRole $perfilPrimario;
    private TenantRole $perfilAdvogado;
    private User $titular;
    private User $socio;
    private User $advogado;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório Perfis ' . uniqid());
        $this->em->persist($this->tenant);

        $this->perfilPrimario = $this->criarPerfil($this->tenant, 'Administrador do Escritório', true);
        $this->perfilAdvogado = $this->criarPerfil($this->tenant, 'Advogado (a)', false);

        $this->titular  = $this->criarUsuario('Titular Primário', $this->tenant, $this->perfilPrimario);
        $this->socio    = $this->criarUsuario('Sócio Primário', $this->tenant, $this->perfilPrimario);
        $this->advogado = $this->criarUsuario('Advogada Secundária', $this->tenant, $this->perfilAdvogado);
        $this->tenant->setCriadoPor($this->titular);
        $this->em->flush();
    }

    // ── §4.1 Lista ─────────────────────────────────────────────────────────

    #[TestDox('lista do primário: sem linha Master, sem link para o Painel do Master, sem "Ver como"')]
    public function testListaDoPrimarioNaoMostraMaster(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Master do Sistema', $html);
        self::assertStringNotContainsString('href="/master"', $html);
        self::assertStringNotContainsString('data-href="/master"', $html);
        self::assertCount(0, $crawler->filter('[data-pf-ver-como]'));
        self::assertCount(0, $crawler->filter('.pf-selo-master'));
    }

    #[TestDox('lista do Master: linha "Master do Sistema" com selo MASTER, Total, nº de Masters e lápis para o Painel')]
    public function testListaDoMasterMostraLinhaMaster(): void
    {
        $master = $this->criarUsuario('Master Teste', $this->tenant, $this->perfilAdvogado, ['ROLE_SUPER_ADMIN']);
        $this->em->flush();
        $totalMasters = $this->contarMasters();

        $this->logarComTenant($this->client, $master, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.pf-tabela-miolo > .pf-linha[data-pf-linha-master]');
        self::assertCount(1, $linha, 'a linha Master é filha direta da tabela');
        self::assertSame('/master', $linha->attr('data-href'));
        self::assertSame('Master do Sistema', trim($linha->filter('.pf-col-nome')->text()));
        self::assertStringContainsString('MASTER', $linha->filter('.pf-col-classe > .pf-selo-master')->text());
        self::assertSame('Total', trim($linha->filter('.pf-col-num')->eq(0)->text()));
        self::assertSame((string) $totalMasters, trim($linha->filter('.pf-col-num')->eq(1)->text()));
        self::assertCount(1, $linha->filter('.pf-col-acoes > a[href="/master"]'));
        self::assertCount(1, $crawler->filter('.pf-cabecalho > [data-pf-ver-como] > [data-pf-visao="primario"]'));
    }

    #[TestDox('lista: primário com selo PRIMÁRIO, descrição do desenho e total do catálogo; secundário sem descrição vira "Usuário secundário"')]
    public function testLinhasPrimarioESecundario(): void
    {
        $semDescricao = $this->criarPerfil($this->tenant, 'Nível I', false);
        $this->em->flush();

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());
        self::assertResponseIsSuccessful();

        $linhas = $crawler->filter('.pf-tabela-miolo > .pf-linha');
        self::assertCount(3, $linhas);

        $primario = $linhas->eq(0);
        self::assertSame('Administrador do Escritório', trim($primario->filter('.pf-col-nome')->text()));
        self::assertCount(1, $primario->filter('.pf-col-classe > .pf-selo-primario'));
        self::assertStringContainsString('Quem abriu a conta.', $primario->filter('.pf-col-desc')->text());
        $totalCatalogo = count($this->em->getRepository(Permission::class)->findAll());
        self::assertSame((string) $totalCatalogo, trim($primario->filter('.pf-col-num')->eq(0)->text()));
        self::assertSame('2', trim($primario->filter('.pf-col-num')->eq(1)->text()), 'titular + sócio');
        self::assertCount(0, $primario->filter('[data-pf-excluir]'), 'o primário não tem lixeira');

        $nivel = $crawler->filter(sprintf('.pf-linha[data-href$="/roles/%d/edit"]', $semDescricao->getId()));
        self::assertSame('Usuário secundário', trim($nivel->filter('.pf-col-desc')->text()));
        self::assertCount(1, $nivel->filter('.pf-col-classe > .pf-selo-secundario'));
        self::assertCount(1, $nivel->filter('.pf-col-acoes [data-pf-excluir]'));
    }

    #[TestDox('secundário sem admin.roles.manage recebe 403 na lista e na promoção')]
    public function testSecundarioSemPermissao403(): void
    {
        $this->logarComTenant($this->client, $this->advogado, $this->tenant);

        $this->client->request('GET', $this->urlLista());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->urlLista() . '/primarios/promover', [
            'usuario' => $this->advogado->getId(),
            '_token'  => 'qualquer',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->recarregarVinculo($this->advogado)->getTenantRole()?->isSystem());
    }

    #[TestDox('secundário COM admin.roles.manage vê a lista mas não promove ninguém a primário (nem a si)')]
    public function testSecundarioComGestaoDePerfisNaoPromove(): void
    {
        $this->darPermissao($this->perfilAdvogado, 'admin.roles.manage');
        $this->logarComTenant($this->client, $this->advogado, $this->tenant);

        $this->client->request('GET', $this->urlLista());
        self::assertResponseIsSuccessful();

        // Token legítimo, tirado da própria tela: a recusa tem de vir da autorização, não do CSRF.
        $crawler = $this->client->request('GET', $this->urlEdicao($this->perfilPrimario));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('[data-pf-form-promover] input[name=_token]')->attr('value');

        $this->client->request('POST', $this->urlLista() . '/primarios/promover', [
            'usuario' => $this->advogado->getId(),
            '_token'  => $token,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->recarregarVinculo($this->advogado)->getTenantRole()?->isSystem());
    }

    // ── §4.2 Edição ────────────────────────────────────────────────────────

    #[TestDox('edição do primário: titular com selo TITULAR e cadeado, sócio com lixeira, caixas marcadas e travadas, sem campo de nome')]
    public function testEdicaoDoPrimario(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlEdicao($this->perfilPrimario));
        self::assertResponseIsSuccessful();

        $primarios = $crawler->filter('[data-pf-primarios] > [data-pf-primario]');
        self::assertCount(2, $primarios);
        self::assertStringContainsString('Titular Primário', $primarios->eq(0)->text(), 'o titular abre o bloco');
        self::assertCount(1, $primarios->eq(0)->filter('.pf-tag-titular'));
        self::assertCount(1, $primarios->eq(0)->filter('.bi-lock-fill'));
        self::assertCount(0, $primarios->eq(0)->filter('[data-pf-remover-primario]'));
        self::assertCount(1, $primarios->eq(1)->filter('.pf-tag-socio'));
        self::assertCount(1, $primarios->eq(1)->filter('[data-pf-remover-primario]'));

        $opcoes = $crawler->filter('[data-pf-promover-select] > option[value!=""]');
        self::assertCount(1, $opcoes, 'só o secundário ativo é promovível');
        self::assertSame((string) $this->advogado->getId(), $opcoes->attr('value'));

        $caixas = $crawler->filter('.pf-cartoes > .pf-cartao input[type=checkbox]');
        self::assertGreaterThan(0, $caixas->count());
        self::assertSame($caixas->count(), $crawler->filter('.pf-cartoes input[type=checkbox][checked][disabled]')->count());
        self::assertCount(0, $crawler->filter('input[name="tenant_role[name]"]'));
        self::assertCount(0, $crawler->filter('.pf-marcar-todos'));
    }

    #[TestDox('POST na edição do primário não apaga as permissões gravadas (as caixas travadas não são enviadas)')]
    public function testPostNoPrimarioNaoApagaPermissoes(): void
    {
        $this->darPermissao($this->perfilPrimario, 'admin.roles.manage');
        $antes = $this->perfilPrimario->getTenantRolePermissions()->count();
        self::assertGreaterThan(0, $antes);

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->client->request('POST', $this->urlEdicao($this->perfilPrimario), ['tenant_role' => ['name' => 'Outro nome']]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $perfil = $this->em->find(TenantRole::class, $this->perfilPrimario->getId());
        self::assertSame('Administrador do Escritório', $perfil?->getName());
        self::assertSame($antes, $perfil?->getTenantRolePermissions()->count());
    }

    #[TestDox('edição do secundário: NOME/DESCRIÇÃO, 6 cartões em grade, rodapé "N de M permissões ativas"; salvar mantém o syncPermissions')]
    public function testEdicaoDoSecundarioSalva(): void
    {
        $this->garantirPermissao('modules.pastas.view');
        $this->em->flush();
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlEdicao($this->perfilAdvogado));
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('.pf-card-campos > .pf-campo > input[name="tenant_role[name]"]'));
        self::assertCount(1, $crawler->filter('.pf-card-campos > .pf-campo > input[name="tenant_role[description]"]'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('.pf-cartoes > .pf-cartao > .pf-cartao-cab > .pf-marcar-todos')->count());
        self::assertMatchesRegularExpression('/^0 de \d+ permissões ativas$/', trim($crawler->filter('.pf-rodape > [data-pf-resumo]')->text()));

        $form = $crawler->filter('form[name=tenant_role]')->form();
        $form['tenant_role[description]'] = 'Advocacia contenciosa';
        foreach ($form['tenant_role[permissions]'] as $caixa) {
            if (in_array('modules.pastas.view', $caixa->availableOptionValues(), true)) {
                $caixa->tick();
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects($this->urlLista());

        $this->em->clear();
        $perfil = $this->em->find(TenantRole::class, $this->perfilAdvogado->getId());
        $codigos = array_map(static fn (TenantRolePermission $t): ?string => $t->getPermission()?->getCode(), $perfil?->getTenantRolePermissions()->toArray() ?? []);
        self::assertSame(['modules.pastas.view'], array_values($codigos));
        self::assertSame('Advocacia contenciosa', $perfil?->getDescription());
    }

    #[TestDox('novo perfil com nome repetido é recusado com a mensagem do desenho')]
    public function testNovoPerfilNomeRepetido(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista() . '/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name=tenant_role]')->form();
        $form['tenant_role[name]'] = 'administrador do escritório';
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Já existe um perfil com esse nome.', trim($crawler->filter('.pf-rodape > [data-pf-resumo-erro]')->text()));
    }

    // ── D4 Sócios via HTTP ─────────────────────────────────────────────────

    #[TestDox('o primário promove um secundário ativo a sócio (com CSRF) e a troca vai para a auditoria')]
    public function testPromoverViaHttp(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/primarios/promover', [
            'usuario' => $this->advogado->getId(),
            '_token'  => $this->tokenCsrf('promover_primario_' . $this->tenant->getId()),
        ]);

        self::assertResponseRedirects($this->urlEdicao($this->perfilPrimario));
        $vinculo = $this->recarregarVinculo($this->advogado);
        self::assertTrue($vinculo->getTenantRole()?->isSystem());

        $auditoria = $this->em->getRepository(AuditLog::class)->findBy([
            'entityClass' => UserTenant::class,
            'entityId'    => (string) $vinculo->getId(),
            'action'      => 'update',
        ]);
        self::assertNotEmpty($auditoria, 'a promoção precisa deixar rastro no audit_log');
        self::assertSame($this->tenant->getId(), $auditoria[0]->getTenantId());
        self::assertSame($this->titular->getId(), $auditoria[0]->getActorUserId());
    }

    #[TestDox('promover sem CSRF válido: 403 e nada muda')]
    public function testPromoverSemCsrf(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/primarios/promover', [
            'usuario' => $this->advogado->getId(),
            '_token'  => 'invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->recarregarVinculo($this->advogado)->getTenantRole()?->isSystem());
    }

    #[TestDox('promover usuário de OUTRO escritório não atravessa: nada muda lá')]
    public function testPromoverDeOutroEscritorio(): void
    {
        $outro = new Tenant();
        $outro->setName('Outro escritório ' . uniqid());
        $this->em->persist($outro);
        $perfilOutro = $this->criarPerfil($outro, 'Estagiário', false);
        $estranho = $this->criarUsuario('Estranho', $outro, $perfilOutro);
        $this->em->flush();

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/primarios/promover', [
            'usuario' => $estranho->getId(),
            '_token'  => $this->tokenCsrf('promover_primario_' . $this->tenant->getId()),
        ]);

        self::assertResponseRedirects($this->urlEdicao($this->perfilPrimario));
        $this->em->clear();
        $vinculos = $this->em->getRepository(UserTenant::class)->findBy(['user' => $estranho->getId()]);
        self::assertCount(1, $vinculos, 'nenhum vínculo novo criado');
        self::assertSame($perfilOutro->getId(), $vinculos[0]->getTenantRole()?->getId());
    }

    #[TestDox('o primário remove o sócio para o perfil secundário escolhido')]
    public function testRemoverSocioViaHttp(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/primarios/' . $this->socio->getId() . '/remover', [
            'perfil_destino' => $this->perfilAdvogado->getId(),
            '_token'         => $this->tokenCsrf('remover_primario_' . $this->socio->getId()),
        ]);

        self::assertResponseRedirects($this->urlEdicao($this->perfilPrimario));
        self::assertSame($this->perfilAdvogado->getId(), $this->recarregarVinculo($this->socio)->getTenantRole()?->getId());
    }

    #[TestDox('o titular nunca é removido, nem pelo sócio, nem com CSRF válido')]
    public function testTitularNaoERemovidoViaHttp(): void
    {
        $this->logarComTenant($this->client, $this->socio, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/primarios/' . $this->titular->getId() . '/remover', [
            'perfil_destino' => $this->perfilAdvogado->getId(),
            '_token'         => $this->tokenCsrf('remover_primario_' . $this->titular->getId()),
        ]);

        self::assertResponseRedirects($this->urlEdicao($this->perfilPrimario));
        self::assertTrue($this->recarregarVinculo($this->titular)->getTenantRole()?->isSystem());
        $this->client->followRedirect();
        self::assertStringContainsString('titular', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('remover com perfil de destino de outro escritório é recusado')]
    public function testRemoverComDestinoDeOutroEscritorio(): void
    {
        $outro = new Tenant();
        $outro->setName('Outro escritório ' . uniqid());
        $this->em->persist($outro);
        $perfilOutro = $this->criarPerfil($outro, 'Estagiário', false);
        $this->em->flush();

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/primarios/' . $this->socio->getId() . '/remover', [
            'perfil_destino' => $perfilOutro->getId(),
            '_token'         => $this->tokenCsrf('remover_primario_' . $this->socio->getId()),
        ]);

        self::assertResponseRedirects($this->urlEdicao($this->perfilPrimario));
        self::assertTrue($this->recarregarVinculo($this->socio)->getTenantRole()?->isSystem());
    }

    // ── edit-role: a porta lateral ─────────────────────────────────────────

    #[TestDox('edit-role não rebaixa o titular (mesmo com outro primário no escritório)')]
    public function testEditRoleNaoRebaixaTitular(): void
    {
        $this->logarComTenant($this->client, $this->socio, $this->tenant);
        $this->submeterEditRole($this->titular, $this->perfilAdvogado);

        // O edit-role legado re-renderiza o form inválido com 200 (passa a view, não o form).
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('titular', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->recarregarVinculo($this->titular)->getTenantRole()?->isSystem());
    }

    #[TestDox('edit-role não rebaixa o último primário (escritório sem titular registrado)')]
    public function testEditRoleNaoRebaixaUltimoPrimario(): void
    {
        $this->tenant->setCriadoPor(null);
        $this->recarregarVinculo($this->socio)->setTenantRole($this->perfilAdvogado);
        $this->em->flush();

        $master = $this->criarUsuario('Master Suporte', $this->tenant, $this->perfilAdvogado, ['ROLE_SUPER_ADMIN']);
        $this->em->flush();

        $this->logarComTenant($this->client, $master, $this->tenant);
        $this->submeterEditRole($this->titular, $this->perfilAdvogado);

        // O edit-role legado re-renderiza o form inválido com 200 (passa a view, não o form).
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('último primário', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->recarregarVinculo($this->titular)->getTenantRole()?->isSystem());
    }

    #[TestDox('edit-role ainda rebaixa um sócio quando sobra primário')]
    public function testEditRoleRebaixaSocio(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->submeterEditRole($this->socio, $this->perfilAdvogado);

        self::assertResponseRedirects();
        self::assertSame($this->perfilAdvogado->getId(), $this->recarregarVinculo($this->socio)->getTenantRole()?->getId());
    }

    // ── B1/I1/I2: só primário ou Master gere primários ─────────────────────

    #[TestDox('edit-role: secundário com admin.users.manage não se autopromove (opção escondida e POST forjado recusado)')]
    public function testEditRoleSecundarioNaoSeAutopromove(): void
    {
        $this->darPermissao($this->perfilAdvogado, 'admin.users.manage');
        $this->logarComTenant($this->client, $this->advogado, $this->tenant);

        $crawler = $this->client->request('GET', $this->urlEditRole($this->advogado));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('select[name="edit_user_tenant_role[tenantRole]"] > option[value="%d"]', $this->perfilPrimario->getId())));

        $this->submeterEditRole($this->advogado, $this->perfilPrimario);

        self::assertFalse($this->recarregarVinculo($this->advogado)->getTenantRole()?->isSystem());
    }

    #[TestDox('edit-role: secundário com admin.users.manage não promove outro colaborador a primário')]
    public function testEditRoleSecundarioNaoPromoveOutro(): void
    {
        $this->darPermissao($this->perfilAdvogado, 'admin.users.manage');
        $estagiario = $this->criarUsuario('Estagiária', $this->tenant, $this->perfilAdvogado);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->advogado, $this->tenant);

        $this->submeterEditRole($estagiario, $this->perfilPrimario);

        self::assertFalse($this->recarregarVinculo($estagiario)->getTenantRole()?->isSystem());
    }

    #[TestDox('edit-role: secundário com admin.users.manage não rebaixa um sócio primário')]
    public function testEditRoleSecundarioNaoRebaixaSocio(): void
    {
        $this->darPermissao($this->perfilAdvogado, 'admin.users.manage');
        $this->logarComTenant($this->client, $this->advogado, $this->tenant);

        $this->submeterEditRole($this->socio, $this->perfilAdvogado);

        self::assertTrue($this->recarregarVinculo($this->socio)->getTenantRole()?->isSystem());
    }

    #[TestDox('edit-role: o primário promove um secundário a primário')]
    public function testEditRolePrimarioPromove(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);

        $this->submeterEditRole($this->advogado, $this->perfilPrimario);

        self::assertResponseRedirects();
        self::assertTrue($this->recarregarVinculo($this->advogado)->getTenantRole()?->isSystem());
    }

    #[TestDox('edit-role: secundário com admin.users.manage não troca a senha de um primário')]
    public function testSecundarioNaoTrocaSenhaDePrimario(): void
    {
        $this->darPermissao($this->perfilAdvogado, 'admin.users.manage');
        $this->logarComTenant($this->client, $this->advogado, $this->tenant);

        $this->submeterEditRole($this->socio, $this->perfilPrimario, 'SenhaNova#2026');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->senhaConfere($this->socio, 'SenhaNova#2026'));
        self::assertTrue($this->senhaConfere($this->socio, 'senha123'));
    }

    #[TestDox('edit-role: o sócio não troca a senha do titular; o titular troca a do sócio')]
    public function testSenhaDoTitularSoPeloTitular(): void
    {
        $this->logarComTenant($this->client, $this->socio, $this->tenant);
        $this->submeterEditRole($this->titular, $this->perfilPrimario, 'SenhaNova#2026');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->senhaConfere($this->titular, 'SenhaNova#2026'));

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->submeterEditRole($this->socio, $this->perfilPrimario, 'SenhaNova#2026');
        self::assertResponseRedirects();
        self::assertTrue($this->senhaConfere($this->socio, 'SenhaNova#2026'));
    }

    #[TestDox('convite: secundário com admin.users.invite não vê nem forja convite com o perfil primário; o primário convida')]
    public function testConviteComoPrimarioSoParaPrimario(): void
    {
        $this->darPermissao($this->perfilAdvogado, 'admin.users.invite');
        $this->logarComTenant($this->client, $this->advogado, $this->tenant);

        $crawler = $this->client->request('GET', '/escritorio/convites');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('select[name=tenant_role_id] > option[value="%d"]', $this->perfilPrimario->getId())));
        self::assertCount(1, $crawler->filter(sprintf('select[name=tenant_role_id] > option[value="%d"]', $this->perfilAdvogado->getId())));

        $emailForjado = uniqid('forjado_') . '@test.com';
        $this->client->request('POST', '/escritorio/convites/novo', [
            '_token'         => (string) $crawler->filter('form[action$="/escritorio/convites/novo"] input[name=_token]')->attr('value'),
            'email'          => $emailForjado,
            'tenant_role_id' => $this->perfilPrimario->getId(),
        ]);
        self::assertResponseRedirects('/escritorio/convites');
        self::assertNull($this->em->getRepository(Invitation::class)->findOneBy(['email' => $emailForjado]));

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', '/escritorio/convites');
        self::assertCount(1, $crawler->filter(sprintf('select[name=tenant_role_id] > option[value="%d"]', $this->perfilPrimario->getId())));
        $emailOk = uniqid('socio_') . '@test.com';
        $this->client->request('POST', '/escritorio/convites/novo', [
            '_token'         => (string) $crawler->filter('form[action$="/escritorio/convites/novo"] input[name=_token]')->attr('value'),
            'email'          => $emailOk,
            'tenant_role_id' => $this->perfilPrimario->getId(),
        ]);
        self::assertNotNull($this->em->getRepository(Invitation::class)->findOneBy(['email' => $emailOk]));
    }

    // ── excluir perfil em uso ──────────────────────────────────────────────

    #[TestDox('excluir perfil em uso deixa os usuários sem perfil, como o desenho promete (sem erro de integridade)')]
    public function testExcluirPerfilEmUso(): void
    {
        $idPerfil = (int) $this->perfilAdvogado->getId();
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/' . $idPerfil, [
            '_token' => $this->tokenCsrf('delete_role_' . $idPerfil),
        ]);

        self::assertResponseRedirects($this->urlLista());
        $this->em->clear();
        self::assertNull($this->em->find(TenantRole::class, $idPerfil));
        self::assertNull($this->recarregarVinculo($this->advogado)->getTenantRole());
    }

    // ── E: papel na barra superior ─────────────────────────────────────────

    #[TestDox('barra superior: "USUÁRIO PRIMÁRIO" para o primário, "MASTER · USUÁRIO SECUNDÁRIO" para o Master com vínculo secundário')]
    public function testPapelNaBarra(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());
        self::assertSame('USUÁRIO PRIMÁRIO', trim($crawler->filter('.navtop-user-info > .navtop-user-papel')->text()));

        $master = $this->criarUsuario('Master Barra', $this->tenant, $this->perfilAdvogado, ['ROLE_SUPER_ADMIN']);
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());
        self::assertSame('MASTER · USUÁRIO SECUNDÁRIO', trim($crawler->filter('.navtop-user-info > .navtop-user-papel')->text()));
    }

    // ── apoio ──────────────────────────────────────────────────────────────

    private function submeterEditRole(User $alvo, TenantRole $novoPerfil, ?string $novaSenha = null): void
    {
        $url     = $this->urlEditRole($alvo);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // O form_end do edit_user_role fica fora do <div> que abriu o form: o parser do crawler
        // fecha o <form> antes e o _token fica de fora de form()->getValues(). O valor vem da
        // própria página; o Referer satisfaz o CSRF stateless (mesma origem). O perfil entra
        // CRU no POST (não por select()): assim o teste também forja opção que a tela escondeu.
        $form    = $crawler->filter('form[name=edit_user_tenant_role]')->form();
        $valores = $form->getPhpValues();
        $valores['edit_user_tenant_role']['tenantRole'] = (string) $novoPerfil->getId();
        $valores['edit_user_tenant_role']['_token'] = (string) $crawler
            ->filter('input[name="edit_user_tenant_role[_token]"]')->attr('value');
        if ($novaSenha !== null) {
            $valores['edit_user_tenant_role']['newPassword'] = ['first' => $novaSenha, 'second' => $novaSenha];
        }

        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);
    }

    private function urlEditRole(User $alvo): string
    {
        return sprintf('/tenant/%d/user/%d/edit-role', $this->tenant->getId(), $alvo->getId());
    }

    private function senhaConfere(User $user, string $senha): bool
    {
        $this->em->clear();
        $recarregado = $this->em->find(User::class, $user->getId());
        self::assertNotNull($recarregado);

        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($recarregado, $senha);
    }

    private function urlLista(): string
    {
        return sprintf('/tenant/%d/roles', $this->tenant->getId());
    }

    private function urlEdicao(TenantRole $perfil): string
    {
        return sprintf('/tenant/%d/roles/%d/edit', $this->tenant->getId(), $perfil->getId());
    }

    /**
     * Token CSRF previsível (mesmo molde do PastaDuplicarControllerTest). O kernel do cliente
     * reinicia a cada requisição e perde o serviço trocado: chame logo antes do request.
     */
    private function tokenCsrf(string $id): string
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

        return 'TOKEN_' . $id;
    }

    private function contarMasters(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM "user" WHERE CAST(roles AS TEXT) LIKE :p',
            ['p' => '%"ROLE_SUPER_ADMIN"%']
        );
    }

    private function recarregarVinculo(User $user): UserTenant
    {
        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $this->tenant->getId()]);
        self::assertNotNull($vinculo);
        $this->em->refresh($vinculo);

        return $vinculo;
    }

    /** O banco de teste não garante o catálogo inteiro (ver TenantRoleFormRenderControllerTest). */
    private function garantirPermissao(string $codigo): Permission
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setGroup(explode('.', $codigo)[0]);
            $permissao->setDescription($codigo);
            $this->em->persist($permissao);
        }

        return $permissao;
    }

    private function darPermissao(TenantRole $perfil, string $codigo): void
    {
        $permissao = $this->garantirPermissao($codigo);

        $trp = new TenantRolePermission();
        $trp->setTenantRole($perfil);
        $trp->setPermission($permissao);
        $perfil->getTenantRolePermissions()->add($trp);
        $this->em->persist($trp);
        $this->em->flush();
    }

    private function criarPerfil(Tenant $tenant, string $nome, bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName($nome);
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        return $perfil;
    }

    /** @param list<string> $papeis */
    private function criarUsuario(string $nome, Tenant $tenant, TenantRole $perfil, array $papeis = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(uniqid('perfis_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);

        return $user;
    }
}
