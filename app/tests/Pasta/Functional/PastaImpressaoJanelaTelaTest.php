<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * EDT-001/PAS-015: a janela "Imprimir" na própria tela da pasta (desenho 1.2.3, marcação `imp`
 * ~L.195). O que se prova é ARRANJO, com filho direto: cabeçalho azul em cima, a folha à esquerda
 * e o painel de opções à direita, na ordem do desenho, e o contrato de URL com o JS. A folha em si
 * (o que entra, o que some) é do `PastaImpressaoFragmentoControllerTest`; aparência e o diálogo de
 * impressão do navegador são do smoke do dono.
 */
#[Group('pasta')]
final class PastaImpressaoJanelaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    /** @return list<string> */
    private function textos(Crawler $nos): array
    {
        return $nos->each(static fn (Crawler $n): string => trim((string) preg_replace('/\s+/u', ' ', $n->text(''))));
    }

    #[TestDox('A janela nasce escondida, com a URL da folha desta pasta, e o "Imprimir resumo" do ⋮ aponta para ela')]
    public function testJanelaEContratoDeUrl(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $janela = $crawler->filter('#psImpressao.psi-fundo');
        self::assertCount(1, $janela);
        self::assertNotNull($janela->attr('hidden'), 'fechada até alguém pedir para imprimir');
        self::assertSame('/pasta/' . $pasta->getId() . '/resumo/imprimir', $janela->attr('data-url-folha'));

        // O JS reconhece o item do ⋮ pelo caminho: ele tem de ser o MESMO da janela.
        $item = $crawler->filter('#psMenuAcoes > a.ps-pop-item[href]')->reduce(
            static fn (Crawler $a): bool => str_contains($a->text(), 'Imprimir resumo'),
        );
        self::assertCount(1, $item);
        self::assertSame($janela->attr('data-url-folha'), parse_url((string) $item->attr('href'), \PHP_URL_PATH));

        self::assertCount(1, $crawler->filter('script[src*="js/pasta-impressao.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/pasta-impressao.css"]'));
    }

    #[TestDox('Arranjo do desenho: cabeçalho "Imprimir" com Fechar; a folha à esquerda e as opções à direita, na ordem do desenho')]
    public function testArranjoDoDesenho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $dialogo = $crawler->filter('#psImpressao > .psi-janela[role="dialog"]');
        self::assertCount(1, $dialogo);
        self::assertSame('psImpressaoTitulo', $dialogo->attr('aria-labelledby'));

        self::assertCount(1, $dialogo->filter('.psi-janela > .psi-cab > i.bi-printer + #psImpressaoTitulo + button.js-psi-fechar[aria-label="Fechar"]'));
        self::assertSame('Imprimir', trim($dialogo->filter('#psImpressaoTitulo')->text()));

        // A folha (palco cinza) vem ANTES do painel de opções: à esquerda.
        self::assertCount(1, $dialogo->filter('.psi-janela > .psi-corpo > #psImpressaoPalco.psi-palco + .psi-lado'));

        $lado = '.psi-janela > .psi-corpo > .psi-lado';
        self::assertSame(['Incluir na folha', 'Tamanho do texto'], $this->textos($dialogo->filter($lado . ' > .psi-rotulo')));

        $opcoes = $dialogo->filter($lado . ' > button.js-psi-opcao');
        self::assertSame(
            ['Cabeçalho do escritório', 'Dados da pasta e do processo', 'Autor, data e tipo do registro', 'Financeiro'],
            $this->textos($opcoes),
        );
        self::assertSame(
            ['cabecalho', 'dados', 'autor', 'financeiro'],
            $opcoes->each(static fn (Crawler $b): string => (string) $b->attr('data-opcao')),
        );
        // Padrões do desenho (`gmImprimir`): três ligadas, Financeiro desligado — e escondido até a
        // folha do servidor provar que quem está vendo pode ver o financeiro.
        self::assertSame(['true', 'true', 'true', 'false'], $opcoes->each(static fn (Crawler $b): string => (string) $b->attr('aria-pressed')));
        self::assertNotNull($opcoes->last()->attr('hidden'));

        $tamanho = $dialogo->filter($lado . ' > .psi-tamanho');
        self::assertCount(1, $tamanho->filter('.psi-tamanho > button.js-psi-menor[aria-label="Diminuir"] + #psImpressaoFonte + button.js-psi-maior[aria-label="Aumentar"]'));
        self::assertSame('11,5 pt', trim($tamanho->filter('#psImpressaoFonte')->text()));

        self::assertSame(
            'Papel A4. Para gerar PDF, escolha "Salvar como PDF" na janela de impressão.',
            trim($dialogo->filter($lado . ' > .psi-nota')->text()),
        );
        self::assertSame(
            ['Imprimir', 'Copiar texto'],
            $this->textos($dialogo->filter($lado . ' > .psi-imprimir, ' . $lado . ' > .psi-copiar')),
        );
        self::assertCount(1, $dialogo->filter($lado . ' > .psi-nota + .psi-imprimir'), 'a nota fica logo acima do Imprimir');
    }
}
