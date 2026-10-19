<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\RecursosDoSistemaController;
use App\Master\Entity\RecursoEstado;
use App\Master\EventListener\BloqueioDeRecursoDesativado;
use App\Master\Service\RecursosDoSistema;
use App\Master\Twig\RecursosExtension;
use App\Master\UseCase\ConsultarAuditoriaUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Telas e recursos ligados e desligados pelo Master (spec docs/specs/perfis-1.2-telas-e-recursos.md §5).
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): o 403 do
 * usuário comum vem da regra, não do token. O CSRF inválido tem teste próprio.
 */
#[CoversClass(RecursosDoSistemaController::class)]
#[CoversClass(BloqueioDeRecursoDesativado::class)]
#[CoversClass(RecursosDoSistema::class)]
#[CoversClass(RecursosExtension::class)]
final class TelasERecursosTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $master;
    private User $comum;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();

        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório dos recursos ' . uniqid()])->_real();
        $this->master = UserFactory::createOne(['fullName' => 'Master do Sistema', 'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']])->_real();
        $this->comum = UserFactory::createOne(['fullName' => 'Primário do Escritório', 'roles' => ['ROLE_USER']])->_real();
        $this->vincular($this->master);
        $this->vincular($this->comum);
    }

    #[TestDox('não-Master recebe a página "em preparação" (503) com motivo, previsão e o botão de volta')]
    public function testNaoMasterRecebe503(): void
    {
        $this->desligar('metas', 'Revisão ética', '2099-10-25');
        $this->entrar($this->comum);

        $crawler = $this->client->request('GET', '/tarefas/minhas');

        self::assertResponseStatusCodeSame(503);
        $pagina = $crawler->filter('body > main.bj-recurso-preparo');
        self::assertCount(1, $pagina->filter('i.bi-tools'));
        self::assertSame('Minhas Metas em preparação', $pagina->filter('h1')->text());
        self::assertSame('Revisão ética', $pagina->filter('.bj-recurso-preparo-texto')->text());
        self::assertSame('Previsão: 25/10/2099', $pagina->filter('.bj-recurso-preparo-previsao')->text());
        self::assertSame('/expediente', $pagina->filter('a.bj-recurso-preparo-voltar')->attr('href'));
        self::assertSame('Voltar ao Expediente', $pagina->filter('a.bj-recurso-preparo-voltar')->text());
    }

    #[TestDox('sem motivo e sem data: texto padrão e nenhuma previsão')]
    public function testTextoPadrao(): void
    {
        $this->desligar('metas', null, null);
        $this->entrar($this->comum);

        $crawler = $this->client->request('GET', '/tarefas/minhas');

        self::assertResponseStatusCodeSame(503);
        self::assertSame(BloqueioDeRecursoDesativado::MENSAGEM_PADRAO, $crawler->filter('.bj-recurso-preparo-texto')->text());
        self::assertCount(0, $crawler->filter('.bj-recurso-preparo-previsao'));
    }

    #[TestDox('chamada de API da função desligada recebe 503 JSON com a mesma mensagem')]
    public function testApiRecebe503Json(): void
    {
        $this->desligar('dashboard', 'Correção de falha', null);
        $this->entrar($this->comum);

        $this->client->request('POST', '/dashboard/preferencias', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(503);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Dashboard em preparação. Correção de falha', $json['erro']);
        self::assertSame('Correção de falha', $json['mensagem']);
    }

    #[TestDox('o item desligado some do menu de quem não é Master (sidebar e subnav)')]
    public function testItemSomeDoMenu(): void
    {
        $this->entrar($this->comum);
        $antes = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $antes->filter('.pje-menu-list > li.pje-menu-item > a[href="/tarefas/minhas"]'), 'ativo, o item aparece');
        self::assertCount(1, $antes->filter('.navtop-subnav > a[href="/tarefas/minhas"]'));

        $this->desligar('metas', null, null);
        $this->desligar('novidades', null, null);
        $depois = $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $depois->filter('a[href="/tarefas/minhas"]'));
        self::assertCount(0, $depois->filter('.usuario-dropdown a[href="/novidades"]'));
        self::assertCount(0, $depois->filter('.bj-recurso-off'), 'quem não é Master não vê marcação');
        self::assertCount(0, $depois->filter('[data-recurso-faixa]'));
    }

    #[TestDox('o Master abre a tela desligada com a faixa vermelha e vê o item marcado no menu')]
    public function testMasterVeComFaixaEMarca(): void
    {
        $this->desligar('metas', 'Tela em preparação', null);
        $this->entrar($this->master);

        $crawler = $this->client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        self::assertSame('Esta tela está desativada para os usuários. Só o Master está vendo.', $crawler->filter('[data-recurso-faixa].bj-recurso-faixa')->text());
        self::assertCount(1, $crawler->filter('.pje-menu-list > li.pje-menu-item.bj-recurso-off > a[href="/tarefas/minhas"]'));
        self::assertCount(1, $crawler->filter('.navtop-subnav > a.bj-recurso-off[href="/tarefas/minhas"]'));
        self::assertCount(0, $crawler->filter('.pje-menu-list > li.pje-menu-item.bj-recurso-off > a[href="/expediente"]'), 'só o desligado é marcado');
    }

    #[TestDox('desligado com data vencida volta a valer como ativo (R4)')]
    public function testDataVencidaReativa(): void
    {
        $this->desligar('metas', 'Tela em preparação', (new \DateTimeImmutable('-1 day'))->format('Y-m-d'));
        $this->entrar($this->comum);

        $crawler = $this->client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.pje-menu-list > li.pje-menu-item > a[href="/tarefas/minhas"]'));
    }

    #[TestDox('o Master desliga e religa pelo POST JSON; a mudança vai para a trilha como "Desativou" e "Reativou"')]
    public function testMasterAlternaEAudita(): void
    {
        $this->entrar($this->master);

        $this->alternar('peticionar', ['ativo' => false, 'motivo' => 'Revisão ética', 'ate' => '2099-01-31']);
        self::assertResponseIsSuccessful();
        self::assertSame(['chave' => 'peticionar', 'ativo' => false, 'motivo' => 'Revisão ética', 'ate' => '2099-01-31'], $this->json());
        $estado = $this->estado('peticionar');
        self::assertNotNull($estado);
        self::assertFalse($estado->isAtivo());
        self::assertSame($this->master->getId(), $estado->getAlteradoPor()?->getId());

        $this->alternar('peticionar', ['ativo' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(['chave' => 'peticionar', 'ativo' => true, 'motivo' => null, 'ate' => null], $this->json());

        // A criação é gravada antes do id (IDENTITY) existir: o rótulo dela sai da chave no próprio registro.
        // Efeito global: a linha grava sem escritório (EscopoDeAuditoriaDePlataforma), só na trilha do Master.
        $linhas = $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => RecursoEstado::class]);
        self::assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            self::assertNull($linha->getTenantId());
        }
        $registros = static::getContainer()->get(ConsultarAuditoriaUseCase::class)->executar()['registros'];
        $acoes = array_column($registros, 'acao');
        self::assertContains('Desativou Peticionar', $acoes);
        self::assertContains('Reativou Peticionar', $acoes);
        $contas = array_column(array_filter($registros, static fn (array $r): bool => str_ends_with((string) $r['acao'], 'Peticionar')), 'conta');
        self::assertSame(['Plataforma (sem conta)'], array_values(array_unique($contas)));
    }

    #[TestDox('POST de quem não é Master: 403 e nada muda')]
    public function testNaoMasterRecebe403(): void
    {
        $this->entrar($this->comum);

        $this->alternar('dashboard', ['ativo' => false]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->estado('dashboard'));
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrfInvalido(): void
    {
        $this->entrar($this->master);

        $this->alternar('dashboard', ['ativo' => false], token: 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->estado('dashboard'));
    }

    #[TestDox('tela essencial e chave desconhecida: 422 com a mensagem')]
    public function testEssencialEDesconhecida422(): void
    {
        $this->entrar($this->master);

        $this->alternar('lista', ['ativo' => false]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Tela essencial: não pode ser desativada.', $this->json()['erro']);
        self::assertNull($this->estado('lista'));

        $this->alternar('inexistente', ['ativo' => false]);
        self::assertResponseStatusCodeSame(422);

        $this->alternar('dashboard', []);
        self::assertResponseStatusCodeSame(422, 'sem "ativo" no corpo');
    }

    #[TestDox('aba "Telas e recursos" no painel: terceira aba, cabeçalho, resumo e o estado inicial em JSON')]
    public function testAbaNoPainel(): void
    {
        $this->desligar('contribua', 'Tela em preparação', null);
        $this->entrar($this->master);

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $abas = $crawler->filter('.mp-abas > .mp-aba');
        self::assertCount(3, $abas);
        self::assertSame('rec', $abas->eq(2)->attr('data-aba'));
        self::assertSame('Telas e recursos', $abas->eq(2)->text());
        self::assertCount(1, $abas->eq(2)->filter('i.bi-toggles'));

        $painel = $crawler->filter('#master-painel > .mp-rec[data-painel-aba="rec"][hidden] > .mp-rec-card');
        self::assertCount(1, $painel);
        self::assertSame('Telas e recursos do sistema', $painel->filter('.mp-rec-cab > .mp-rec-cab-texto > b')->text());
        self::assertSame('2 desativado(s) para os usuários', $painel->filter('.mp-rec-cab > .mp-rec-resumo')->text(), 'as duas linhas de Contribua');
        self::assertSame('Buscar tela ou função', $painel->filter('.mp-rec-cab > input.mp-rec-busca')->attr('placeholder'));
        self::assertSame('/master/recursos/CHAVE', $painel->attr('data-url'));
        self::assertNotEmpty($painel->attr('data-token'));

        $linhas = json_decode((string) $painel->attr('data-recursos'), true);
        self::assertCount(19, $linhas);
        $contribua = array_values(array_filter($linhas, static fn (array $l): bool => $l['chave'] === 'contribua'));
        self::assertCount(2, $contribua);
        self::assertFalse($contribua[0]['ativo']);
        self::assertSame('Tela em preparação', $contribua[1]['motivo']);
        self::assertStringContainsString('js/master/recursos.js', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('menu do usuário: item desligado some, o grupo vazio some junto e Sair continua por último')]
    public function testMenuDoUsuarioSemGrupoVazio(): void
    {
        $this->entrar($this->comum);

        foreach (['tema', 'idioma', 'instalar'] as $chave) {
            $this->desligar($chave, null, null);
        }

        $crawler = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('div.usuario-dropdown');
        self::assertCount(0, $menu->filter('#toggleTema'));
        self::assertCount(0, $menu->filter('[data-mu-idioma]'));
        self::assertCount(0, $menu->filter('#linkInstalarApp'));
        $grupos = $crawler->filter('div.usuario-dropdown > div.mu-grupo');
        $grupos->each(static fn ($g) => self::assertGreaterThan(0, $g->filter('.mu-item')->count(), 'nenhum grupo vazio'));
        self::assertCount(1, $grupos->last()->filter('a[data-mu-sair]'), 'Sair continua por último');
        self::assertCount(1, $crawler->filter('div.usuario-dropdown > div.mu-grupo > [data-mu-ajuda]'), 'o outro grupo continua');
    }

    #[TestDox('o Master vê Idioma e Ajuda desligados marcados em vermelho no menu do usuário')]
    public function testMasterVeItensDoMenuMarcados(): void
    {
        $this->entrar($this->master);
        $this->desligar('idioma', null, null);
        $this->desligar('ajuda', null, null);

        $crawler = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('div.usuario-dropdown > div.mu-grupo > button.bj-recurso-off[data-mu-idioma]'));
        self::assertCount(1, $crawler->filter('div.usuario-dropdown > div.mu-grupo > button.bj-recurso-off[data-mu-ajuda]'));
        self::assertCount(0, $crawler->filter('div.usuario-dropdown > div.mu-grupo > .bj-recurso-off#toggleTema'));
    }

    #[TestDox('tema escuro desligado: quem não é Master não recebe o script que aplica o tema salvo; o Master recebe')]
    public function testTemaEscuroDesligadoNaoAplica(): void
    {
        $leitura = "localStorage.getItem('tema') === 'dark'";
        $this->entrar($this->comum);
        $this->client->request('GET', '/expediente');
        self::assertStringContainsString($leitura, (string) $this->client->getResponse()->getContent(), 'ativo, o tema salvo vale');

        $this->desligar('tema', null, null);
        $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($leitura, (string) $this->client->getResponse()->getContent());

        $this->entrar($this->master);
        $this->client->request('GET', '/expediente');
        self::assertStringContainsString($leitura, (string) $this->client->getResponse()->getContent());
    }

    private function desligar(string $chave, ?string $motivo, ?string $ate): void
    {
        $estado = $this->estado($chave) ?? new RecursoEstado($chave);
        $estado->desativar($motivo, $ate !== null ? new \DateTimeImmutable($ate) : null, null, new \DateTimeImmutable());
        $this->em->persist($estado);
        $this->em->flush();
    }

    private function estado(string $chave): ?RecursoEstado
    {
        return $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => $chave]);
    }

    /** @param array<string, mixed> $corpo */
    private function alternar(string $chave, array $corpo, string $token = 'TOKEN_master_recursos'): void
    {
        $this->client->request('POST', '/master/recursos/' . $chave, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    private function vincular(User $user): void
    {
        $perfil = (new TenantRole())->setTenant($this->escritorio)->setName('Administrador do Escritório ' . uniqid())->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($perfil));
        $this->em->flush();
    }

    private function instalarCsrfFixo(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }
}
