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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * EDT-004/FPN-010: "Imprimir extrato" do Financeiro sai pela MESMA folha do Imprimir resumo
 * (`secao=extrato`): tarja azul, cabeçalho BLUEJUS com "Impresso em … por …", faixa
 * Pasta/Cliente/Processo, título "Extrato de pagamentos", a lista de TODOS os lançamentos (a mesma
 * do `#psExtratoFolha`) e o rodapé "Documento interno do escritório · BlueJus" (dc 3515, 4611-4619).
 *
 * O extrato é financeiro inteiro: quem não vê o financeiro leva 403.
 */
#[CoversClass(PastaResumoController::class)]
#[Group('pasta')]
final class PastaImpressaoExtratoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function url(int $pastaId, bool $fragmento = true): string
    {
        return "/pasta/{$pastaId}/resumo/imprimir?secao=extrato" . ($fragmento ? '&fragmento=1' : '');
    }

    private function pagamento(Pasta $pasta, Tenant $tenant, string $descricao, string $valor, ?string $pagoEm = null): void
    {
        $p = new PastaPagamento();
        $p->setPasta($pasta);
        $p->setTenant($tenant);
        $p->setDescricao($descricao);
        $p->setValor($valor);
        $p->setVencimento(new \DateTimeImmutable('2026-09-10'));
        if ($pagoEm !== null) {
            $p->alternarQuitacao(new \DateTimeImmutable($pagoEm));
        }
        $this->em()->persist($p);
        $this->em()->flush();
    }

    private function texto(Crawler $no): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $no->text('')));
    }

    #[TestDox('A folha do extrato tem a tarja, o cabeçalho BLUEJUS, a faixa da pasta, o título, TODOS os lançamentos e o rodapé do desenho')]
    public function testFolhaDoExtrato(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeCliente('MARIA EXTRATO');
        $this->em()->flush();
        $this->pagamento($pasta, $tenant, 'Entrada de honorários', '1000.00', '2026-09-01');
        $this->pagamento($pasta, $tenant, '2ª parcela', '300.50');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseIsSuccessful();
        $folha = $crawler->filter('.rp-documento > main.rp-folha[data-rp-secao="extrato"]');
        self::assertCount(1, $folha);

        self::assertCount(1, $folha->filter('main.rp-folha > [data-rp-parte="cabecalho"] > .rp-faixa'), 'a tarja azul');
        $cab = $this->texto($folha->filter('[data-rp-parte="cabecalho"]'));
        self::assertStringContainsString('BLUEJUS', $cab);
        self::assertStringContainsString('Impresso em', $cab);
        self::assertStringContainsString('por Admin Push', $cab);

        $faixa = $this->texto($folha->filter('main.rp-folha > [data-rp-parte="dados"]'));
        self::assertStringContainsString('Pasta ' . $pasta->getNup(), $faixa);
        self::assertStringContainsString('Cliente MARIA EXTRATO', $faixa);

        self::assertSame('Extrato de pagamentos', $this->texto($folha->filter('main.rp-folha > [data-rp-parte="titulo"]')));

        $extrato = $folha->filter('[data-rp-parte="corpo"] > [data-secao="extrato"]');
        self::assertCount(1, $extrato);
        self::assertStringContainsString('R$ 1.000,00 recebidos de R$ 1.300,50', $this->texto($extrato->filter('p')));
        $linhas = $extrato->filter('ul > li')->each(fn (Crawler $li): string => $this->texto($li));
        self::assertCount(2, $linhas, 'pagos também, não só os próximos');
        self::assertContains('Entrada de honorários: R$ 1.000,00 · pago em 01/09/2026', $linhas);
        self::assertStringStartsWith('2ª parcela: R$ 300,50 · venceu 10/09/2026', $linhas[1], 'a mesma linha do `#psExtratoFolha`');

        self::assertSame('Documento interno do escritório · BlueJus', $this->texto($folha->filter('main.rp-folha > [data-rp-parte="rodape"]')));
        // Nada do resumo vaza para o extrato.
        self::assertCount(0, $folha->filter('[data-secao="metas"], [data-secao="dados"]'));
    }

    #[TestDox('O extrato não leva lançamento de outra pasta nem de outro escritório')]
    public function testExtratoNaoVaza(): void
    {
        $client             = static::createClient();
        [$user, $tenant]    = $this->criarAdmin();
        [, $tenantVizinho]  = $this->criarAdmin();
        $pasta              = $this->criarPasta($tenant);
        $irma               = $this->criarPasta($tenant);
        $vizinha            = $this->criarPasta($tenantVizinho);
        $this->pagamento($pasta, $tenant, 'Da própria pasta', '10.00');
        $this->pagamento($irma, $tenant, 'Da pasta irmã', '20.00');
        $this->pagamento($vizinha, $tenantVizinho, 'Do vizinho', '30.00');

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Da própria pasta', $html);
        self::assertStringNotContainsString('Da pasta irmã', $html);
        self::assertStringNotContainsString('Do vizinho', $html);
    }

    #[TestDox('Quem não vê o financeiro leva 403 no extrato — e nada dele na resposta')]
    public function testSemVerFinanceiroDa403(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->pagamento($pasta, $tenant, 'Lançamento secreto', '777.00');

        $naoVe = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $naoVe->method('podeVer')->willReturn(false);
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $naoVe);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Lançamento secreto', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Extrato de pasta de OUTRO escritório dá 404')]
    public function testOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $this->pagamento($pastaB, $tenantB, 'Do escritório B', '50.00');

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', $this->url((int) $pastaB->getId()));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Do escritório B', (string) $client->getResponse()->getContent());
    }

    #[TestDox('O "Imprimir extrato" do ⋮ de Pagamentos abre a janela de impressão da pasta (e só cai na impressão antiga sem ela)')]
    public function testMenuUsaAJanela(): void
    {
        $twig = (string) file_get_contents(__DIR__ . '/../../../templates/pasta/_financeiro.html.twig');
        $inicio = strpos($twig, 'function imprimirExtrato()');
        self::assertNotFalse($inicio);
        $funcao = substr($twig, $inicio, 400);

        self::assertStringContainsString('window.PastaImpressao && window.PastaImpressao.extrato()', $funcao);
        self::assertStringContainsString("extrato: function () { return abrir({ secao: 'extrato' }); }", (string) file_get_contents(__DIR__ . '/../../../public/js/pasta-impressao.js'));
    }
}
