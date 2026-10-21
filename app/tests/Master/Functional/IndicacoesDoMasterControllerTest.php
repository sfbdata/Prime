<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Entity\IndicacaoConfig;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\UseCase\ObterPainelDeIndicacoesUseCase;
use App\Master\Controller\IndicacoesDoMasterController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Seção "Indicações do BlueJus" do Painel do Master (desenho 05 VS 21.10.26, L234-242 e L683-689): o JSON do
 * cabeçalho e dos limites, a chave de pausa para todos e os três limites. Só o Master.
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): o 403 de quem não é Master vem
 * da regra, não do token. O CSRF errado tem teste próprio.
 */
#[CoversClass(IndicacoesDoMasterController::class)]
final class IndicacoesDoMasterControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . IndicacoesDoMasterController::TOKEN;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();
    }

    #[TestDox('Master lê o cabeçalho e os limites: os números batem com o ObterPainelDeIndicacoesUseCase')]
    public function testMasterLeOsNumerosDoUseCase(): void
    {
        $this->entrarComoMaster();
        $antes = $this->numeros();
        $por = $this->usuario(['ROLE_USER']);
        $agora = new \DateTimeImmutable();
        $this->em->persist(Indicacao::porEmail($por, null, uniqid('a_') . '@exemplo.com', null, $agora->modify('-1 hour')));
        $this->em->persist(Indicacao::porEmail($por, null, uniqid('b_') . '@exemplo.com', null, $agora->modify('-2 hours')));
        $this->em->persist(Indicacao::porEmail($por, null, uniqid('c_') . '@exemplo.com', null, $agora->modify('-3 days')));
        $emailQueVirouConta = uniqid('d_') . '@exemplo.com';
        $this->em->persist(Indicacao::porEmail($por, null, $emailQueVirouConta, null, $agora->modify('-5 days')));
        $this->usuario(['ROLE_USER'], $emailQueVirouConta, $agora->modify('-1 day'));
        $this->em->flush();

        $this->client->request('GET', '/master/indicacoes');

        self::assertResponseIsSuccessful();
        $dados = $this->json();
        $painel = static::getContainer()->get(ObterPainelDeIndicacoesUseCase::class)->executar();
        self::assertSame($painel->enviadas, $dados['enviadas']);
        self::assertSame($painel->ultimas24h, $dados['hoje']);
        self::assertSame($painel->viraramConta, $dados['viraramConta']);
        self::assertSame($antes['enviadas'] + 4, $dados['enviadas']);
        self::assertSame($antes['hoje'] + 2, $dados['hoje']);
        self::assertSame($antes['viraramConta'] + 1, $dados['viraramConta']);
        self::assertSame([
            'pausadoParaTodos' => $painel->limites->pausadoParaTodos,
            'porDia' => $painel->limites->porDia,
            'porMes' => $painel->limites->porMes,
            'alertaEm24h' => $painel->limites->alertaEm24h,
        ], $dados['limites']);
        self::assertArrayNotHasKey('indicadores', $dados, 'o desenho de 21/10 tirou a tabela por pessoa');
        self::assertArrayNotHasKey('alertas', $dados, 'os alertas vão para a Central de Notificações');
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'GET dados' => ['GET', '/master/indicacoes'];
        yield 'POST pausa' => ['POST', '/master/indicacoes/pausa'];
        yield 'POST limites' => ['POST', '/master/indicacoes/limites'];
    }

    #[DataProvider('rotas')]
    #[TestDox('quem não é Master (primário de escritório) recebe 403 em $metodo $url, e nada muda')]
    public function testNaoMaster403(string $metodo, string $url): void
    {
        $this->entrarComoPrimarioDeEscritorio();
        $antes = $this->limitesNoBanco();

        $this->postarOuLer($metodo, $url, ['pausar' => true, 'porDia' => 5, 'porMes' => 50, 'alertaEm24h' => 4]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($antes, $this->limitesNoBanco());
    }

    #[TestDox('sem login: não chega ao JSON')]
    public function testSemLogin(): void
    {
        $this->client->request('GET', '/master/indicacoes');

        self::assertFalse($this->client->getResponse()->isSuccessful());
    }

    #[TestDox('CSRF errado: 403 nos dois POST e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();
        $antes = $this->limitesNoBanco();

        $this->postar('/master/indicacoes/pausa', ['pausar' => !$antes['pausadoParaTodos']], 'errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token CSRF inválido. Recarregue a página.', $this->json()['erro']);

        $this->postar('/master/indicacoes/limites', ['porDia' => 7, 'porMes' => 70, 'alertaEm24h' => 6], 'errado');
        self::assertResponseStatusCodeSame(403);

        self::assertSame($antes, $this->limitesNoBanco());
    }

    #[TestDox('pausar e liberar para todos: grava, responde o estado novo e registra quem alterou')]
    public function testPausarELiberar(): void
    {
        $master = $this->entrarComoMaster();

        $this->postar('/master/indicacoes/pausa', ['pausar' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['limites']['pausadoParaTodos']);
        self::assertTrue($this->limitesNoBanco()['pausadoParaTodos']);
        self::assertSame($master->getId(), $this->config()->getAlteradoPor()?->getId());

        $this->postar('/master/indicacoes/pausa', ['pausar' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['limites']['pausadoParaTodos']);
        self::assertFalse($this->limitesNoBanco()['pausadoParaTodos']);
    }

    #[TestDox('pausa sem o campo booleano: 422 com a mensagem')]
    public function testPausaSemBooleano(): void
    {
        $this->entrarComoMaster();

        $this->postar('/master/indicacoes/pausa', ['pausar' => 'sim']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe se os convites ficam pausados ou liberados.', $this->json()['erro']);
    }

    #[TestDox('limites válidos (números ou texto do campo): grava os três e responde o estado novo')]
    public function testLimitesValidos(): void
    {
        $this->entrarComoMaster();

        $this->postar('/master/indicacoes/limites', ['porDia' => '12', 'porMes' => 120, 'alertaEm24h' => '9']);

        self::assertResponseIsSuccessful();
        $esperado = ['porDia' => 12, 'porMes' => 120, 'alertaEm24h' => 9];
        self::assertSame($esperado, array_intersect_key($this->json()['limites'], $esperado));
        self::assertSame($esperado, array_intersect_key($this->limitesNoBanco(), $esperado));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function limitesInvalidos(): iterable
    {
        yield 'dia zero' => [['porDia' => 0, 'porMes' => 10, 'alertaEm24h' => 5], 'O limite por dia precisa ser de pelo menos 1.'];
        yield 'mês negativo' => [['porDia' => 5, 'porMes' => -3, 'alertaEm24h' => 5], 'O limite por mês precisa ser de pelo menos 1.'];
        yield 'alerta zero' => [['porDia' => 5, 'porMes' => 10, 'alertaEm24h' => '0'], 'O alerta precisa ser de pelo menos 1.'];
        yield 'vazio' => [['porDia' => '', 'porMes' => 10, 'alertaEm24h' => 5], 'Informe os limites em números inteiros.'];
        yield 'fração' => [['porDia' => 2.5, 'porMes' => 10, 'alertaEm24h' => 5], 'Informe os limites em números inteiros.'];
        yield 'texto' => [['porDia' => 'dez', 'porMes' => 10, 'alertaEm24h' => 5], 'Informe os limites em números inteiros.'];
        yield 'faltando' => [['porDia' => 5, 'porMes' => 10], 'Informe os limites em números inteiros.'];
    }

    /** @param array<string, mixed> $corpo */
    #[DataProvider('limitesInvalidos')]
    #[TestDox('limite inválido ($_dataName): 422 com a mensagem e nada muda')]
    public function testLimitesInvalidos(array $corpo, string $mensagem): void
    {
        $this->entrarComoMaster();
        $antes = $this->limitesNoBanco();

        $this->postar('/master/indicacoes/limites', $corpo);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($mensagem, $this->json()['erro']);
        self::assertSame($antes, $this->limitesNoBanco());
    }

    #[TestDox('tela: a seção "ind" entra no painel logo depois da "com", com a chave e os três campos do desenho')]
    public function testSecaoNoPainel(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $painel = 'div.mp-painel[data-painel-aba="painel"]';
        $externa = $crawler->filter($painel . ' > div.mp-secao-externa[data-sec-externa="ind"]');
        self::assertCount(1, $externa);
        self::assertNotNull($externa->attr('hidden'), 'a seção abre pelo painel.js, oculta de início');
        self::assertCount(1, $crawler->filter($painel . ' > div.mp-secao-externa[data-sec-externa="com"] + div.mp-secao-externa[data-sec-externa="ind"]'), 'logo depois da seção Comercial');

        $caixa = $externa->filter('div.mp-secao-externa > div.mi[data-mi] > section.mi-caixa');
        self::assertCount(1, $caixa);
        $cabeca = $caixa->filter('section.mi-caixa > div.mi-cabeca');
        self::assertSame('Indicações do BlueJus', $cabeca->filter('div.mi-cabeca > b.mi-cabeca-titulo')->text());
        self::assertCount(1, $cabeca->filter('div.mi-cabeca > i.bi.bi-share'));
        self::assertCount(1, $cabeca->filter('div.mi-cabeca > span.mi-cabeca-sub[data-mi-resumo]'));
        $chave = $cabeca->filter('div.mi-cabeca > button.mi-chave[role="switch"][data-mi-pausa]');
        self::assertCount(1, $chave);
        self::assertSame('Convites liberados', $chave->filter('button.mi-chave > span[data-mi-pausa-txt]')->text());
        self::assertCount(1, $chave->filter('button.mi-chave > span.mi-chave-trilho > span.mi-chave-bola'));

        $campos = $caixa->filter('section.mi-caixa > div.mi-limites > label.mi-campo');
        self::assertCount(3, $campos);
        $esperados = [
            ['LIMITE POR DIA (POR PESSOA)', 'porDia', 'Cada usuário pode enviar no máximo esse número de convites em 24 horas. Passando disso, o sistema bloqueia e pede para tentar no dia seguinte.'],
            ['LIMITE POR MÊS', 'porMes', 'Cada usuário pode enviar no máximo esse número de convites em 30 dias.'],
            ['AVISAR NO CHAT A PARTIR DE (24H)', 'alertaEm24h', 'Se alguém enviar esse número de convites em 24 horas, o Master recebe um alerta no Chat I.A (disparo alto de convites), para identificar uso indevido.'],
        ];
        foreach ($esperados as $i => [$rotulo, $chaveDoCampo, $dica]) {
            $campo = $campos->eq($i);
            self::assertSame($rotulo, trim($campo->text()));
            self::assertSame($dica, $campo->attr('title'));
            self::assertCount(1, $campo->filter('label.mi-campo > i.bi.bi-info-circle'));
            $entrada = $campo->filter('label.mi-campo > input[type="number"][min="1"][data-mi-limite="' . $chaveDoCampo . '"]');
            self::assertCount(1, $entrada, $chaveDoCampo);
        }

        $regras = $caixa->filter('section.mi-caixa > div.mi-limites > span.mi-regras');
        self::assertSame('Regras de segurança dos convites', trim($regras->text()));
        self::assertStringStartsWith('Só envia convite quem tem nome, telefone e CPF cadastrados.', (string) $regras->attr('title'));
        self::assertCount(1, $regras->filter('span.mi-regras > i.bi.bi-shield-check'));

        self::assertCount(0, $externa->filter('table'), 'o desenho de 21/10 tirou a tabela por pessoa');
        self::assertCount(1, $crawler->filter('script[src*="js/master/indicacoes.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/master-indicacoes.css"]'));
    }

    #[TestDox('painel.js: a sub-aba "ind" entra logo depois da "com", com o rótulo e o ícone do desenho (SECS, L565)')]
    public function testSubAbaNoPainelJs(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/painel.js');

        self::assertStringContainsString(
            "  if (EXTERNAS.com) SECS.push(['com', 'Comercial e e-mails', 'bi-megaphone']);\n  if (EXTERNAS.ind) SECS.push(['ind', 'Indicações do BlueJus', 'bi-share']);\n",
            $js,
        );
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
        $tenant = (new Tenant())->setName('Escritório das indicações ' . uniqid());
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
    private function usuario(array $papeis, ?string $email = null, ?\DateTimeImmutable $criadoEm = null): User
    {
        $u = new User();
        $u->setEmail($email ?? 'indicacoes_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário das Indicações');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        if ($criadoEm !== null) {
            $u->setCreatedAt($criadoEm);
        }
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @return array{enviadas: int, hoje: int, viraramConta: int} */
    private function numeros(): array
    {
        $painel = static::getContainer()->get(ObterPainelDeIndicacoesUseCase::class)->executar();

        return ['enviadas' => $painel->enviadas, 'hoje' => $painel->ultimas24h, 'viraramConta' => $painel->viraramConta];
    }

    private function config(): IndicacaoConfig
    {
        $this->em->clear();

        return static::getContainer()->get(IndicacaoConfigRepository::class)->obter();
    }

    /** @return array{pausadoParaTodos: bool, porDia: int, porMes: int, alertaEm24h: int} */
    private function limitesNoBanco(): array
    {
        $c = $this->config();

        return ['pausadoParaTodos' => $c->isPausadoParaTodos(), 'porDia' => $c->getLimiteDia(), 'porMes' => $c->getLimiteMes(), 'alertaEm24h' => $c->getAlerta24h()];
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

    /** @param array<string, mixed> $corpo */
    private function postarOuLer(string $metodo, string $url, array $corpo): void
    {
        if ($metodo === 'GET') {
            $this->client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/json']);

            return;
        }
        $this->postar($url, $corpo);
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
