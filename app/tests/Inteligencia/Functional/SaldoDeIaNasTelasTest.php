<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\SaldoDeIaOutput;
use App\Inteligencia\Service\SaldoDeIaDaPessoa;
use App\Inteligencia\Twig\InteligenciaExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A barra de uso e a janela da trava da I.A. gratuita nas três telas de I.A. (spec ia-liberada-por-indicacao,
 * seção 6): drawer da pasta, Chat I.A. e BlueJus I.A. da Central, mais o aviso da janela "Ler documentos".
 * Tudo some em escritório com plano pago. O JS (abrir a janela na recusa 409) não roda aqui: prova-se o HTML de onde
 * ele parte. Arranjo por filho direto onde o lugar importa.
 */
#[CoversClass(InteligenciaExtension::class)]
final class SaldoDeIaNasTelasTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const DRAWER = 'aside.ia-drawer#psIaDrawer[data-ia-drawer]';
    private const JANELA = '.ias-ov[data-ia-saldo-janela] > .ias-caixa';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function pagarPlano(Tenant $tenant): void
    {
        $plano = new Plano('pago_' . substr(uniqid(), -8), 'Pago de teste', 20, 1990, 'Plano de teste', 99);
        $this->em()->persist($plano);
        $this->em()->persist(new EspacoDoEscritorio($tenant, $plano));
        $this->em()->flush();
    }

    /** O saldo da pessoa fixo no teste (antes da primeira requisição, para o container entregar o dublê). */
    private function saldoFixo(float $pecasUsadas, int $percentual, bool $esgotado = false): void
    {
        $saldo = $this->createStub(SaldoDeIaDaPessoa::class);
        $saldo->method('de')->willReturn(new SaldoDeIaOutput(
            seAplica: true,
            tokensUsados: (int) round($pecasUsadas * 33_000),
            tokensDireito: 100_000,
            pecasUsadas: $pecasUsadas,
            pecasDireito: 3,
            percentual: $percentual,
            esgotado: $esgotado,
            perto: $percentual >= 80,
            colegasCadastrados: 0,
        ));
        static::getContainer()->set(SaldoDeIaDaPessoa::class, $saldo);
    }

    /** @return array{Crawler, KernelBrowser} */
    private function pastaDoAdmin(bool $pago = false): array
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        if ($pago) {
            $this->pagarPlano($tenant);
        }
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return [$crawler, $client];
    }

    #[TestDox('Drawer da pasta, escritório sem plano pago: barra com o texto em peças logo abaixo do cabeçalho e o link "Ganhar mais" que abre "Indicar o BlueJus"')]
    public function testBarraNoDrawer(): void
    {
        [$crawler] = $this->pastaDoAdmin();

        $barra = $crawler->filter(self::DRAWER . ' > .ias-barra[data-ia-saldo-barra]');
        self::assertSame(1, $barra->count(), 'a barra é filha direta do drawer');
        self::assertSame('I.A. gratuita: 0,0 de 3 peças usadas', trim($barra->filter('.ias-linha > .ias-texto')->text()));
        self::assertSame(1, $barra->filter('.ias-linha > button.ias-ganhar[data-ind-abrir]')->count());
        self::assertSame('Ganhar mais', trim($barra->filter('.ias-ganhar')->text()));
        self::assertSame(1, $barra->filter('.ias-trilho[role="progressbar"]')->count(), 'o trilho de progresso existe');
        self::assertSame('0', $barra->attr('data-perto'));
        self::assertStringNotContainsString('ias-barra--perto', (string) $barra->attr('class'));
    }

    #[TestDox('Com 80% ou mais a barra fica amarela e o texto vira "Resta menos de 1 peça. Convide um colega para ganhar mais 3."')]
    public function testBarraPerto(): void
    {
        $this->saldoFixo(2.4, 80);
        [$crawler] = $this->pastaDoAdmin();

        $barra = $crawler->filter(self::DRAWER . ' > .ias-barra[data-ia-saldo-barra]');
        self::assertSame(1, $barra->count());
        self::assertStringContainsString('ias-barra--perto', (string) $barra->attr('class'));
        self::assertSame('1', $barra->attr('data-perto'));
        self::assertSame('Resta menos de 1 peça. Convide um colega para ganhar mais 3.', trim($barra->filter('.ias-texto')->text()));
    }

    #[TestDox('Uso parcial usa vírgula decimal: "1,4 de 3 peças usadas"')]
    public function testVirgulaDecimal(): void
    {
        $this->saldoFixo(1.4, 47);
        [$crawler] = $this->pastaDoAdmin();

        self::assertSame('I.A. gratuita: 1,4 de 3 peças usadas', trim($crawler->filter(self::DRAWER . ' > .ias-barra .ias-texto')->text()));
    }

    #[TestDox('Escritório com plano pago: nem barra, nem janela da trava, nem aviso de "Ler documentos"')]
    public function testSomeEmEscritorioPago(): void
    {
        [$crawler, $client] = $this->pastaDoAdmin(true);

        self::assertSame(0, $crawler->filter('.ias-barra')->count());
        self::assertSame(0, $crawler->filter('.ias-ov')->count());
        self::assertSame(0, $crawler->filter('script[src*="js/ia-saldo.js"]')->count());

        $pastaId = (int) preg_replace('/\D+/', '', (string) $crawler->filter(self::DRAWER)->attr('data-ia-painel-url'));
        $painel = $client->request('GET', "/pasta/{$pastaId}/ia/agentes");
        self::assertSame(0, $painel->filter('[data-ia-aviso-saldo]')->count());
    }

    #[TestDox('Janela da trava no drawer: título, texto, botões na ordem da spec e a linha do plano pago com link para o Contribua (administrador)')]
    public function testJanelaParaAdministrador(): void
    {
        [$crawler] = $this->pastaDoAdmin();

        $janela = $crawler->filter(self::JANELA);
        self::assertSame(1, $janela->count());
        self::assertNotNull($crawler->filter('.ias-ov[data-ia-saldo-janela]')->attr('hidden'), 'nasce fechada');
        self::assertSame('Sua I.A. gratuita acabou', trim($janela->filter('.ias-topo > b')->text()));
        self::assertSame(
            'Você usou o equivalente a 3 peças. Convide um colega: quando ele se cadastrar e confirmar o e-mail, você ganha mais 3.',
            trim($janela->filter('.ias-corpo > p#iasTexto')->text()),
        );
        $botoes = $janela->filter('.ias-corpo > .ias-acoes > button.ias-btn')->each(static fn (Crawler $b): string => trim($b->text()));
        self::assertSame(['Copiar meu link', 'Enviar pelo WhatsApp', 'Convidar por e-mail', 'Fechar'], $botoes);
        self::assertSame(1, $janela->filter('.ias-acoes > button[data-ia-saldo-email][data-ind-abrir]')->count(), 'o e-mail abre "Indicar o BlueJus"');

        $plano = $janela->filter('.ias-corpo > p.ias-plano[data-ia-saldo-plano]');
        self::assertSame(1, $plano->count());
        self::assertStringContainsString('Com um plano pago, o escritório usa a I.A. sem esse limite.', $plano->text());
        self::assertStringEndsWith('/contribua', (string) parse_url((string) $plano->filter('a')->attr('href'), PHP_URL_PATH));
        self::assertSame(1, $crawler->filter('script[src*="js/ia-saldo.js"]')->count());
    }

    #[TestDox('Colaborador (não administrador) não vê a linha do plano pago na janela da trava')]
    public function testJanelaParaColaborador(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $colaborador = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view']);
        $this->logarComTenant($client, $colaborador, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertSame(1, $crawler->filter(self::JANELA)->count());
        self::assertSame(0, $crawler->filter(self::JANELA . ' .ias-plano')->count());
    }

    #[TestDox('Aba Documentos: o aviso "Esta leitura pode usar boa parte da sua I.A. gratuita" aparece só sem plano pago')]
    public function testAvisoDeLerDocumentos(): void
    {
        [$crawler, $client] = $this->pastaDoAdmin();
        $pastaId = (int) preg_replace('/\D+/', '', (string) $crawler->filter(self::DRAWER)->attr('data-ia-painel-url'));

        $painel = $client->request('GET', "/pasta/{$pastaId}/ia/agentes");
        self::assertResponseIsSuccessful();

        $aviso = $painel->filter('.ia-aba-painel[data-ia-aba-painel="documentos"] > p.ia-docs-aceite[data-ia-aviso-saldo]');
        self::assertSame(1, $aviso->count());
        self::assertSame('Esta leitura pode usar boa parte da sua I.A. gratuita', trim($aviso->text()));
    }

    #[TestDox('Chat I.A.: barra no topo da coluna principal, com "Ganhar mais" abrindo a janela da trava (a página não tem "Indicar o BlueJus"), e a janela presente')]
    public function testChatIa(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/chat-ia');
        self::assertResponseIsSuccessful();

        $barra = $crawler->filter('main.ci-principal > .ias-barra[data-ia-saldo-barra]');
        self::assertSame(1, $barra->count());
        self::assertSame(1, $barra->filter('.ias-linha > button.ias-ganhar[data-ia-saldo-abrir]')->count());
        self::assertSame(0, $barra->filter('[data-ind-abrir]')->count());
        self::assertSame(1, $crawler->filter('body > ' . str_replace('> .ias-caixa', '', self::JANELA))->count());
        self::assertSame(1, $crawler->filter('script[src*="js/ia-saldo.js"]')->count());
    }

    #[TestDox('BlueJus I.A. da Central: barra dentro da tela de conversa (escondida até abrir a conversa com a IA) e a janela da trava')]
    public function testCentral(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/central');
        self::assertResponseIsSuccessful();

        $envoltorio = $crawler->filter('.bj-central-conversa-tela > div[data-central-ia-saldo]');
        self::assertSame(1, $envoltorio->count());
        self::assertNotNull($envoltorio->attr('hidden'));
        self::assertSame(1, $crawler->filter('.bj-central-conversa-tela > div[data-central-ia-saldo] > .ias-barra[data-ia-saldo-barra]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('.ias-ov[data-ia-saldo-janela]')->count());
    }
}
