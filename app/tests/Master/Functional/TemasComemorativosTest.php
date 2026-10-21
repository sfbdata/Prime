<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\TemasComemorativosController;
use App\Master\Entity\TemaConfig;
use App\Master\Repository\TemaConfigRepository;
use App\Master\Twig\TemaComemorativoExtension;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Temas comemorativos (spec docs/specs/temas-comemorativos.md): o Master configura pelo card da aba "Telas e
 * recursos"; toda página logada aplica o tema ativo.
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): o 403 do usuário comum vem
 * da regra, não do token. O CSRF inválido tem teste próprio. As páginas com tema usam o modo manual (independe da
 * data em que a suíte roda); a data é coberta no unit (TemaDoDiaTest, CatalogoDeTemasTest).
 */
#[CoversClass(TemasComemorativosController::class)]
#[CoversClass(TemaComemorativoExtension::class)]
#[CoversClass(TemaConfigRepository::class)]
final class TemasComemorativosTest extends JusPrimeWebTestCase
{
    use Factories;

    private const TOKEN = 'TOKEN_master_temas';

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

        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório dos temas ' . uniqid()])->_real();
        $this->master = UserFactory::createOne(['fullName' => 'Master do Sistema', 'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']])->_real();
        $this->comum = UserFactory::createOne(['fullName' => 'Primário do Escritório', 'roles' => ['ROLE_USER']])->_real();
        $this->vincular($this->master);
        $this->vincular($this->comum);
    }

    #[TestDox('o Master fixa um tema (modo manual): a resposta traz o estado novo e a linha única é gravada e auditada')]
    public function testMasterSalvaModoManual(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/temas/modo', ['modo' => 'manual', 'manual' => 'natal']);

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame('manual', $json['modo']);
        self::assertSame('natal', $json['manual']);
        self::assertSame('natal', $json['ativo']);
        self::assertCount(11, $json['temas']);
        $natal = array_column($json['temas'], null, 'id')['natal'];
        self::assertSame('18/12 a 25/12', $natal['quando']);
        self::assertTrue($natal['ligado']);

        $config = $this->config();
        self::assertNotNull($config);
        self::assertSame(TemaConfig::ID_UNICO, $config->getId());
        self::assertSame('manual', $config->getModo());
        self::assertSame('natal', $config->getManual());
        self::assertSame($this->master->getId(), $config->getAlteradoPor()?->getId());

        $linhas = $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => TemaConfig::class]);
        self::assertCount(1, $linhas);
        self::assertNull($linhas[0]->getTenantId(), 'efeito global: a linha fica sem escritório, só na trilha do Master');
    }

    #[TestDox('o Master troca para "Desligado" e o status do estado volta sem tema')]
    public function testMasterDesligaOsTemas(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/temas/modo', ['modo' => 'manual', 'manual' => 'pais']);

        $this->postar('/master/temas/modo', ['modo' => 'desligado', 'manual' => 'pais']);

        self::assertResponseIsSuccessful();
        self::assertSame('desligado', $this->json()['modo']);
        self::assertNull($this->json()['ativo']);
        self::assertSame('desligado', $this->config()?->getModo());
    }

    #[TestDox('o Master desliga e religa um tema pelo interruptor')]
    public function testMasterDesligaUmTema(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/temas/outrosa/ligado', ['ligado' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse(array_column($this->json()['temas'], null, 'id')['outrosa']['ligado']);
        self::assertSame(['outrosa'], $this->config()?->getDesligados());

        $this->postar('/master/temas/outrosa/ligado', ['ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue(array_column($this->json()['temas'], null, 'id')['outrosa']['ligado']);
        self::assertSame([], $this->config()?->getDesligados());
    }

    #[TestDox('quem não é Master recebe 403 e nada muda')]
    public function testNaoMasterRecebe403(): void
    {
        $this->entrar($this->comum);

        $this->postar('/master/temas/modo', ['modo' => 'desligado']);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/temas/natal/ligado', ['ligado' => false]);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->config());
    }

    #[TestDox('sem login: o pedido JSON recebe 401 ("Sua sessão terminou") e nada muda')]
    public function testSemLoginRecusa(): void
    {
        $this->postar('/master/temas/modo', ['modo' => 'desligado']);

        self::assertResponseStatusCodeSame(401);
        self::assertNull($this->config());
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrfInvalido(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/temas/modo', ['modo' => 'desligado'], 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/temas/natal/ligado', ['ligado' => false], 'errado');
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->config());
    }

    #[TestDox('tema desconhecido, modo inválido e corpo sem "ligado": 422 com a mensagem')]
    public function testRecusas422(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/temas/carnaval/ligado', ['ligado' => false]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Tema desconhecido.', $this->json()['erro']);

        $this->postar('/master/temas/modo', ['modo' => 'manual', 'manual' => 'carnaval']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Tema desconhecido.', $this->json()['erro']);

        $this->postar('/master/temas/modo', ['modo' => 'festa']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Modo de tema inválido.', $this->json()['erro']);

        $this->postar('/master/temas/natal/ligado', []);
        self::assertResponseStatusCodeSame(422);

        self::assertNull($this->config());
    }

    #[TestDox('painel do Master: o card "Temas comemorativos" é o primeiro filho de .mp-rec, com os textos do desenho')]
    public function testCardNoPainel(): void
    {
        $this->gravar('manual', 'criancas');
        $this->entrar($this->master);

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#master-painel > .mp-rec[data-painel-aba="rec"] > .mp-tm:first-child');
        self::assertCount(1, $card);
        self::assertCount(1, $crawler->filter('.mp-rec > .mp-tm + .mp-rec-card'), 'o card de Telas e recursos vem logo depois');
        self::assertCount(1, $card->filter('.mp-tm-cab > i.bi-palette'));
        self::assertSame('Temas comemorativos', $card->filter('.mp-tm-cab > .mp-tm-cab-texto > b')->text());
        self::assertSame(
            'Muda a cor do sistema e mostra uma mensagem nas datas especiais. Vale para todos os usuários; cada um pode fechar a mensagem.',
            $card->filter('.mp-tm-cab > .mp-tm-cab-texto > div')->text(),
        );
        self::assertSame('🎈 Dia das Crianças está ativo agora', $card->filter('.mp-tm-cab > [data-mp-tm-status]')->text());

        $modos = $card->filter('.mp-tm-barra > .mp-tm-modos > button.mp-tm-modo');
        self::assertSame(['Automático pelo calendário', 'Escolher um tema', 'Desligado'], $modos->each(static fn (Crawler $b): string => $b->text()));
        self::assertSame('manual', $card->filter('.mp-tm-modo.is-on')->attr('data-modo'));
        self::assertSame('Clique num tema para deixá-lo fixo.', $card->filter('.mp-tm-barra > .mp-tm-dica')->text());
        self::assertNull($card->filter('.mp-tm-barra > .mp-tm-dica')->attr('hidden'), 'a dica aparece no modo manual');
        self::assertSame('Parar prévia', trim($card->filter('.mp-tm-barra > .mp-tm-parar')->text()));
        self::assertCount(1, $card->filter('.mp-tm-barra > .mp-tm-parar[hidden] > i.bi-stop-fill'));

        $temas = $card->filter('.mp-tm-grade > .mp-tm-tema');
        self::assertCount(11, $temas);
        $criancas = $card->filter('.mp-tm-grade > .mp-tm-tema.is-sel[data-tema="criancas"]');
        self::assertCount(1, $criancas);
        self::assertSame('Dia das Crianças', $criancas->filter('.mp-tm-corpo > b')->text());
        self::assertSame('12/10', $criancas->filter('.mp-tm-corpo > .mp-tm-quando')->text());
        self::assertSame('🎈', $criancas->filter('.mp-tm-ic')->text());
        self::assertSame('ATIVO', $criancas->filter('.mp-tm-acoes > .mp-tm-ativo')->text());
        self::assertNull($criancas->filter('.mp-tm-acoes > .mp-tm-ativo')->attr('hidden'));
        self::assertSame('Prévia', $criancas->filter('.mp-tm-acoes > .mp-tm-previa')->text());
        self::assertSame('Ver como fica por 1 minuto', $criancas->filter('.mp-tm-acoes > .mp-tm-previa')->attr('title'));
        self::assertSame('Ligar ou desligar', $criancas->filter('.mp-tm-acoes > .mp-tm-chave.is-on')->attr('title'));
        self::assertCount(10, $card->filter('.mp-tm-grade > .mp-tm-tema .mp-tm-ativo[hidden]'), 'só o ativo tem o selo');
        self::assertSame('Brasil', $card->filter('.mp-tm-tema[data-tema="independencia"] .mp-tm-ic.is-texto')->text());

        $estado = json_decode((string) $card->attr('data-estado'), true);
        self::assertSame('criancas', $estado['ativo']);
        self::assertSame('/master/temas/modo', $card->attr('data-url-modo'));
        self::assertSame('/master/temas/idtema/ligado', $card->attr('data-url-ligar'));
        self::assertNotEmpty($card->attr('data-token'));
    }

    #[TestDox('status do card: desligado e sem tema hoje')]
    public function testStatusSemTema(): void
    {
        $this->gravar('desligado', null);
        $this->entrar($this->master);

        $crawler = $this->client->request('GET', '/master');
        self::assertSame('Temas desligados: cores padrão do BlueJus', $crawler->filter('.mp-tm [data-mp-tm-status]')->text());

        $this->gravar('manual', null);
        $crawler = $this->client->request('GET', '/master');
        self::assertSame('Nenhum tema hoje: cores padrão do BlueJus', $crawler->filter('.mp-tm [data-mp-tm-status]')->text());
    }

    #[TestDox('página logada com tema ativo: CSS, variáveis de cor, fita, selo com a mensagem literal e o botão redondo')]
    public function testPaginaComTemaAtivo(): void
    {
        $this->gravar('manual', 'outrosa');
        $this->entrar($this->comum);

        $crawler = $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('body link#bjtCssArquivo[rel="stylesheet"][href*="/css/tema-comemorativo.css"]'));
        $css = $crawler->filter('body style#bjtCss')->text();
        self::assertStringContainsString('--header-bg:#d0558f', $css);
        self::assertStringContainsString('--header-avatar-fg:#d0558f', $css);
        self::assertStringContainsString('--bs-primary:#d0558f', $css);
        self::assertStringContainsString('--bs-primary-rgb:208, 85, 143', $css);
        self::assertStringContainsString('--bs-link-color:#d0558f', $css);
        // Links do tema só fora do tema escuro (no escuro, a cor do tema some sobre o fundo).
        self::assertMatchesRegularExpression('/html:root:not\(\[data-bs-theme=dark\]\)\{[^}]*--bs-link-color:#d0558f/', $css);
        self::assertStringContainsString('--bjt-fita:linear-gradient(90deg,#f8bbd0,#d0558f,#f8bbd0)', $css);

        self::assertCount(1, $crawler->filter('body #bjtFita[aria-hidden="true"]'));
        $selo = $crawler->filter('body #bjtSelo');
        self::assertCount(1, $selo);
        self::assertSame('🎗️', $selo->filter('.e')->text());
        self::assertSame('Outubro Rosa', $selo->filter('.bjt-selo-texto > b')->text());
        self::assertStringContainsString('Outubro Rosa: prevenção ao câncer de mama. Cuide-se e faça seus exames.', $selo->filter('.bjt-selo-texto')->text());
        self::assertSame('Fechar', $selo->filter('button')->attr('title'));
        self::assertSame('×', $selo->filter('button')->text());
        $mini = $crawler->filter('body button#bjtMini[hidden]');
        self::assertSame('Outubro Rosa', $mini->attr('title'));
        self::assertCount(1, $crawler->filter('body script[src*="/js/tema-comemorativo.js"]'));
        self::assertNull($crawler->filter('#bjtTema')->attr('data-catalogo'), 'o catálogo da prévia é só do Master');
    }

    #[TestDox('tema animado (Dia das Crianças): fita e botão redondo com a animação')]
    public function testTemaAnimado(): void
    {
        $this->gravar('manual', 'criancas');
        $this->entrar($this->comum);

        $crawler = $this->client->request('GET', '/expediente');

        self::assertCount(1, $crawler->filter('#bjtFita.bjt-anim'));
        self::assertCount(1, $crawler->filter('#bjtMini.bjt-anim'));
    }

    #[TestDox('sem tema ativo: nada do tema na página, nem CSS nem script')]
    public function testSemTemaNadaNaPagina(): void
    {
        $this->gravar('desligado', null);
        $this->entrar($this->comum);

        $crawler = $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertCount(0, $crawler->filter('#bjtTema, #bjtCss, #bjtFita, #bjtSelo, #bjtMini'));
        self::assertStringNotContainsString('tema-comemorativo', $html);
        self::assertStringNotContainsString('bj-tema-visto', $html);
    }

    #[TestDox('Master sem tema ativo: só o script com o catálogo da prévia, sem CSS nem cores')]
    public function testMasterSemTemaLevaSoAPrevia(): void
    {
        $this->gravar('desligado', null);
        $this->entrar($this->master);

        $crawler = $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        $catalogo = json_decode((string) $crawler->filter('#bjtTema[hidden]')->attr('data-catalogo'), true);
        self::assertCount(11, $catalogo);
        self::assertSame('null', $crawler->filter('#bjtTema')->attr('data-tema'));
        self::assertCount(0, $crawler->filter('#bjtCss, #bjtCssArquivo, #bjtFita, #bjtSelo'));
        self::assertCount(1, $crawler->filter('body script[src*="/js/tema-comemorativo.js"]'));
    }

    private function gravar(string $modo, ?string $manual): void
    {
        $config = $this->config() ?? new TemaConfig();
        $config->definirModo($modo, $manual, null, new \DateTimeImmutable());
        $this->em->persist($config);
        $this->em->flush();
    }

    private function config(): ?TemaConfig
    {
        $this->em->clear();

        return $this->em->getRepository(TemaConfig::class)->find(TemaConfig::ID_UNICO);
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, server: [
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
