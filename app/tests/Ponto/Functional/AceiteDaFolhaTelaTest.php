<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\AceiteDaFolhaController;
use App\Ponto\Controller\PontoController;
use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\SeloDaFolha;
use App\Ponto\Service\SeloDaFolhaHmac;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A barra da folha com o aceite mensal (spec ponto-desenho-0811, E16, P22 e P33; decisões 1, 4, 10, 11 e 16; desenho
 * 16 L144 a L147, L290 e L816): o selo do aceite que vale (verde "Assinada em", ocre "Ressalva em" e vermelho "Alterada
 * depois do aceite"), o "Assinar folha" só no mês fechado sem aceite que valha, com a função ligada e o segredo
 * presente, e a janela do aceite. O selo gravado fica com a função desligada ou sem o segredo; a ficha do gestor mostra
 * só o selo. O arranjo é conferido por filho direto.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(AceiteDaFolhaController::class)]
#[CoversClass(TenantController::class)]
final class AceiteDaFolhaTelaTest extends JusPrimeWebTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    private const SEGREDO = 'segredo-de-teste-da-tela-do-aceite';
    private const REGIAO = '.pe-page > .pe-grade > .pe-dir > #ponto-regiao-folha:first-child';
    private const BARRA = self::REGIAO . ' > section.pe-folha[data-screen-label="Folha de Ponto"]:first-child > div.pe-folha-barra:first-child';
    private const SELO = self::BARRA . ' > span.pe-folha-aceite';
    private const JANELA = 'body #ponto-janela-aceite.pe-janela-fundo[hidden] > form.pe-janela[role="dialog"][aria-modal="true"]';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $maria;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
        // O segredo entra no container antes do primeiro uso e não pode ser trocado depois: o teste sem ele é a exceção.
        $this->segredo($this->name() === 'testSemOSegredoOSeloFica' ? null : self::SEGREDO);
        $this->escritorio = $this->escritorio('Escritório da tela do aceite');
        $this->maria = $this->pessoaComOutubro($this->escritorio);
    }

    #[TestDox('Mês fechado sem aceite: a barra tem "Ver PDF", "Baixar XLSX", "Assinar folha" e "Baixar PDF", nesta ordem, e nenhum selo')]
    public function testBarraComAssinarFolha(): void
    {
        $tela = $this->tela();

        self::assertSame(
            ['Ver PDF', 'Baixar XLSX', 'Assinar folha', 'Baixar PDF'],
            $tela->filter(self::BARRA . ' > button.pe-folha-btn')->each(static fn (Crawler $no): string => trim($no->text())),
            'a ordem da decisão 11',
        );
        self::assertCount(1, $tela->filter(self::BARRA . ' > button.pe-folha-btn[data-folha-assinar]:nth-last-child(2) > i.bi.bi-pen'));
        self::assertCount(0, $tela->filter(self::BARRA . ' .pe-folha-aceite'), 'sem aceite, sem selo');
        self::assertCount(1, $tela->filter(self::JANELA), 'a janela do aceite entra na página com o botão');
    }

    #[TestDox('A janela do aceite (L290): competência, "Sua posição" em "Concordo", a ressalva sempre à vista, "Cancelar" e "Confirmar"')]
    public function testJanelaDoAceite(): void
    {
        $janela = $this->tela()->filter(self::JANELA);

        self::assertSame('/ponto/folha/assinar', $janela->attr('action'));
        self::assertSame(['post', 'aceite'], [strtolower((string) $janela->attr('method')), $janela->attr('data-janela-form')]);
        self::assertSame('Assinar folha', trim($janela->filter('.pe-janela-topo > b.pe-janela-titulo')->text()));
        self::assertCount(1, $janela->filter('.pe-janela-topo > i.bi.bi-pen'));
        self::assertSame('2026-10', $janela->filter('input[type="hidden"][name="competencia"]')->attr('value'));
        self::assertNotEmpty($janela->filter('input[type="hidden"][name="_token"]')->attr('value'));

        $corpo = $janela->filter('.pe-janela-corpo');
        self::assertSame('Competência 10/2026. Confira os registros antes de confirmar.', trim($corpo->filter('.pe-janela-texto')->text()));
        self::assertSame('10/2026', $corpo->filter('.pe-janela-texto > b')->text());
        $posicao = $corpo->filter('label.pe-janela-rotulo > select.pe-janela-select[name="situacao"]');
        self::assertSame(
            [['concordo', 'Concordo: os registros representam fielmente minha jornada'], ['ressalva', 'Discordo de parte dos registros (ressalva)']],
            $posicao->filter('option')->each(static fn (Crawler $no): array => [$no->attr('value'), trim($no->text())]),
        );
        self::assertSame('concordo', $posicao->filter('option[selected]')->attr('value'), 'abre em "Concordo" (L816)');
        $ressalva = $corpo->filter('label.pe-janela-rotulo > textarea.pe-janela-campo[name="ressalva"]');
        self::assertSame(['3', '1000'], [$ressalva->attr('rows'), $ressalva->attr('maxlength')]);
        self::assertCount(0, $corpo->filter('[hidden]'), 'a ressalva fica sempre à vista, como no desenho');
        self::assertStringStartsWith('Sua posição', trim($corpo->filter('label.pe-janela-rotulo')->eq(0)->text()));
        self::assertStringStartsWith('Ressalva', trim($corpo->filter('label.pe-janela-rotulo')->eq(1)->text()));

        self::assertCount(1, $janela->filter('div.pe-janela-erro[data-janela-erro][hidden]'));
        self::assertSame(['Cancelar', 'Confirmar'], $janela->filter('.pe-janela-rodape > button')->each(static fn (Crawler $no): string => trim($no->text())));
        self::assertSame('submit', $janela->filter('.pe-janela-rodape > button.pe-janela-acao[data-janela-enviar]')->attr('type'));
        self::assertCount(2, $janela->filter('button[data-janela-fechar]'), 'o "x" e o "Cancelar" fecham');
    }

    #[TestDox('Confirmar pela janela: volta na competência com "Folha assinada." e o selo verde "Assinada em 09/11/2026"; o botão some')]
    public function testConcordoPelaJanela(): void
    {
        $tela = $this->enviarPelaJanela('concordo', '');

        self::assertSame(AceiteDaFolhaController::FOLHA_ASSINADA, trim($tela->filter('.app-content-header .alert.alert-success')->text()));
        $this->assertSelo($tela, 'Assinada em 09/11/2026', 'pe-folha-aceite pe-folha-aceite--concordo');
        self::assertCount(0, $tela->filter('[data-folha-assinar]'), 'a folha assinada não se assina de novo');
        self::assertCount(0, $tela->filter('#ponto-janela-aceite'));
    }

    #[TestDox('Discordo com texto pela janela: o selo ocre "Ressalva em 09/11/2026"')]
    public function testRessalvaPelaJanela(): void
    {
        $tela = $this->enviarPelaJanela('ressalva', 'O dia 6 foi de folga.');

        $this->assertSelo($tela, 'Ressalva em 09/11/2026', 'pe-folha-aceite pe-folha-aceite--ressalva');
        self::assertSame('O dia 6 foi de folga.', $this->aceitesNoBanco($this->escritorio, $this->maria)[0]['ressalva']);
    }

    #[TestDox('Batida nova no mês depois do aceite: o selo vermelho "Alterada depois do aceite" e o "Assinar folha" volta')]
    public function testAlteradaDepoisDoAceite(): void
    {
        $this->assinar();
        $this->diaCompleto($this->maria, $this->escritorio, '2026-10-07');

        $tela = $this->tela();

        $this->assertSelo($tela, 'Alterada depois do aceite', 'pe-folha-aceite pe-folha-aceite--alterada');
        self::assertCount(1, $tela->filter(self::BARRA . ' > button[data-folha-assinar]'));
        self::assertCount(1, $tela->filter(self::JANELA));
    }

    #[TestDox('Aceite mexido no banco (UPDATE numa coluna, o IP): o selo vermelho "Alterada depois do aceite" e o "Assinar folha" volta (decisão 14)')]
    public function testAceiteMexidoNoBanco(): void
    {
        $this->assinar('ressalva', 'O dia 6 foi de folga.');
        $this->assertSelo($this->tela(), 'Ressalva em 09/11/2026', 'pe-folha-aceite pe-folha-aceite--ressalva');
        $aceite = (int) $this->aceitesNoBanco($this->escritorio, $this->maria)[0]['id'];
        self::assertSame(1, $this->em()->getConnection()->executeStatement("UPDATE ponto_folha_aceite SET ip = '10.0.0.1' WHERE id = ?", [$aceite]));

        $tela = $this->tela();

        $this->assertSelo($tela, 'Alterada depois do aceite', 'pe-folha-aceite pe-folha-aceite--alterada');
        self::assertCount(1, $tela->filter(self::BARRA . ' > button[data-folha-assinar]'));
        self::assertCount(1, $tela->filter(self::JANELA));
    }

    #[TestDox('Função desligada no Global: o selo gravado fica e o "Assinar folha" some, mesmo com a folha alterada depois do aceite')]
    public function testDesligadaNoGlobalOSeloFica(): void
    {
        $this->assinar();
        $this->diaCompleto($this->maria, $this->escritorio, '2026-10-07');
        $this->desligarNoGlobal();

        $tela = $this->tela();

        $this->assertSelo($tela, 'Alterada depois do aceite', 'pe-folha-aceite pe-folha-aceite--alterada');
        $this->assertSemAssinar($tela);
    }

    #[TestDox('Função desligada no Escritório: o selo gravado fica e o "Assinar folha" some')]
    public function testDesligadaNoEscritorioOSeloFica(): void
    {
        $this->assinar();
        $this->desligarNoEscritorio($this->escritorio, $this->maria);

        $tela = $this->tela();

        $this->assertSelo($tela, 'Assinada em 09/11/2026', 'pe-folha-aceite pe-folha-aceite--concordo');
        $this->assertSemAssinar($tela);
    }

    #[TestDox('Sem o segredo no servidor: o selo gravado fica e o "Assinar folha" some (decisão 16)')]
    public function testSemOSegredoOSeloFica(): void
    {
        $this->assinar('ressalva', 'Faltou a batida do dia 5.');

        $tela = $this->tela();

        $this->assertSelo($tela, 'Ressalva em 09/11/2026', 'pe-folha-aceite pe-folha-aceite--ressalva');
        $this->assertSemAssinar($tela);
    }

    #[TestDox('Mês aberto: sem selo e sem "Assinar folha"')]
    public function testMesAberto(): void
    {
        $tela = $this->tela('2026-11');

        self::assertCount(0, $tela->filter('.pe-folha-aceite'));
        $this->assertSemAssinar($tela);
    }

    #[TestDox('Ficha do gestor (decisão 10): só o selo do aceite, sem o "Assinar folha" nem a janela')]
    public function testFichaDoGestorSoComOSelo(): void
    {
        $this->assinar();
        $gestora = $this->pessoa('Gestora');
        $this->vincular($gestora, $this->escritorio, [], true);
        $this->logarComTenant($this->client, $gestora, $this->escritorio);
        $this->em()->clear();

        $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?competencia=2026-10', $this->escritorio->getId(), $this->maria->getId()));

        self::assertResponseIsSuccessful();
        $barra = '#tab-ponto > section.pe-folha[data-screen-label="Folha de Ponto"] > div.pe-folha-barra';
        self::assertSame('Assinada em 09/11/2026', trim($ficha->filter($barra . ' > span.pe-folha-aceite')->text()));
        self::assertSame(['Ver PDF', 'Baixar XLSX', 'Baixar PDF'], $ficha->filter($barra . ' > button.pe-folha-btn')->each(static fn (Crawler $no): string => trim($no->text())));
        $this->assertSemAssinar($ficha);
        self::assertCount(1, $ficha->filter('#tab-ponto > div.pe-folha.pe-folha-janelas > #ponto-janela-ver-pdf'), 'o "Ver PDF" da ficha usa a mesma janela');
    }

    #[TestDox('Os valores do selo (L145) e os textos do aviso flutuante e da janela (L266, L717 a L730, L809) no CSS e no JS')]
    public function testValoresDoSeloEOsTextosDoAviso(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/ponto-eletronico.css');
        $e16 = substr($css, (int) strpos($css, '/* --- E16: folha, aceite mensal e Ver PDF'));
        foreach (['display: inline-flex;', 'gap: 5px;', 'height: 24px;', 'padding: 0 8px;', 'border-radius: 3px;', 'background: #1e7b45;', 'font-size: 12px;', 'font-weight: bold;'] as $valor) {
            self::assertStringContainsString($valor, (string) strstr($e16, '.pe-folha-aceite--ressalva', true), 'selo: ' . $valor);
        }
        self::assertStringContainsString('.pe-folha-aceite--ressalva { background: #8a6200; }', $e16);
        self::assertStringContainsString('.pe-folha-aceite--alterada { background: #a3232b; }', $e16);
        self::assertStringContainsString('.pe-folha-janelas > .pe-sf-aviso--info { background: #0a6fa0; }', $e16);
        self::assertStringContainsString('.pe-folha-janelas > .pe-sf-aviso--erro { background: #a3232b; }', $e16);

        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-folha.js');
        foreach ([
            "const AVISO_MS = 3400;",
            "'Gerando a folha em PDF...'",
            "'Folha baixada em PDF.'",
            "'Folha baixada em PDF. Código ' + codigo + '.'",
            "'Não foi possível gerar o PDF.'",
            "'Muitos pedidos de folha em sequência. Aguarde um minuto e tente de novo.'",
            "'Descreva a ressalva.'",
            "resposta.headers.get('X-Codigo-Folha')",
            'resposta.status === 429',
            'new AbortController()',
        ] as $trecho) {
            self::assertStringContainsString($trecho, $js);
        }
    }

    /** O aceite gravado pelo cenário leva o segredo mesmo no teste em que o servidor fica sem ele. */
    private function seloDaFolha(): SeloDaFolha
    {
        return new SeloDaFolhaHmac(new ChavesDoHmacDoPonto(self::SEGREDO));
    }

    private function assinar(string $situacao = 'concordo', string $ressalva = ''): void
    {
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, $situacao, $ressalva), $this->maria, $this->escritorio);
    }

    private function tela(string $competencia = '2026-10'): Crawler
    {
        $this->logarComTenant($this->client, $this->maria, $this->escritorio);
        $this->em()->clear();
        $tela = $this->client->request('GET', '/ponto/?competencia=' . $competencia);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** Envia o formulário da janela como o navegador (com o token da página) e segue para a /ponto. */
    private function enviarPelaJanela(string $situacao, string $ressalva): Crawler
    {
        $formulario = $this->tela()->filter(self::JANELA)->form(['situacao' => $situacao, 'ressalva' => $ressalva]);
        $this->client->submit($formulario);
        self::assertResponseRedirects('/ponto/?competencia=2026-10');
        $this->em()->clear();
        $tela = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function assertSelo(Crawler $tela, string $texto, string $classes): void
    {
        $selo = $tela->filter(self::SELO);
        self::assertCount(1, $selo, 'o selo é filho direto da barra');
        self::assertSame($texto, trim($selo->text()));
        self::assertSame($classes, $selo->attr('class'));
        self::assertCount(1, $selo->filter('i.bi.bi-pen:first-child'));
        self::assertCount(1, $tela->filter(self::BARRA . ' > select.pe-folha-mes + span.pe-folha-aceite'), 'logo depois da lista do mês (L145)');
    }

    private function assertSemAssinar(Crawler $tela): void
    {
        self::assertCount(0, $tela->filter('[data-folha-assinar]'));
        self::assertCount(0, $tela->filter('#ponto-janela-aceite'));
    }
}
