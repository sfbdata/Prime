<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\ConfiguracoesGlobaisController;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Service\AvisoDeManutencao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Seção "Configurações globais" do Painel do Master (desenho 05 VS 25.10.26, L282-285 e L955-959): o JSON do
 * formulário, o salvar com os 15 campos (13 na linha única, 2 nas fontes de Planos e Faixas), a recusa e o aviso de
 * manutenção que sai dela. Só o Master. O armazém de CSRF é fixo (padrão dos testes do painel).
 */
#[CoversClass(ConfiguracoesGlobaisController::class)]
#[CoversClass(AvisoDeManutencao::class)]
final class ConfiguracoesGlobaisControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . ConfiguracoesGlobaisController::CSRF;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();
    }

    #[TestDox('Master lê o formulário: 5 grupos, 15 campos, padrões do desenho e as duas fontes de fora')]
    public function testLeFormulario(): void
    {
        $this->entrarComoMaster();

        $this->client->request('GET', '/master/configuracoes', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(['identidade', 'contas', 'seguranca', 'regiao', 'manutencao'], array_column($d['grupos'], 'grupo'));
        self::assertCount(15, array_merge(...array_column($d['grupos'], 'campos')));
        self::assertSame((string) static::getContainer()->get(PlanoRepository::class)->free()->getCotaGb(), $d['valores']['freeGb']);
        self::assertSame((string) static::getContainer()->get(ConfiguracaoDoEspacoRepository::class)->atual()->getFaixaAlerta(), $d['valores']['aviso']);
        self::assertSame('BlueJus', $d['padroes']['nome']);
        self::assertSame('24', $d['padroes']['sessao'], 'padrão do ambiente (app.sessao_padrao_minutos), não o 480 estático');
    }

    #[TestDox('quem não é Master recebe 403 nas duas rotas, e nada muda')]
    public function testNaoMaster(): void
    {
        $this->entrarComoPrimarioDeEscritorio();

        $this->client->request('GET', '/master/configuracoes');
        self::assertResponseStatusCodeSame(403);

        $this->postar(['nome' => 'Invasor']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravada());
    }

    #[TestDox('CSRF errado: 403 e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();

        $this->postar(['nome' => 'X'], 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravada());
    }

    #[TestDox('salvar: grava os 12 campos na linha única, o Free e o aviso nas fontes deles, e registra quem alterou')]
    public function testSalva(): void
    {
        $master = $this->entrarComoMaster();

        $this->postar([
            'nome' => 'Outro Nome', 'remetente' => 'envio@exemplo.com', 'suporte' => 'ajuda@exemplo.com', 'cadastro' => 'convite',
            'freeGb' => '12', 'aviso' => '85', 'sessao' => '60', 'tentativas' => '3', 'dois' => 'todos', 'retencao' => '730', 'avisoLoginNovo' => 'off',
            'idioma' => 'en', 'fuso' => 'America/Manaus', 'manut' => 'Parada às 22h', 'manutAte' => '2099-01-01T23:00',
        ]);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame('Outro Nome', $d['valores']['nome']);
        self::assertSame('12', $d['valores']['freeGb']);
        self::assertSame('85', $d['valores']['aviso']);
        self::assertNotNull($d['alteradoEm']);

        $this->em->clear();
        $linha = $this->gravada();
        self::assertNotNull($linha);
        self::assertSame('convite', $linha->getValores()['cadastro']);
        self::assertSame('America/Manaus', $linha->getValores()['fuso']);
        self::assertArrayNotHasKey('freeGb', $linha->getValores());
        self::assertArrayNotHasKey('aviso', $linha->getValores());
        self::assertSame($master->getId(), $linha->getAlteradoPor()?->getId());
        self::assertSame(12, static::getContainer()->get(PlanoRepository::class)->free()->getCotaGb());
        self::assertSame(85, static::getContainer()->get(ConfiguracaoDoEspacoRepository::class)->atual()->getFaixaAlerta());
    }

    #[TestDox('valor recusado: 422 com a mensagem e nada muda')]
    public function testRecusa(): void
    {
        $this->entrarComoMaster();

        $this->postar(['sessao' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('sessao', $this->json()['erro']);
        self::assertNull($this->gravada());
    }

    #[TestDox('aviso de espaço fora da ordem das faixas: 422 e o Free e a linha única ficam como estavam')]
    public function testAvisoForaDeOrdem(): void
    {
        $this->entrarComoMaster();
        $cotaAntes = static::getContainer()->get(PlanoRepository::class)->free()->getCotaGb();
        $faixaAntes = static::getContainer()->get(ConfiguracaoDoEspacoRepository::class)->atual()->getFaixaAlerta();
        $atencao = static::getContainer()->get(ConfiguracaoDoEspacoRepository::class)->atual()->getFaixaAtencao();

        $this->postar(['nome' => 'Nome novo', 'freeGb' => (string) ($cotaAntes + 5), 'aviso' => (string) $atencao]);

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame($cotaAntes, static::getContainer()->get(PlanoRepository::class)->free()->getCotaGb());
        self::assertSame($faixaAntes, static::getContainer()->get(ConfiguracaoDoEspacoRepository::class)->atual()->getFaixaAlerta());
        self::assertNull($this->gravada());
    }

    #[TestDox('pedido igual ao que vale não grava a linha única')]
    public function testSemMudancaNaoGrava(): void
    {
        $this->entrarComoMaster();

        $this->postar([]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->gravada());
    }

    #[TestDox('`sessao` só é gravada quando difere do padrão do ambiente (24): igual não grava, 480 grava, voltar a 24 apaga')]
    public function testSessaoSoGravaQuandoDifereDoPadraoDoAmbiente(): void
    {
        $this->entrarComoMaster();

        $this->postar(['nome' => 'Outro nome', 'sessao' => '24']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('sessao', $this->gravada()?->getValores() ?? []);

        $this->postar(['nome' => 'Outro nome', 'sessao' => '480']);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame('480', $this->gravada()?->getValores()['sessao'] ?? null);

        $this->postar(['nome' => 'Outro nome', 'sessao' => '24']);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertArrayNotHasKey('sessao', $this->gravada()?->getValores() ?? []);
    }

    #[TestDox('aviso de manutenção: aparece para o usuário logado enquanto vale, some depois do prazo e sem texto')]
    public function testAvisoDeManutencao(): void
    {
        $this->entrarComoMaster();
        $this->postar(['manut' => 'Parada às 22h <b>hoje</b>', 'manutAte' => '2099-01-01T23:00']);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/master');
        $aviso = $crawler->filter('.ae-avisos > .ae-aviso[data-aviso-manutencao]');
        self::assertCount(1, $aviso);
        self::assertSame('Parada às 22h <b>hoje</b>', $aviso->filter('.ae-texto > div')->text(), 'o texto sai escapado');
        self::assertCount(0, $aviso->filter('b > b'));

        $this->postar(['manut' => 'Parada às 22h', 'manutAte' => '2000-01-01T00:00']);
        self::assertCount(0, $this->client->request('GET', '/master')->filter('[data-aviso-manutencao]'), 'prazo vencido');

        $this->postar(['manut' => '', 'manutAte' => '']);
        self::assertCount(0, $this->client->request('GET', '/master')->filter('[data-aviso-manutencao]'), 'sem texto');
    }

    #[TestDox('tela: a seção "config" é externa, com a barra de ações e a grade; o painel.js não a esconde atrás da vazia')]
    public function testSecaoNoPainel(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        $externa = $crawler->filter('div.mp-painel[data-painel-aba="painel"] > div.mp-secao-externa[data-sec-externa="config"]');
        self::assertCount(1, $externa);
        self::assertNotNull($externa->attr('hidden'));
        $raiz = $externa->filter('div.mp-secao-externa > div.mcg[data-mcg]');
        self::assertCount(1, $raiz);
        self::assertCount(1, $raiz->filter('div.mcg > div.mcg-barra > span.mcg-status'));
        self::assertCount(1, $raiz->filter('div.mcg-barra > button.mcg-btn[data-mcg-padrao]'));
        self::assertCount(1, $raiz->filter('div.mcg-barra > button.mcg-salvar[data-mcg-salvar][disabled]'));
        self::assertCount(1, $raiz->filter('div.mcg > div.mcg-grade[data-mcg-grade]'));
        self::assertCount(1, $crawler->filter('script[src*="js/master/configuracoes.js"]'));
    }

    private function gravada(): ?ConfiguracaoGlobal
    {
        return static::getContainer()->get(ConfiguracaoGlobalRepository::class)->buscarUnica();
    }

    private function entrarComoMaster(): User
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = (new Tenant())->setName('Escritório das configurações ' . uniqid());
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('config_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário das Configurações');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed> $valores */
    private function postar(array $valores, string $token = self::TOKEN): void
    {
        $this->client->request('POST', '/master/configuracoes', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode(['valores' => $valores]));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
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
