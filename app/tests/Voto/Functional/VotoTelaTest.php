<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Master\EventListener\BloqueioDeRecursoDesativado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\VotoController;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Service\LiberacaoDoVotoNoEscritorio;
use App\Voto\Twig\VotoExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * BlueJus Voto, fases A1 e A1b (docs/specs/bluejus-voto.md): a tela dentro da moldura, as três travas (recurso `voto`
 * do Master, permissão `modules.voto.view` e a liberação do escritório pelo Master) e o arranjo do desenho por filho
 * direto.
 *
 * O estado do recurso é montado em cada teste (ligar/desligar), para não depender de a migration
 * Version20261109120000 ter rodado no banco de teste. Quem não é Master só abre a tela com o escritório liberado
 * (`liberarOEscritorio()`); os testes de cada trava liberam o escritório para que a recusa venha só da trava testada.
 */
#[CoversClass(VotoController::class)]
#[CoversClass(LiberacaoDoVotoNoEscritorio::class)]
#[CoversClass(VotoExtension::class)]
final class VotoTelaTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório do Voto ' . uniqid()])->_real();
    }

    #[TestDox('usuário sem a permissão modules.voto.view recebe 403 e não vê o item no menu')]
    public function testSemPermissaoRecebe403(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'comum', false));

        $this->client->request('GET', '/voto');
        self::assertResponseStatusCodeSame(403);

        // Sem nenhuma permissão o Expediente também é barrado: a moldura é conferida em Novidades (todo usuário logado).
        $novidades = $this->client->request('GET', '/novidades');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $novidades->filter('.pje-menu-list'));
        self::assertCount(0, $novidades->filter('a[href="/voto"]'));
    }

    #[TestDox('perfil comum com a permissão modules.voto.view abre a tela')]
    public function testComPermissaoAbreATela(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'comum', true));

        $this->client->request('GET', '/voto');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('recurso desligado: quem não é Master recebe "em preparação" (503) e o item some do menu')]
    public function testDesligadoBarraQuemNaoEMaster(): void
    {
        $this->desligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        $crawler = $this->client->request('GET', '/voto');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('Voto em preparação', $crawler->filter('body > main.bj-recurso-preparo h1')->text());
        self::assertSame(BloqueioDeRecursoDesativado::MENSAGEM_PADRAO, $crawler->filter('.bj-recurso-preparo-texto > .bj-recurso-preparo-motivo')->text());

        $expediente = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $expediente->filter('a[href="/voto"]'));
    }

    #[TestDox('recurso desligado: o Master abre a tela com a faixa vermelha e vê o item marcado no menu')]
    public function testDesligadoMasterVeComFaixa(): void
    {
        $this->desligar();
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], 'sistema', false));

        $crawler = $this->client->request('GET', '/voto');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-recurso-faixa].bj-recurso-faixa'));
        self::assertCount(1, $crawler->filter('.pje-menu-list > li.pje-menu-item.bj-recurso-off > a[href="/voto"]'));
    }

    #[TestDox('recurso ligado: item no menu, sem marca, com o rótulo e o ícone do catálogo')]
    public function testLigadoItemNoMenu(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        $crawler = $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        $item = $crawler->filter('.pje-menu-list > li.pje-menu-item > a[href="/voto"]');
        self::assertCount(1, $item);
        self::assertSame('Voto', trim($item->filter('span')->text()));
        self::assertCount(1, $item->filter('i.bi.bi-archive'));
        self::assertCount(0, $crawler->filter('.bj-recurso-off'));
    }

    #[TestDox('recurso ligado: o Master vê o item sem a marca de desligado e a tela sem a faixa')]
    public function testLigadoMasterSemMarca(): void
    {
        $this->ligar();
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], 'sistema', false));

        $crawler = $this->client->request('GET', '/voto');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.pje-menu-list > li.pje-menu-item > a[href="/voto"]'));
        self::assertCount(0, $crawler->filter('li.bj-recurso-off > a[href="/voto"]'));
        self::assertCount(0, $crawler->filter('[data-recurso-faixa]'));
    }

    #[TestDox('arranjo do desenho: título e as quatro abas como filhos diretos da tela; sem a faixa de demonstração em nenhuma aba')]
    public function testArranjoDoDesenho(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        foreach (['ele', 'tra', 'ges', 'aco'] as $aba) {
            $tela = $this->client->request('GET', '/voto?aba=' . $aba);
            self::assertResponseIsSuccessful($aba);
            self::assertCount(0, $tela->filter('.voto-demonstracao'), 'o Voto é votação real: sem a faixa na aba ' . $aba);
            self::assertStringNotContainsString('Ambiente demonstrativo', $tela->filter('body')->text(), $aba);
            self::assertStringNotContainsString('fictícios', $tela->filter('body')->text(), $aba);
        }
        $crawler = $this->client->request('GET', '/voto');

        self::assertResponseIsSuccessful();
        self::assertSame('Voto', $crawler->filter('.voto-tela > h1.voto-titulo')->text());
        self::assertCount(0, $crawler->filter('.voto-tela > .vAviso.voto-demonstracao'));
        $abas = $crawler->filter('.voto-tela > nav.vC.voto-abas > a.vTab');
        self::assertSame(['Votar', 'Resultados', 'Gestão eleitoral', 'Acompanhar'], $abas->each(static fn ($a) => trim($a->text())));
        self::assertSame(
            ['bi-check2-square', 'bi-shield-check', 'bi-gear', 'bi-broadcast'],
            $abas->each(static fn ($a) => trim(str_replace('bi ', '', (string) $a->filter('i')->attr('class')))),
        );
        self::assertCount(0, $crawler->filter('.voto-tela .card, .voto-tela .btn'), 'componentes do AdminLTE não entram');
    }

    #[TestDox('sem aba na URL abre Votar, com o aviso de visão de gestão')]
    public function testAbaPadraoEVotar(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        $crawler = $this->client->request('GET', '/voto');

        self::assertSame('Votar', trim($crawler->filter('.voto-abas > a.vTab.vTabOn[aria-current="page"]')->text()));
        self::assertCount(1, $crawler->filter('.voto-abas > a.vTabOn'));
        self::assertSame(
            'Visão de gestão: aqui aparecem todas as votações. O votante vê somente aquelas em que consta. Clique em Votar para ver a tela do votante.',
            $crawler->filter('.voto-tela > .voto-painel[data-aba="ele"] > .vAviso')->text(),
        );
    }

    #[TestDox('aba Acompanhar mostra a lista vazia do desenho')]
    public function testAbaAcompanharVazia(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        $crawler = $this->client->request('GET', '/voto?aba=aco');

        self::assertSame('Acompanhar', trim($crawler->filter('.voto-abas > a.vTabOn')->text()));
        $caixa = $crawler->filter('.voto-tela > .voto-painel[data-aba="aco"] > .vC');
        self::assertSame('Acompanhar em tempo real', trim($caixa->filter('.vH')->text()));
        self::assertSame(
            'Clique em Assistir para abrir uma janela própria, que pode ser levada a outra tela. Assistir mostra participação e quórum e pode ser projetado.',
            $caixa->filter('.vLin.voto-aco-texto')->text(),
            'só o que a assembleia tem: sem Lista nominal nem Documento de auditoria',
        );
        self::assertSame('Nenhuma votação publicada.', $caixa->filter('.vLin.voto-aco-vazio')->text());
    }

    #[TestDox('aba desconhecida na URL cai em Votar')]
    public function testAbaDesconhecidaCaiEmVotar(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        $crawler = $this->client->request('GET', '/voto?aba=xyz');

        self::assertResponseIsSuccessful();
        self::assertSame('Votar', trim($crawler->filter('.voto-abas > a.vTabOn')->text()));
        self::assertCount(1, $crawler->filter('.voto-tela > .voto-painel[data-aba="ele"]'));
    }

    #[TestDox('Resultados sem pauta encerrada: nenhum painel vazio na coluna (Gestão eleitoral: AssembleiaGestorTest)')]
    public function testAbasSemConteudoNaoDesenhamPainel(): void
    {
        $this->ligar();
        $this->liberarOEscritorio();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        foreach (['tra' => 'Resultados'] as $aba => $rotulo) {
            $crawler = $this->client->request('GET', '/voto?aba=' . $aba);
            self::assertResponseIsSuccessful();
            self::assertSame($rotulo, trim($crawler->filter('.voto-abas > a.vTabOn')->text()));
            self::assertCount(0, $crawler->filter('.voto-tela > .voto-painel'));
        }
    }

    #[TestDox('escritório não liberado pelo Master: até o perfil de sistema recebe 403 e não vê o item no menu')]
    public function testNaoLiberadoRecebe403(): void
    {
        $this->ligar();
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        $this->client->request('GET', '/voto');
        self::assertResponseStatusCodeSame(403);

        $expediente = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $expediente->filter('.pje-menu-list'));
        self::assertCount(0, $expediente->filter('a[href="/voto"]'));
        self::assertSame(
            'O BlueJus Voto ainda não foi liberado para este escritório.',
            static::getContainer()->get('translator')->trans('voto.recusa.nao_liberado', [], null, 'pt_BR'),
        );
    }

    #[TestDox('escritório bloqueado pelo Master (linha com liberado falso): 403 e item fora do menu')]
    public function testBloqueadoPeloMasterRecebe403(): void
    {
        $this->ligar();
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $liberacao->bloquear();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->entrar($this->usuario(['ROLE_USER'], 'comum', true));

        $this->client->request('GET', '/voto');
        self::assertResponseStatusCodeSame(403);

        $novidades = $this->client->request('GET', '/novidades');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $novidades->filter('a[href="/voto"]'));
    }

    #[TestDox('a liberação de OUTRO escritório não vale para o da sessão: 403 e item fora do menu')]
    public function testLiberacaoDeOutroEscritorioNaoVale(): void
    {
        $this->ligar();
        $outro = TenantFactory::createOne(['name' => 'Outro escritório do Voto ' . uniqid()])->_real();
        $this->liberarOEscritorio($outro);
        $this->entrar($this->usuario(['ROLE_USER'], 'sistema', false));

        $this->client->request('GET', '/voto');
        self::assertResponseStatusCodeSame(403);

        $expediente = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $expediente->filter('a[href="/voto"]'));
    }

    #[TestDox('o Master abre a tela num escritório não liberado e vê o item no menu (confere antes de liberar)')]
    public function testMasterEmEscritorioNaoLiberadoAbreATela(): void
    {
        $this->ligar();
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], 'comum', false));

        $crawler = $this->client->request('GET', '/voto');

        self::assertResponseIsSuccessful();
        self::assertSame('Voto', $crawler->filter('.voto-tela > h1.voto-titulo')->text());
        self::assertCount(1, $crawler->filter('.pje-menu-list > li.pje-menu-item > a[href="/voto"]'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM voto.liberacao WHERE tenant_id = ?',
            [$this->escritorio->getId()],
        ), 'o escritório segue sem liberação: quem passou foi o Master');
    }

    private function liberarOEscritorio(?Tenant $tenant = null): void
    {
        $liberacao = new LiberacaoDoVoto($tenant ?? $this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    private function ligar(): void
    {
        $estado = $this->estado();
        if ($estado !== null) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    private function desligar(): void
    {
        $estado = $this->estado() ?? new RecursoEstado('voto');
        $estado->desativar(null, null, null, new \DateTimeImmutable());
        $this->em->persist($estado);
        $this->em->flush();
    }

    private function estado(): ?RecursoEstado
    {
        return $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
    }

    /**
     * @param list<string> $papeis
     * @param 'sistema'|'comum' $perfil 'sistema' passa pelo bypass do PermissionChecker; 'comum' depende da concessão
     */
    private function usuario(array $papeis, string $perfil, bool $comPermissao): User
    {
        $user = UserFactory::createOne(['fullName' => 'Usuário do Voto', 'roles' => $papeis])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem($perfil === 'sistema');
        $this->em->persist($papel);
        if ($comPermissao) {
            $this->conceder($papel);
        }
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }

    private function conceder(TenantRole $papel): void
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'modules.voto.view']);
        if ($permissao === null) {
            $permissao = (new Permission())->setCode('modules.voto.view')->setDescription('Acesso ao módulo Voto (eleições e votações)')->setGroup('modules');
            $this->em->persist($permissao);
        }
        $concessao = (new TenantRolePermission())->setTenantRole($papel)->setPermission($permissao);
        $this->em->persist($concessao);
        $papel->getTenantRolePermissions()->add($concessao);
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
