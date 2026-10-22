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
 * Cancelar envio, KPIs, HTML num iframe sandbox e DESTINATÁRIOS). Da F3e (spec comercial-marcas.md): os botões da
 * seleção, as abas Com estrela e Arquivadas, Reenviar e Exportar no visualizador e o menu do botão direito.
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

    #[TestDox('O Relatório substitui o Monitoramento: é a última caixa do [data-mc], depois das Automações e das Datas comemorativas, e o Monitoramento sumiu')]
    public function testSubstituiOMonitoramento(): void
    {
        $caixas = $this->doc->filter('[data-mc] > section');
        // F4: as Datas comemorativas entre as Automações e o Relatório (dc 05 VS 21.10.26 L334).
        self::assertSame(4, $caixas->count());
        self::assertSame('mc-auto-titulo', $caixas->eq(1)->attr('aria-labelledby'));
        self::assertSame('mc-dt-titulo', $caixas->eq(2)->attr('aria-labelledby'));
        self::assertNotNull($caixas->eq(3)->attr('data-mc-relatorio'));
        self::assertSame('Relatório de campanhas', $caixas->eq(3)->attr('aria-label'));
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
        self::assertSame('/master/comercial/campanhas/999999999', $rl->attr('data-url-campanha'));
        self::assertSame('/master/comercial/campanhas/999999999/cancelar', $rl->attr('data-url-cancelar'));
        self::assertNotEmpty($rl->attr('data-token'));
        self::assertSame('/master/comercial/relatorio/marcas', $rl->attr('data-url-marcas'));
        self::assertSame('/master/comercial/campanhas/999999999/destinatarios.csv', $rl->attr('data-url-csv'));
    }

    #[TestDox('A barra (L301): selecionar, atualizar, Arquivar, Estrela, a lixeira, a seleção, a busca "Buscar campanha ou e-mail" e a faixa, nessa ordem')]
    public function testBarra(): void
    {
        $filhos = $this->doc->filter('section.mc-rl > .mc-rl-barra > *')->each(static fn (Crawler $c): string => (string) ($c->attr('title') ?? $c->attr('class')));
        self::assertSame(['Selecionar', 'Atualizar', 'Arquivar', 'Marcar com estrela', 'Remover do relatório', 'mc-rl-sel-n', 'mc-rl-vao', 'mc-rl-busca', 'mc-rl-faixa', 'Página anterior', 'Próxima página'], $filhos);
        $selecao = 'section.mc-rl > .mc-rl-barra > button[data-mc-rl-marcar][hidden]';
        self::assertSame(['arquivar', 'estrela', 'remover'], $this->doc->filter($selecao)->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-rl-marcar')));
        self::assertSame(['Arquivar', 'Estrela', ''], $this->textos($selecao));
        self::assertSame(['bi bi-archive', 'bi bi-star', 'bi bi-trash3'], $this->doc->filter($selecao . ' > i')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertSame(1, $this->doc->filter('section.mc-rl > .mc-rl-barra > label.mc-rl-busca > input[placeholder="Buscar campanha ou e-mail"]')->count());
        self::assertSame(['0 de 0'], $this->textos('section.mc-rl > .mc-rl-barra > .mc-rl-faixa'));
    }

    #[TestDox('As 5 abas (L302 e L700): rótulos, ícones e subtítulos do dc, Enviadas selecionada')]
    public function testAbas(): void
    {
        $abas = 'section.mc-rl > .mc-rl-abas > button.mc-rl-aba';
        self::assertSame(['env', 'ag', 'rec', 'est', 'arq'], $this->doc->filter($abas)->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-rl-aba')));
        self::assertSame(['Enviadas', 'Agendadas', 'Recorrentes', 'Com estrela', 'Arquivadas'], $this->textos($abas . ' > .mc-rl-aba-texto > .mc-rl-aba-rot'));
        self::assertSame(['Na fila e já enviadas', 'Com data programada', 'Semanais, quinzenais e mensais', 'Campanhas marcadas', 'Fora das listas'], $this->textos($abas . ' > .mc-rl-aba-texto > .mc-rl-aba-sub'));
        self::assertSame(['bi bi-send', 'bi bi-clock', 'bi bi-arrow-repeat', 'bi bi-star', 'bi bi-archive'], $this->doc->filter($abas . ' > i')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertSame(5, $this->doc->filter($abas . ' > .mc-rl-aba-texto > .mc-rl-aba-rot > .mc-rl-aba-n[data-mc-rl-n]')->count());
        self::assertSame(['true', 'false', 'false', 'false', 'false'], $this->doc->filter($abas)->each(static fn (Crawler $c): string => (string) $c->attr('aria-selected')));
        self::assertSame(1, $this->doc->filter('section.mc-rl > .mc-rl-lista[data-mc-rl-lista]')->count());
    }

    #[TestDox('O visualizador (L502-514): Voltar, Duplicar, Reenviar a quem não leu, Exportar destinatários e Cancelar envio; KPIs; HTML em iframe sandbox ao lado de DESTINATÁRIOS')]
    public function testVisualizador(): void
    {
        $caixa = 'section.mc-rl > .mc-rl-ver > .mc-rl-ver-caixa';
        self::assertSame(1, $this->doc->filter('section.mc-rl > .mc-rl-ver[hidden]')->count());
        self::assertSame(['Voltar', 'mc-rl-vao', 'Duplicar', 'Reenviar a quem não leu', 'Exportar destinatários', 'Cancelar envio'], $this->doc->filter($caixa . ' > .mc-rl-ver-barra > *')->each(static fn (Crawler $c): string => (string) ($c->attr('aria-label') ?? (trim($c->text()) !== '' ? trim($c->text()) : $c->attr('class')))));
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ver-barra > button[data-mc-rl-cancelar][hidden]')->count());
        self::assertSame(['bi bi-files', 'bi bi-arrow-repeat', 'bi bi-filetype-csv', 'bi bi-x-circle'], $this->doc->filter($caixa . ' > .mc-rl-ver-barra > button.mc-rl-ver-acao > i')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ver-barra > button[data-mc-rl-csv="completo"]')->count());
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ver-corpo > .mc-rl-ver-kpis[data-mc-rl-kpis]')->count());
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ver-corpo > .mc-rl-ver-grade > iframe.mc-rl-ver-html[sandbox=""]')->count());
        self::assertSame(['DESTINATÁRIOS'], $this->textos($caixa . ' > .mc-rl-ver-corpo > .mc-rl-ver-grade > .mc-rl-ver-dest > .mc-rl-ver-dest-titulo'));
    }

    #[TestDox('O menu do botão direito (L504 e L704): título, itens, ícones e separadores na ordem do dc; Excluir em vermelho')]
    public function testMenu(): void
    {
        $caixa = 'section.mc-rl > .mc-rl-ctx[hidden] > .mc-rl-ctx-caixa[role="menu"]';
        self::assertSame(1, $this->doc->filter('section.mc-rl > .mc-rl-ctx[hidden] > .mc-rl-ctx-fundo[data-mc-rl-ctx-fechar]')->count());
        $filhos = $this->doc->filter($caixa . ' > *')->each(static fn (Crawler $c): string => match ($c->attr('class')) {
            'mc-rl-ctx-sep' => '---',
            'mc-rl-ctx-titulo' => 'título',
            default => trim((string) preg_replace('/\s+/', ' ', $c->text())) . ' ' . (string) $c->filter('i')->attr('class'),
        });
        self::assertSame([
            'título',
            'Abrir campanha bi bi-envelope-open',
            'Duplicar como nova bi bi-files',
            'Reenviar a quem não leu bi bi-arrow-repeat',
            '---',
            'Encaminhar para um e-mail bi bi-forward',
            'Marcar com estrela bi bi-star',
            'Arquivar bi bi-archive',
            '---',
            'Exportar destinatários (CSV) bi bi-filetype-csv',
            '---',
            'Excluir do relatório bi bi-trash3',
        ], $filhos);
        self::assertSame(1, $this->doc->filter($caixa . ' > .mc-rl-ctx-titulo[data-mc-rl-ctx-titulo]:first-child')->count());
        self::assertSame(['abrir', 'duplicar', 'reenviar', 'estrela', 'arquivar', 'csv', 'excluir'], $this->doc->filter($caixa . ' > button[data-mc-rl-ctx-acao]')->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-rl-ctx-acao')));
        self::assertSame(1, $this->doc->filter($caixa . ' > button.mc-rl-ctx-excluir[data-mc-rl-ctx-acao="excluir"]')->count());
    }

    #[TestDox('"Encaminhar para um e-mail" está no menu, desabilitado, com o motivo no title e sem ação')]
    public function testEncaminharDesabilitado(): void
    {
        $item = $this->doc->filter('section.mc-rl > .mc-rl-ctx > .mc-rl-ctx-caixa > button.mc-rl-ctx-off');
        self::assertSame(1, $item->count());
        self::assertSame('Encaminhar para um e-mail', trim($item->text()));
        self::assertNotNull($item->attr('disabled'));
        self::assertSame('true', $item->attr('aria-disabled'));
        self::assertSame('Aguardando a decisão sobre enviar teste para outros endereços.', $item->attr('title'));
        self::assertNull($item->attr('data-mc-rl-ctx-acao'));
        self::assertSame(0, $this->doc->filter('[data-url-encaminhar]')->count());
    }

    #[TestDox('O JS: o contrato do Duplicar (evento mc:duplicar-campanha), os textos do dc pelo catálogo e sem travessão no texto')]
    public function testJs(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-relatorio.js');
        self::assertStringContainsString("raiz.dispatchEvent(new CustomEvent('mc:duplicar-campanha', { bubbles: true, cancelable: true, detail: detalhe }))", $js);
        foreach (["BJ_T.t('comercial_relatorio.js.vazia')", "BJ_T.t('comercial_relatorio.js.confirmar_cancelar')",
            "BJ_T.t('comercial_relatorio.js.confirmar_remover', { n: ids.length })", "BJ_T.t('comercial_relatorio.js.confirmar_excluir')",
            "BJ_T.t('comercial_relatorio.js.duplicada_menu')", "BJ_T.t('comercial_relatorio.js.reenvio_menu_sem_rastreio')",
            "BJ_T.t('comercial_relatorio.js.reenvio_sem_rastreio')", "BJ_T.t('comercial_relatorio.js.linha_dica')",
            "'bi-star-fill' : 'bi-star'", "estado.aba === 'arq' ? BJ_T.t('comercial_relatorio.js.desarquivar') : BJ_T.t('comercial_relatorio.js.arquivar')"] as $trecho) {
            self::assertStringContainsString($trecho, $js);
        }
        // Os textos do dc, agora no catálogo (bloco comercial_relatorio.js).
        foreach ([
            'vazia' => 'Nenhuma campanha nesta aba.',
            'confirmar_cancelar' => 'Cancelar o envio programado desta campanha?',
            'confirmar_remover' => 'Remover {n} campanha(s) do relatório?',
            'confirmar_excluir' => 'Excluir esta campanha do relatório?',
            'duplicada_menu' => 'Campanha duplicada: ajuste e envie.',
            'reenvio_menu_sem_rastreio' => 'Esta campanha não rastreou leitura.',
            'reenvio_sem_rastreio' => 'Esta campanha não rastreou leitura; não dá para saber quem não leu.',
            'linha_dica' => 'Abrir · botão direito para mais opções',
        ] as $chave => $texto) {
            self::assertSame($texto, static::getContainer()->get('translator')->trans('comercial_relatorio.js.' . $chave, [], null, 'pt_BR'), $chave);
        }
        self::assertStringNotContainsString('urlEncaminhar', $js, 'o Encaminhar não envia: sem rota nem ação no JS');
        self::assertStringNotContainsString("'encaminhar'", $js);
        self::assertStringNotContainsString('prompt(', $js);
        self::assertSame(0, preg_match('/[—–]/u', $js));
        self::assertFileDoesNotExist(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-monitoramento.js');
    }

    #[TestDox('O catálogo do JS vem uma vez, antes do script, só com as chaves comercial_relatorio.js')]
    public function testCatalogoDoJs(): void
    {
        // O da seção (comercial.js, no topo do _comercial) é outro bloco; aqui só o do Relatório.
        $catalogo = $this->doc->filter('script[type="application/json"][data-bj-t]')->reduce(static fn (Crawler $c): bool => str_contains($c->text(), 'comercial_relatorio.js.'));
        self::assertSame(1, $catalogo->count());
        self::assertSame(1, $this->doc->filter('script[data-bj-t] + script[src*="js/master/comercial-relatorio.js"]')->count());
        $chaves = array_keys((array) json_decode($catalogo->text(), true, flags: JSON_THROW_ON_ERROR));
        self::assertNotSame([], $chaves);
        self::assertSame([], array_values(array_filter($chaves, static fn (string $c): bool => !str_starts_with($c, 'comercial_relatorio.js.'))));
    }
}
