<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Auth\Controller\GerenciarConvitesController;
use App\Auth\UseCase\CriarConviteEscritorioUseCase;
use App\Controller\TenantController;
use App\Controller\TenantRoleController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\HierarquiaDePerfis;
use App\Tenant\UseCase\PedirAcessoAosPerfisUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Hierarquia de perfis (spec perfis-1.2-permissoes-e-hierarquia C2 a C7; desenho 05 - Perfis 1.2, L496, L720-724 e
 * L822-824). Fecha a escalada: o secundário com admin.roles.manage fica só com a leitura da lista de secundários; o
 * edit-role e o convite de quem não é primário nem Master só atribuem perfil contido no dele.
 *
 * Cenário: o primário TITULAR; o "Gestor" (secundário com admin.roles.manage, admin.users.manage, admin.users.invite
 * e clientes); o perfil "Restrito" (só clientes) de uma colega e de quem não tem acesso aos Perfis; o perfil "Amplo"
 * (o do Gestor mais processos), acima do Gestor.
 */
#[CoversClass(TenantRoleController::class)]
#[CoversClass(TenantController::class)]
#[CoversClass(GerenciarConvitesController::class)]
#[CoversClass(CriarConviteEscritorioUseCase::class)]
#[CoversClass(HierarquiaDePerfis::class)]
#[CoversClass(PedirAcessoAosPerfisUseCase::class)]
final class HierarquiaDePerfisControllerTest extends JusPrimeWebTestCase
{
    private const GESTAO = ['admin.roles.manage', 'admin.users.manage', 'admin.users.invite', 'modules.clientes.view'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private TenantRole $perfilPrimario;
    private TenantRole $perfilGestor;
    private TenantRole $perfilRestrito;
    private TenantRole $perfilAmplo;
    private User $titular;
    private User $gestor;
    private User $colega;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório Hierarquia ' . uniqid());
        $this->em->persist($this->tenant);

        $this->perfilPrimario = $this->criarPerfil('Administrador do Escritório', true, []);
        $this->perfilGestor   = $this->criarPerfil('Gestor', false, self::GESTAO);
        $this->perfilRestrito = $this->criarPerfil('Restrito', false, ['modules.clientes.view']);
        $this->perfilAmplo    = $this->criarPerfil('Amplo', false, [...self::GESTAO, 'modules.processos.view']);

        $this->titular = $this->criarUsuario('Titular Primário', $this->perfilPrimario);
        $this->gestor  = $this->criarUsuario('Gestor Secundário', $this->perfilGestor);
        $this->colega  = $this->criarUsuario('Colega Secundária', $this->perfilRestrito);
        $this->tenant->setCriadoPor($this->titular);
        $this->em->flush();
    }

    // ── C2/C3: lista ───────────────────────────────────────────────────────

    #[TestDox('C2/C6: o secundário com admin.roles.manage vê só os secundários; lixeira oculta; lápis, linha e Novo Perfil com o aviso do desenho')]
    public function testListaDoSecundarioSoLeitura(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());

        self::assertResponseIsSuccessful();
        $linhas = $crawler->filter('.pf-tabela-miolo > .pf-linha');
        self::assertCount(3, $linhas, 'Gestor, Restrito e Amplo; o perfil de sistema não aparece');
        self::assertCount(0, $crawler->filter('.pf-selo-primario'));
        self::assertCount(0, $crawler->filter('.pf-tabela-miolo > .pf-linha[data-href]'), 'nenhuma linha abre a edição');
        self::assertCount(0, $crawler->filter('.pf-col-acoes a.pf-acao-editar'));
        self::assertCount(0, $crawler->filter('.pf-acao-excluir'), 'lixeira oculta (sc-if do desenho, L65)');
        self::assertCount(3, $crawler->filter('.pf-col-acoes > button.pf-acao-editar[data-pf-recusa]'));
        self::assertCount(0, $crawler->filter('.is-desabilitada, .is-desabilitado, [aria-disabled]'), 'sem cara de desabilitado');
        $mensagem = 'Seu perfil não pode editar um perfil do mesmo nível ou acima.';
        self::assertSame($mensagem, $crawler->filter('.pf-col-acoes > button.pf-acao-editar')->first()->attr('data-pf-recusa'));
        self::assertSame($mensagem, $linhas->first()->attr('data-pf-recusa'));
        self::assertCount(0, $crawler->filter(sprintf('a[href="%s/new"]', $this->urlLista())));
        $novo = $crawler->filter('.pf-cabecalho > button.pf-btn-cinza[data-pf-recusa]');
        self::assertCount(1, $novo, '"Novo Perfil" visível como no desenho (L50), com o aviso no clique');
        self::assertSame($mensagem, $novo->attr('data-pf-recusa'));
        self::assertStringContainsString('Novo Perfil', $novo->text());
        self::assertSame(['3', '2', '0', '3'], $this->numerosDosChips($crawler), 'o resumo conta só o que a lista mostra (Gestor e Colega)');
    }

    #[TestDox('C3: o primário vê todos os perfis e edita e exclui os secundários')]
    public function testListaDoPrimario(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.pf-tabela-miolo > .pf-linha[data-href]'));
        self::assertCount(3, $crawler->filter('[data-pf-excluir]'));
        self::assertCount(0, $crawler->filter('[data-pf-recusa]'));
        self::assertCount(1, $crawler->filter(sprintf('.pf-cabecalho > a[href="%s/new"]', $this->urlLista())));
    }

    // ── C3: servidor ───────────────────────────────────────────────────────

    #[TestDox('C3/C7: o secundário com admin.roles.manage recebe 403 ao editar o próprio perfil, o de uma colega e o do primário, e o POST forjado não muda nada')]
    public function testSecundarioNaoEdita(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        foreach ([$this->perfilGestor, $this->perfilRestrito, $this->perfilAmplo, $this->perfilPrimario] as $perfil) {
            $this->client->request('GET', $this->urlEdicao($perfil));
            self::assertResponseStatusCodeSame(403, (string) $perfil->getName());
        }

        // Auto-escalada: marcar no próprio perfil uma permissão que ele não tem.
        $this->client->request('POST', $this->urlEdicao($this->perfilGestor), [
            'tenant_role' => ['name' => 'Gestor', 'permissions' => [...self::GESTAO, 'modules.processos.view']],
        ]);
        self::assertResponseStatusCodeSame(403);
        $gestao = self::GESTAO;
        sort($gestao);
        self::assertSame($gestao, $this->codigosDe($this->perfilGestor));
    }

    #[TestDox('C3/C7: o secundário com admin.roles.manage recebe 403 ao criar perfil (GET e POST) e nada é gravado')]
    public function testSecundarioNaoCria(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $this->client->request('GET', $this->urlLista() . '/new');
        self::assertResponseStatusCodeSame(403);

        $nome = 'Forjado ' . uniqid();
        $this->client->request('POST', $this->urlLista() . '/new', [
            'tenant_role' => ['name' => $nome, 'permissions' => ['admin.roles.manage']],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(TenantRole::class)->findOneBy(['name' => $nome]));
    }

    #[TestDox('C3/C7: o secundário com admin.roles.manage recebe 403 ao excluir o perfil de uma colega e o próprio, mesmo com CSRF válido')]
    public function testSecundarioNaoExclui(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        // Token válido (primeira requisição do cliente): a recusa tem de vir da hierarquia, não do CSRF.
        $this->client->request('POST', $this->urlLista() . '/' . $this->perfilRestrito->getId(), [
            '_token' => $this->tokenCsrf('delete_role_' . $this->perfilRestrito->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);

        // O próprio perfil: a hierarquia recusa antes de olhar o token.
        $this->client->request('POST', $this->urlLista() . '/' . $this->perfilGestor->getId(), ['_token' => 'qualquer']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNotNull($this->em->find(TenantRole::class, $this->perfilRestrito->getId()));
        self::assertNotNull($this->em->find(TenantRole::class, $this->perfilGestor->getId()));
        self::assertSame($this->perfilRestrito->getId(), $this->recarregarVinculo($this->colega)->getTenantRole()?->getId());
    }

    #[TestDox('C3/C7: o primário edita um perfil secundário')]
    public function testPrimarioEditaSecundario(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlEdicao($this->perfilRestrito));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name=tenant_role]')->form();
        $form['tenant_role[name]'] = 'Restrito Renomeado';
        $this->client->submit($form);

        self::assertResponseRedirects($this->urlLista());
        $this->em->clear();
        self::assertSame('Restrito Renomeado', $this->em->find(TenantRole::class, $this->perfilRestrito->getId())?->getName());
    }

    #[TestDox('C3/C7: o Master (com vínculo secundário) vê tudo, abre o primário, edita e exclui secundários')]
    public function testMasterMexeEmTudo(): void
    {
        $master = $this->criarUsuario('Master Hierarquia', $this->perfilRestrito, ['ROLE_SUPER_ADMIN']);
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $this->tenant);

        $crawler = $this->client->request('GET', $this->urlLista());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-pf-recusa]'));

        $this->client->request('GET', $this->urlEdicao($this->perfilPrimario));
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', $this->urlEdicao($this->perfilAmplo));
        self::assertResponseIsSuccessful();

        // O próprio perfil do Master (secundário) também é dele: a regra do desenho é "o Master mexe em todos".
        $crawler = $this->client->request('GET', $this->urlLista());
        $token   = (string) $crawler->filter(sprintf('form[action$="/roles/%d"] input[name=_token]', $this->perfilAmplo->getId()))->attr('value');
        $this->client->request('POST', $this->urlLista() . '/' . $this->perfilAmplo->getId(), ['_token' => $token]);
        self::assertResponseRedirects($this->urlLista());
        $this->em->clear();
        self::assertNull($this->em->find(TenantRole::class, $this->perfilAmplo->getId()));
    }

    // ── C2: Acesso restrito e Pedir acesso ─────────────────────────────────

    #[TestDox('C2: quem não tem acesso vê "Acesso restrito" (403) com o texto do desenho, Voltar e Pedir acesso, na lista, no novo e na edição')]
    public function testAcessoRestrito(): void
    {
        $this->logarComTenant($this->client, $this->colega, $this->tenant);

        foreach ([$this->urlLista(), $this->urlLista() . '/new', $this->urlEdicao($this->perfilRestrito)] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
            $cartao = $crawler->filter('.pf-page > .pf-restrito > .pf-restrito-cartao');
            self::assertCount(1, $cartao, $url);
            self::assertCount(1, $cartao->filter('.pf-restrito-icone > i.bi-shield-lock'));
            self::assertSame('Acesso restrito', trim($cartao->filter('.pf-restrito-titulo')->text()));
            self::assertSame(
                'O menu Perfis é exclusivo do usuário primário do escritório e do Master. Para usar, peça a permissão ao primário.',
                trim($cartao->filter('.pf-restrito-texto')->text())
            );
            self::assertSame('Voltar', trim($cartao->filter('.pf-restrito-acoes > a.pf-restrito-voltar')->text()));
            self::assertSame('Pedir acesso', trim($cartao->filter('.pf-restrito-acoes > form > button.pf-restrito-pedir')->text()));
            self::assertCount(0, $cartao->filter('[data-pf-pedido-enviado]'));
        }
    }

    #[TestDox('C2: "Pedir acesso" avisa os primários ativos pela Central, uma vez por dia; depois a tela mostra "Pedido enviado ao primário"')]
    public function testPedirAcesso(): void
    {
        $socio = $this->criarUsuario('Sócio Primário', $this->perfilPrimario);
        $desligado = $this->criarUsuario('Primário Desligado', $this->perfilPrimario);
        $desligado->setIsActive(false);
        $this->em->flush();

        // Primário de OUTRO escritório: não recebe o aviso.
        $outro = new Tenant();
        $outro->setName('Outro Escritório ' . uniqid());
        $this->em->persist($outro);
        $perfilDoOutro = new TenantRole();
        $perfilDoOutro->setTenant($outro);
        $perfilDoOutro->setName('Administrador do Escritório');
        $perfilDoOutro->setIsSystem(true);
        $this->em->persist($perfilDoOutro);
        $primarioDeFora = $this->criarUsuario('Primário de Fora', $perfilDoOutro, ['ROLE_USER'], $outro);
        $this->em->flush();

        $this->logarComTenant($this->client, $this->colega, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlLista());
        $form    = $crawler->filter('form[data-pf-form-pedir-acesso]')->form();
        $token   = (string) $form->get('_token')->getValue();
        $this->client->submit($form);
        self::assertResponseRedirects($this->urlLista());

        $avisos = $this->avisosDoPedido();
        self::assertCount(2, $avisos, 'titular e sócio; a conta desativada e o primário de outro escritório ficam de fora');
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Notificacao::class)->count([
            'usuario' => $primarioDeFora->getId(),
            'tipo'    => PedirAcessoAosPerfisUseCase::TIPO_NOTIFICACAO,
        ]));
        $destinos = array_map(static fn (Notificacao $n): ?int => $n->getUsuario()?->getId(), $avisos);
        sort($destinos);
        $esperados = [$this->titular->getId(), $socio->getId()];
        sort($esperados);
        self::assertSame($esperados, $destinos);
        self::assertSame('Pedido de acesso aos Perfis', $avisos[0]->getTitulo());
        self::assertSame('Colega Secundária pediu acesso ao menu Perfis do Escritório.', $avisos[0]->getMensagem());
        self::assertSame(sprintf('/tenant/%d/roles?pedido=%d', $this->tenant->getId(), $this->colega->getId()), $avisos[0]->getUrl());

        $crawler = $this->client->request('GET', $this->urlLista());
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Pedido enviado ao primário', trim($crawler->filter('.pf-restrito-acoes > [data-pf-pedido-enviado]')->text()));
        self::assertCount(0, $crawler->filter('form[data-pf-form-pedir-acesso]'));

        // O teto vale no servidor: um segundo POST no mesmo dia não avisa de novo.
        $this->client->request('POST', $this->urlLista() . '/pedir-acesso', ['_token' => $token]);
        self::assertResponseRedirects($this->urlLista());
        self::assertCount(2, $this->avisosDoPedido());
    }

    #[TestDox('C2: pedir acesso sem CSRF válido é 403 e não avisa ninguém')]
    public function testPedirAcessoSemCsrf(): void
    {
        $this->logarComTenant($this->client, $this->colega, $this->tenant);
        $this->client->request('POST', $this->urlLista() . '/pedir-acesso', ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->avisosDoPedido());
    }

    // ── C5: edit-role ──────────────────────────────────────────────────────

    #[TestDox('C5/C7: no edit-role, o secundário com admin.users.manage não troca o próprio perfil (select só com o atual e POST forjado recusado)')]
    public function testEditRoleNaoTrocaOProprioPerfil(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $crawler = $this->client->request('GET', $this->urlEditRole($this->gestor));
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $this->perfilGestor->getId()], $this->opcoesDoSelect($crawler));

        $this->submeterEditRole($this->gestor, $this->perfilRestrito);
        self::assertSame($this->perfilGestor->getId(), $this->recarregarVinculo($this->gestor)->getTenantRole()?->getId());
    }

    #[TestDox('C5/C7: no edit-role, o secundário só atribui perfil contido no dele; o perfil acima some do select e o POST forjado é recusado')]
    public function testEditRoleSoSubconjunto(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $crawler = $this->client->request('GET', $this->urlEditRole($this->colega));
        self::assertResponseIsSuccessful();
        $opcoes = $this->opcoesDoSelect($crawler);
        self::assertContains((string) $this->perfilRestrito->getId(), $opcoes);
        self::assertContains((string) $this->perfilGestor->getId(), $opcoes);
        self::assertNotContains((string) $this->perfilAmplo->getId(), $opcoes);
        self::assertNotContains((string) $this->perfilPrimario->getId(), $opcoes);

        $this->submeterEditRole($this->colega, $this->perfilAmplo);
        self::assertSame($this->perfilRestrito->getId(), $this->recarregarVinculo($this->colega)->getTenantRole()?->getId());

        $this->submeterEditRole($this->colega, $this->perfilGestor);
        self::assertResponseRedirects();
        self::assertSame($this->perfilGestor->getId(), $this->recarregarVinculo($this->colega)->getTenantRole()?->getId());
    }

    #[TestDox('C5: no edit-role, o secundário não mexe em quem tem perfil FORA do subconjunto dele (nem rebaixa, nem deixa sem perfil)')]
    public function testEditRoleNaoMexeEmQuemEstaAcima(): void
    {
        $acima = $this->criarUsuario('Colega Ampla', $this->perfilAmplo);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $crawler = $this->client->request('GET', $this->urlEditRole($acima));
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $this->perfilAmplo->getId()], $this->opcoesDoSelect($crawler), 'só o perfil atual');

        // POST forjado: o select só tem o perfil atual, então o form recusa (a mensagem C5 é o cinto, no unit).
        $this->submeterEditRole($acima, $this->perfilRestrito);
        self::assertResponseIsSuccessful();
        self::assertSame($this->perfilAmplo->getId(), $this->recarregarVinculo($acima)->getTenantRole()?->getId());

        $this->submeterEditRole($acima, null);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Seu perfil não pode mexer em quem tem permissões que ele não tem.', (string) $this->client->getResponse()->getContent());
        self::assertSame($this->perfilAmplo->getId(), $this->recarregarVinculo($acima)->getTenantRole()?->getId(), 'nem sem perfil');
    }

    #[TestDox('C5 (senha): o secundário com admin.users.manage não troca a senha de quem tem perfil acima do dele; troca a de quem tem perfil contido')]
    public function testSenhaSoDeQuemCabe(): void
    {
        $acima = $this->criarUsuario('Colega Ampla', $this->perfilAmplo);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $this->submeterEditRole($acima, $this->perfilAmplo, 'SenhaNova#2026');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Seu perfil não pode mexer em quem tem permissões que ele não tem.', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->senhaConfere($acima, 'SenhaNova#2026'));
        self::assertTrue($this->senhaConfere($acima, 'senha123'));

        $this->submeterEditRole($this->colega, $this->perfilRestrito, 'SenhaNova#2026');
        self::assertResponseRedirects();
        self::assertTrue($this->senhaConfere($this->colega, 'SenhaNova#2026'));
    }

    #[TestDox('C8: quem não é Master não troca a senha de quem tem vínculo ativo em outro escritório (secundário e primário recusados; senha intacta)')]
    public function testSenhaDeQuemUsaOutroEscritorio(): void
    {
        $alvo = $this->criarUsuario('Primária em B', $this->perfilRestrito);
        $this->em->flush();
        $this->vincularEmOutroEscritorio($alvo, true);
        $mensagem = 'Esta pessoa também usa outro escritório. A senha só pode ser trocada por ela, em Meu Perfil, ou pelo suporte.';

        foreach ([$this->gestor, $this->titular] as $ator) {
            $this->logarComTenant($this->client, $ator, $this->tenant);
            $this->submeterEditRole($alvo, $this->perfilRestrito, 'SenhaNova#2026');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($mensagem, (string) $this->client->getResponse()->getContent());
            self::assertTrue($this->senhaConfere($alvo, 'senha123'), (string) $ator->getFullName());
        }
    }

    #[TestDox('C8: vínculo SUSPENSO em outro escritório também bloqueia (pode voltar a valer); o Master troca a senha de quem usa outro escritório')]
    public function testSenhaComVinculoSuspensoEPeloMaster(): void
    {
        $suspenso = $this->criarUsuario('Suspensa em B', $this->perfilRestrito);
        $ativo    = $this->criarUsuario('Primária em B', $this->perfilRestrito);
        $master   = $this->criarUsuario('Master Senha', $this->perfilRestrito, ['ROLE_SUPER_ADMIN']);
        $this->em->flush();
        $this->vincularEmOutroEscritorio($suspenso, false);
        $this->vincularEmOutroEscritorio($ativo, true);

        $this->logarComTenant($this->client, $this->gestor, $this->tenant);
        $this->submeterEditRole($suspenso, $this->perfilRestrito, 'SenhaNova#2026');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(HierarquiaDePerfis::RECUSA_SENHA_DE_OUTRO_ESCRITORIO, (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->senhaConfere($suspenso, 'senha123'));

        $this->logarComTenant($this->client, $master, $this->tenant);
        $this->submeterEditRole($ativo, $this->perfilRestrito, 'SenhaDoMaster#2026');
        self::assertResponseRedirects();
        self::assertTrue($this->senhaConfere($ativo, 'SenhaDoMaster#2026'));
    }

    #[TestDox('C5 (remover): o secundário com admin.users.manage não remove do escritório quem tem perfil fora do subconjunto dele (POST direto)')]
    public function testNaoRemoveQuemEstaAcima(): void
    {
        $acima = $this->criarUsuario('Colega Ampla', $this->perfilAmplo);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/remover', $this->tenant->getId(), $acima->getId()), [
            '_token' => $this->tokenCsrf('remover_' . $acima->getId()),
        ]);

        self::assertResponseRedirects(sprintf('/tenant/%d/users', $this->tenant->getId()));
        self::assertTrue($this->recarregarVinculo($acima)->isActive(), 'continua no escritório');
        $this->client->followRedirect();
        self::assertStringContainsString('Seu perfil não pode mexer em quem tem permissões que ele não tem.', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('C5 (remover): o secundário remove quem tem perfil contido no dele')]
    public function testRemoveQuemCabe(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $this->client->request('POST', sprintf('/tenant/%d/user/%d/remover', $this->tenant->getId(), $this->colega->getId()), [
            '_token' => $this->tokenCsrf('remover_' . $this->colega->getId()),
        ]);

        self::assertResponseRedirects(sprintf('/tenant/%d/users', $this->tenant->getId()));
        $this->em->clear();
        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $this->colega->getId(), 'tenant' => $this->tenant->getId()]);
        self::assertTrue($vinculo === null || !$vinculo->isActive(), 'saiu do escritório');
    }

    #[TestDox('C5: no edit-role, o primário atribui qualquer secundário, inclusive acima do perfil de quem pede')]
    public function testEditRolePrimarioSemMudanca(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);

        $this->submeterEditRole($this->colega, $this->perfilAmplo);

        self::assertResponseRedirects();
        self::assertSame($this->perfilAmplo->getId(), $this->recarregarVinculo($this->colega)->getTenantRole()?->getId());
    }

    // ── C5: convite ────────────────────────────────────────────────────────

    #[TestDox('C5/C7: no convite, o secundário com admin.users.invite não vê nem forja perfil acima do dele; com perfil contido, convida')]
    public function testConviteSoSubconjunto(): void
    {
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);

        $crawler = $this->client->request('GET', '/escritorio/convites');
        self::assertResponseIsSuccessful();
        $opcao = static fn (TenantRole $r): string => sprintf('select[name=tenant_role_id] > option[value="%d"]', $r->getId());
        self::assertCount(1, $crawler->filter($opcao($this->perfilRestrito)));
        self::assertCount(1, $crawler->filter($opcao($this->perfilGestor)));
        self::assertCount(0, $crawler->filter($opcao($this->perfilAmplo)));
        self::assertCount(0, $crawler->filter($opcao($this->perfilPrimario)));

        $token = (string) $crawler->filter('form[action$="/escritorio/convites/novo"] input[name=_token]')->attr('value');
        $forjado = uniqid('acima_') . '@test.com';
        $this->client->request('POST', '/escritorio/convites/novo', ['_token' => $token, 'email' => $forjado, 'tenant_role_id' => $this->perfilAmplo->getId()]);
        self::assertResponseRedirects('/escritorio/convites');
        self::assertNull($this->em->getRepository(Invitation::class)->findOneBy(['email' => $forjado]));

        $ok = uniqid('contido_') . '@test.com';
        $this->client->request('POST', '/escritorio/convites/novo', ['_token' => $token, 'email' => $ok, 'tenant_role_id' => $this->perfilRestrito->getId()]);
        self::assertNotNull($this->em->getRepository(Invitation::class)->findOneBy(['email' => $ok]));
    }

    // ── Apoio ──────────────────────────────────────────────────────────────

    /** @return list<Notificacao> */
    private function avisosDoPedido(): array
    {
        $this->em->clear();

        /** @var list<Notificacao> $avisos */
        $avisos = $this->em->getRepository(Notificacao::class)->findBy([
            'tenant' => $this->tenant->getId(),
            'tipo'   => PedirAcessoAosPerfisUseCase::TIPO_NOTIFICACAO,
        ]);

        return $avisos;
    }

    /** @return list<string> */
    private function opcoesDoSelect(Crawler $crawler): array
    {
        return array_values(array_filter($crawler
            ->filter('select[name="edit_user_tenant_role[tenantRole]"] > option')
            ->each(static fn ($o): string => (string) $o->attr('value')), static fn (string $v): bool => $v !== ''));
    }

    /** O mesmo envio do PerfisDoEscritorioControllerTest: o perfil entra CRU no POST, para forjar opção escondida. */
    private function submeterEditRole(User $alvo, ?TenantRole $novoPerfil, ?string $novaSenha = null): void
    {
        $url     = $this->urlEditRole($alvo);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $form    = $crawler->filter('form[name=edit_user_tenant_role]')->form();
        $valores = $form->getPhpValues();
        $valores['edit_user_tenant_role']['tenantRole'] = $novoPerfil === null ? '' : (string) $novoPerfil->getId();
        if ($novaSenha !== null) {
            $valores['edit_user_tenant_role']['newPassword'] = ['first' => $novaSenha, 'second' => $novaSenha];
        }
        $valores['edit_user_tenant_role']['_token'] = (string) $crawler
            ->filter('input[name="edit_user_tenant_role[_token]"]')->attr('value');

        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);
    }

    /** @return list<string> números dos chips do resumo, na ordem do desenho */
    private function numerosDosChips(Crawler $crawler): array
    {
        return $crawler->filter('.pf-page > .pf-chips > .pf-chip > .pf-chip-txt > b[data-pf-chip-n]')->each(static fn ($b): string => trim($b->text()));
    }

    /** @return list<string> os códigos gravados no perfil, em ordem */
    private function codigosDe(TenantRole $perfil): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT p.code FROM tenant_role_permission trp JOIN permission p ON p.id = trp.permission_id WHERE trp.tenant_role_id = :r ORDER BY p.code',
            ['r' => $perfil->getId()]
        ));
    }

    /** Vínculo do usuário num escritório B, onde ele é primário; ativo ou não. */
    private function vincularEmOutroEscritorio(User $user, bool $ativo): void
    {
        $outro = new Tenant();
        $outro->setName('Escritório B ' . uniqid());
        $this->em->persist($outro);
        $sistemaDeB = new TenantRole();
        $sistemaDeB->setTenant($outro);
        $sistemaDeB->setName('Administrador do Escritório');
        $sistemaDeB->setIsSystem(true);
        $this->em->persist($sistemaDeB);
        $vinculo = new UserTenant($user, $outro);
        $vinculo->setTenantRole($sistemaDeB);
        if (!$ativo) {
            $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        }
        $this->em->persist($vinculo);
        $this->em->flush();
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

    private function urlEditRole(User $alvo): string
    {
        return sprintf('/tenant/%d/user/%d/edit-role', $this->tenant->getId(), $alvo->getId());
    }

    /** Token CSRF previsível (o mesmo molde do PerfisDoEscritorioControllerTest): chame logo antes do request. */
    private function tokenCsrf(string $id): string
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

        return 'TOKEN_' . $id;
    }

    private function recarregarVinculo(User $user): UserTenant
    {
        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $this->tenant->getId()]);
        self::assertNotNull($vinculo);
        $this->em->refresh($vinculo);

        return $vinculo;
    }

    /** @param list<string> $codigos */
    private function criarPerfil(string $nome, bool $sistema, array $codigos): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName($nome);
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        foreach ($codigos as $codigo) {
            $trp = new TenantRolePermission();
            $trp->setTenantRole($perfil);
            $trp->setPermission($this->garantirPermissao($codigo));
            $perfil->getTenantRolePermissions()->add($trp);
            $this->em->persist($trp);
        }

        return $perfil;
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
            $this->em->flush();
        }

        return $permissao;
    }

    /** @param list<string> $papeis */
    private function criarUsuario(string $nome, TenantRole $perfil, array $papeis = ['ROLE_USER'], ?Tenant $escritorio = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(uniqid('hierarquia_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em->persist($user);

        $vinculo = new UserTenant($user, $escritorio ?? $this->tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);

        return $user;
    }
}
