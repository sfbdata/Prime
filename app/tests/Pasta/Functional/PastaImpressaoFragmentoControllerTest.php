<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Controller\PastaResumoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * EDT-001/PAS-015: a folha da janela "Imprimir" (`pasta_resumo_imprimir?fragmento=1`).
 *
 * O fragmento é o que o pasta-impressao.js põe na pré-visualização e imprime por iframe: só a
 * folha, sem <html> e sem <script>, com as partes que a janela liga e desliga marcadas por
 * `data-rp-parte`. As opções da query (cabecalho, dados, autor, financeiro, fonte) são as mesmas
 * do PDF; aqui se prova que o servidor as obedece — e que o Financeiro só sai para quem o vê.
 *
 * O cross-tenant usa SUPER_ADMIN: ele passa em qualquer `canAccessResource`, então o 404 só pode
 * vir da conferência de dono.
 */
#[CoversClass(PastaResumoController::class)]
#[Group('pasta')]
final class PastaImpressaoFragmentoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO         = '07011345720258070007';
    private const NUMERO_MASCARA = '0701134-57.2025.8.07.0007';

    /** @param array<string, string> $query */
    private function url(int $pastaId, array $query = []): string
    {
        return "/pasta/{$pastaId}/resumo/imprimir?" . http_build_query(['fragmento' => '1'] + $query);
    }

    private function pastaComFinanceiro(Tenant $tenant): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setNomeCliente('CONDOMÍNIO DA FOLHA');
        $pasta->setValorCausa('12860.00');
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO));

        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao('Honorários iniciais');
        $pagamento->setValor('1234.00');
        $pagamento->setVencimento(new \DateTimeImmutable('2026-09-10'));
        $pagamento->alternarQuitacao(new \DateTimeImmutable('2026-09-05'));
        $this->em()->persist($pagamento);
        $this->em()->flush();

        return $pasta;
    }

    private function folha(Crawler $crawler): Crawler
    {
        $folha = $crawler->filter('.rp-documento > main.rp-folha');
        self::assertCount(1, $folha, 'a folha é filha direta do invólucro do fragmento');

        return $folha;
    }

    private function texto(Crawler $no): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $no->text('')));
    }

    #[TestDox('O fragmento é só a folha: sem <html> nem <script>, com o próprio estilo e cada parte do desenho no lugar')]
    public function testFragmentoTrazSoAFolhaComAsPartes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComFinanceiro($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', $this->url((int) $pasta->getId(), ['financeiro' => '1']));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('<script', $html, 'o iframe de impressão não executa nada — e a folha não traz nada para executar');
        self::assertStringNotContainsString('window.print', $html);
        self::assertStringContainsString('rp-sem-financeiro', $crawler->filter('.rp-documento > style')->text(''));

        $folha = $this->folha($crawler);
        self::assertSame('resumo', $folha->attr('data-rp-secao'));
        self::assertSame('Resumo da pasta ' . $pasta->getNup(), $folha->attr('data-rp-titulo'));

        // Ordem do `impMontar`: cabeçalho, dados, título, autor, corpo, financeiro, rodapé.
        self::assertSame(
            ['cabecalho', 'dados', 'titulo', 'autor', 'corpo', 'financeiro', 'rodape'],
            $folha->filter('main.rp-folha > [data-rp-parte]')->each(static fn (Crawler $n): string => (string) $n->attr('data-rp-parte')),
        );

        $cab = $this->texto($folha->filter('[data-rp-parte="cabecalho"]'));
        self::assertStringContainsString('BLUEJUS', $cab);
        self::assertStringContainsString('Impresso em', $cab);
        self::assertStringContainsString('por Admin Push', $cab);
        self::assertCount(1, $folha->filter('[data-rp-parte="cabecalho"] > .rp-faixa'), 'a tarja azul do desenho');

        $dados = $this->texto($folha->filter('[data-rp-parte="dados"]'));
        self::assertStringContainsString('CONDOMÍNIO DA FOLHA', $dados);
        self::assertStringContainsString(self::NUMERO_MASCARA, $dados);

        self::assertSame('Resumo da pasta ' . $pasta->getNup(), $this->texto($folha->filter('[data-rp-parte="titulo"]')));
        self::assertSame('', $this->texto($folha->filter('[data-rp-parte="autor"]')), 'o resumo não tem autor; a moldura do registro é que preenche');
        self::assertCount(1, $folha->filter('[data-rp-parte="corpo"] > [data-secao="dados"]'));
        self::assertCount(1, $folha->filter('[data-rp-parte="corpo"] > [data-secao="financeiro"]'));

        $fin = $this->texto($folha->filter('[data-rp-parte="financeiro"]'));
        self::assertStringContainsString('Contratado R$ 1.234,00 · Recebido R$ 1.234,00 · A receber R$ 0,00', $fin);
        self::assertStringContainsString('Honorários iniciais', $fin);
        self::assertStringContainsString('Recebido em 05/09/2026', $fin);

        self::assertSame('Documento interno do escritório · BlueJus', $this->texto($folha->filter('[data-rp-parte="rodape"]')));
    }

    /** @return iterable<string, array{string}> */
    public static function partesOpcionais(): iterable
    {
        yield 'cabeçalho do escritório' => ['cabecalho'];
        yield 'dados da pasta e do processo' => ['dados'];
        yield 'autor, data e tipo' => ['autor'];
        yield 'financeiro' => ['financeiro'];
    }

    #[DataProvider('partesOpcionais')]
    #[TestDox('Opção desligada na query some da folha — e só ela: $parte')]
    public function testOpcaoDesligadaOmiteSoAQuelaParte(string $parte): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComFinanceiro($tenant);
        $todas           = ['cabecalho' => '1', 'dados' => '1', 'autor' => '1', 'financeiro' => '1'];

        $this->logarComTenant($client, $user, $tenant);
        $folha = $this->folha($client->request('GET', $this->url((int) $pasta->getId(), [$parte => '0'] + $todas)));

        self::assertCount(0, $folha->filter('[data-rp-parte="' . $parte . '"]'));
        foreach (array_diff(array_keys($todas), [$parte]) as $outra) {
            self::assertCount(1, $folha->filter('[data-rp-parte="' . $outra . '"]'), "{$outra} continua");
        }
    }

    #[TestDox('Tudo desligado (o filtro que remove TUDO): sobram título, corpo e rodapé — e o Financeiro nasce desligado, como no desenho')]
    public function testTudoDesligadoEFinanceiroPadrao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComFinanceiro($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $folha = $this->folha($client->request('GET', $this->url((int) $pasta->getId(), [
            'cabecalho' => '0', 'dados' => '0', 'autor' => '0', 'financeiro' => '0',
        ])));
        self::assertSame(
            ['titulo', 'corpo', 'rodape'],
            $folha->filter('main.rp-folha > [data-rp-parte]')->each(static fn (Crawler $n): string => (string) $n->attr('data-rp-parte')),
        );
        self::assertStringNotContainsString('BLUEJUS', $folha->text());

        // Sem `financeiro` na query: o padrão do desenho (`gmImprimir`) é DESLIGADO.
        $padrao = $this->folha($client->request('GET', $this->url((int) $pasta->getId())));
        self::assertCount(0, $padrao->filter('[data-rp-parte="financeiro"]'));
        self::assertCount(1, $padrao->filter('[data-rp-parte="cabecalho"]'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fontes(): iterable
    {
        yield 'padrão' => ['', '11.5'];
        yield 'dentro do intervalo' => ['14', '14'];
        yield 'passo de meio ponto' => ['12.3', '12.5'];
        yield 'vírgula' => ['9,5', '9.5'];
        yield 'acima do teto' => ['99', '16'];
        yield 'abaixo do piso' => ['4', '9'];
        yield 'lixo' => ['abc', '11.5'];
    }

    #[DataProvider('fontes')]
    #[TestDox('Tamanho do texto: 9 a 16 pt em passos de 0,5 (pedido "$pedida" → $esperada pt)')]
    public function testTamanhoDoTexto(string $pedida, string $esperada): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $folha = $this->folha($client->request('GET', $this->url((int) $pasta->getId(), $pedida === '' ? [] : ['fonte' => $pedida])));

        self::assertSame('font-size: ' . $esperada . 'pt', $folha->filter('[data-rp-parte="corpo"]')->attr('style'));
    }

    #[TestDox('Quem NÃO vê o financeiro não recebe nem a seção resumida nem o bloco de lançamentos, mesmo pedindo financeiro=1')]
    public function testFinanceiroEscondidoDeQuemNaoVe(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComFinanceiro($tenant);

        // A regra de hoje é a do guarda de leitura; o dublê diz "não" sem mexer nesse guarda.
        $ve    = true;
        $duble = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $duble->method('podeVer')->willReturnCallback(static function () use (&$ve): bool {
            return $ve;
        });
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $duble);

        $this->logarComTenant($client, $user, $tenant);

        // Controle: quem vê recebe as duas coisas (prova que o cenário produz o financeiro).
        $comFin = $this->folha($client->request('GET', $this->url((int) $pasta->getId(), ['financeiro' => '1'])));
        self::assertCount(1, $comFin->filter('[data-rp-parte="financeiro"]'));
        self::assertCount(1, $comFin->filter('[data-secao="financeiro"]'));

        $ve = false;
        $crawler = $client->request('GET', $this->url((int) $pasta->getId(), ['financeiro' => '1']));
        self::assertResponseIsSuccessful();
        $semFin = $this->folha($crawler);
        self::assertCount(0, $semFin->filter('[data-rp-parte="financeiro"]'));
        self::assertCount(0, $semFin->filter('[data-secao="financeiro"]'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Honorários iniciais', $html);
        self::assertStringNotContainsString('R$ 12.860,00', $html);
        self::assertStringNotContainsString('R$ 1.234,00', $html);
    }

    #[TestDox('Fragmento de pasta de OUTRO escritório dá 404, sem nada dela na resposta')]
    public function testOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $pastaB->setNomeCliente('CLIENTE DO ESCRITÓRIO B');
        $this->em()->flush();

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', $this->url((int) $pastaB->getId(), ['financeiro' => '1']));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('CLIENTE DO ESCRITÓRIO B', (string) $client->getResponse()->getContent());

        // Seção desconhecida em pasta alheia também é 404 de DONO — a query não muda a resposta.
        $client->request('GET', $this->url((int) $pastaB->getId(), ['secao' => 'qualquer']));
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Usuário do MESMO escritório sem permissão de ver a pasta leva 403 no fragmento')]
    public function testSemPermissaoDa403(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $semNada    = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta      = $this->criarPasta($tenant);
        $pasta->setNomeCliente('SEGREDO DO CASO');
        $this->em()->flush();

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('SEGREDO DO CASO', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Seção desconhecida dá 404; a moldura só existe no fragmento (página própria → 404)')]
    public function testSecaoDesconhecidaEMolduraForaDoFragmento(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $pasta->getId();

        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', $this->url($id, ['secao' => 'segredo']));
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$id}/resumo/imprimir?secao=moldura");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Moldura (registro e processo): cabeçalho, dados e rodapé do servidor; título, autor e corpo VAZIOS para a tela preencher')]
    public function testMoldura(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComFinanceiro($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $folha = $this->folha($client->request('GET', $this->url((int) $pasta->getId(), ['secao' => 'moldura'])));

        self::assertSame('moldura', $folha->attr('data-rp-secao'));
        self::assertSame('', $this->texto($folha->filter('[data-rp-parte="titulo"]')));
        self::assertSame('', $this->texto($folha->filter('[data-rp-parte="autor"]')));
        self::assertSame('', $this->texto($folha->filter('[data-rp-parte="corpo"]')), 'nada do resumo na moldura');
        self::assertCount(0, $folha->filter('[data-secao]'));
        self::assertStringContainsString('CONDOMÍNIO DA FOLHA', $this->texto($folha->filter('[data-rp-parte="dados"]')));
        self::assertCount(1, $folha->filter('[data-rp-parte="rodape"]'));
    }
}
