<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

/**
 * O arranjo do "Relatório como caixa de entrada" (F3d) no parcial do /master contra o desenho 05 VS 21.10.26
 * (L300-305 e L502-514), com combinador de filho direto: ele substitui o antigo Monitoramento depois das Automações, a
 * barra (selecionar, atualizar, busca, faixa), as 3 abas com os textos do dc e o visualizador (Voltar, Duplicar,
 * Cancelar envio, KPIs, HTML num iframe sandbox e DESTINATÁRIOS).
 */
#[CoversNothing]
final class ParcialDoRelatorioTest extends KernelTestCase
{
    private Crawler $doc;

    protected function setUp(): void
    {
        self::bootKernel();
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
        $html = static::getContainer()->get(Environment::class)->render('master/_comercial.html.twig');
        $this->doc = new Crawler('<!doctype html><html><body>' . $html . '</body></html>');
    }

    /** @return list<string> */
    private function textos(string $seletor): array
    {
        return $this->doc->filter($seletor)->each(static fn (Crawler $c): string => trim((string) preg_replace('/\s+/', ' ', $c->text())));
    }

    #[TestDox('O Relatório substitui o Monitoramento: é a última caixa do [data-mc], depois das Automações, e o Monitoramento sumiu')]
    public function testSubstituiOMonitoramento(): void
    {
        $caixas = $this->doc->filter('[data-mc] > section');
        self::assertSame(3, $caixas->count());
        self::assertSame('mc-auto-titulo', $caixas->eq(1)->attr('aria-labelledby'));
        self::assertNotNull($caixas->eq(2)->attr('data-mc-relatorio'));
        self::assertSame('Relatório de campanhas', $caixas->eq(2)->attr('aria-label'));
        self::assertSame(0, $this->doc->filter('[data-mc-monitoramento], section.mc-mon, [data-mc-camps]')->count());
        self::assertStringNotContainsString('Monitoramento', $this->doc->filter('[data-mc]')->text());
        self::assertSame(1, $this->doc->filter('link[href*="css/master-comercial-relatorio.css"]')->count());
        self::assertSame(1, $this->doc->filter('script[src*="js/master/comercial-relatorio.js"]')->count());
        self::assertSame(0, $this->doc->filter('script[src*="comercial-monitoramento.js"]')->count());
    }

    #[TestDox('As URLs e o token: a lista, o visualizador e o cancelar, com o marcador que o JS troca')]
    public function testUrls(): void
    {
        $rl = $this->doc->filter('[data-mc] > section.mc-rl');
        self::assertSame('/master/comercial/relatorio', $rl->attr('data-url-lista'));
        self::assertSame('/master/comercial/campanhas/9999999999', $rl->attr('data-url-campanha'));
        self::assertSame('/master/comercial/campanhas/9999999999/cancelar', $rl->attr('data-url-cancelar'));
        self::assertNotEmpty($rl->attr('data-token'));
    }

    #[TestDox('A barra (L301): selecionar, atualizar, a seleção, a busca "Buscar campanha ou e-mail" e a faixa, nessa ordem')]
    public function testBarra(): void
    {
        $filhos = $this->doc->filter('section.mc-rl > .mc-rl-barra > *')->each(static fn (Crawler $c): string => (string) ($c->attr('title') ?? $c->attr('class')));
        self::assertSame(['Selecionar', 'Atualizar', 'mc-rl-sel-n', 'mc-rl-vao', 'mc-rl-busca', 'mc-rl-faixa', 'Página anterior', 'Próxima página'], $filhos);
        self::assertSame(1, $this->doc->filter('section.mc-rl > .mc-rl-barra > label.mc-rl-busca > input[placeholder="Buscar campanha ou e-mail"]')->count());
        self::assertSame(['0 de 0'], $this->textos('section.mc-rl > .mc-rl-barra > .mc-rl-faixa'));
    }

    #[TestDox('As 3 abas (L302 e L700): rótulos, ícones e subtítulos do dc, Enviadas selecionada')]
    public function testAbas(): void
    {
        $abas = 'section.mc-rl > .mc-rl-abas > button.mc-rl-aba';
        self::assertSame(['env', 'ag', 'rec'], $this->doc->filter($abas)->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-rl-aba')));
        self::assertSame(['Enviadas', 'Agendadas', 'Recorrentes'], $this->textos($abas . ' > .mc-rl-aba-texto > .mc-rl-aba-rot'));
        self::assertSame(['Na fila e já enviadas', 'Com data programada', 'Semanais, quinzenais e mensais'], $this->textos($abas . ' > .mc-rl-aba-texto > .mc-rl-aba-sub'));
        self::assertSame(['bi bi-send', 'bi bi-clock', 'bi bi-arrow-repeat'], $this->doc->filter($abas . ' > i')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertSame(3, $this->doc->filter($abas . ' > .mc-rl-aba-texto > .mc-rl-aba-rot > .mc-rl-aba-n[data-mc-rl-n]')->count());
        self::assertSame(['true', 'false', 'false'], $this->doc->filter($abas)->each(static fn (Crawler $c): string => (string) $c->attr('aria-selected')));
        self::assertSame(1, $this->doc->filter('section.mc-rl > .mc-rl-lista[data-mc-rl-lista]')->count());
    }

    #[TestDox('O visualizador (L502-514): Voltar, Duplicar e Cancelar envio; KPIs; HTML em iframe sandbox ao lado de DESTINATÁRIOS')]
    public function testVisualizador(): void
    {
        $caixa = 'section.mc-rl > .mc-rl-ver > .mc-rl-ver-caixa';
        self::assertSame(1, $this->doc->filter('section.mc-rl > .mc-rl-ver[hidden]')->count());
        self::assertSame(['Voltar', 'mc-rl-vao', 'Duplicar', 'Cancelar envio'], $this->doc->filter($caixa . ' > .mc-rl-ver-barra > *')->each(static fn (Crawler $c): string => (string) ($c->attr('aria-label') ?? (trim($c->text()) !== '' ? trim($c->text()) : $c->attr('class')))));
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ver-barra > button[data-mc-rl-cancelar][hidden]')->count());
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ver-corpo > .mc-rl-ver-kpis[data-mc-rl-kpis]')->count());
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ver-corpo > .mc-rl-ver-grade > iframe.mc-rl-ver-html[sandbox=""]')->count());
        self::assertSame(['DESTINATÁRIOS'], $this->textos($caixa . ' > .mc-rl-ver-corpo > .mc-rl-ver-grade > .mc-rl-ver-dest > .mc-rl-ver-dest-titulo'));
    }

    #[TestDox('O JS: o contrato do Duplicar (evento mc:duplicar-campanha), o "Nenhuma campanha nesta aba." do dc e sem travessão no texto')]
    public function testJs(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-relatorio.js');
        self::assertStringContainsString("new CustomEvent('mc:duplicar-campanha', { cancelable: true, detail: detalhe })", $js);
        self::assertStringContainsString('Nenhuma campanha nesta aba.', $js);
        self::assertStringContainsString("'Cancelar o envio programado desta campanha?'", $js);
        self::assertSame(0, preg_match('/[—–]/u', $js));
        self::assertFileDoesNotExist(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-monitoramento.js');
    }
}
