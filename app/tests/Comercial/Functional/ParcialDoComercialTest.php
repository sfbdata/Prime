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
 * O arranjo do bloco "Nova campanha de e-mail" no parcial do /master contra o desenho 05 (dc L.229-260), com
 * combinador de filho direto: a posição antes das Automações, a grade em duas colunas (1 · MODELO, 2 · PARA QUEM e
 * 3 · MENSAGEM à esquerda; PRÉVIA, destinatários, Frequência e a linha de "Agendar" à direita), a ordem dos botões e os
 * textos literais. Fatia 2b: os 6 públicos, a Frequência, "Agendar" e "Enviar campanha", e o Monitoramento depois das
 * Automações (dc L.282-286).
 */
#[CoversNothing]
final class ParcialDoComercialTest extends KernelTestCase
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

    #[TestDox('A campanha vem antes das Automações, como no dc (L.229 e L.262), com o cabeçalho literal')]
    public function testPosicaoECabecalho(): void
    {
        self::assertSame(['mc-cp-titulo', 'mc-auto-titulo', 'mc-mon-titulo'], $this->doc->filter('[data-mc] > section')->each(static fn (Crawler $c): string => (string) $c->attr('aria-labelledby')));
        self::assertSame(1, $this->doc->filter('section.mc-cp > .mc-cabeca > i.bi-megaphone')->count());
        self::assertSame(['Nova campanha de e-mail'], $this->textos('section.mc-cp > .mc-cabeca > b'));
        self::assertSame(['convites, ofertas, agradecimentos e novidades · individual ou em grande escala'], $this->textos('section.mc-cp > .mc-cabeca > span'));
    }

    #[TestDox('Duas colunas na grade (L.231): 1 · MODELO, 2 · PARA QUEM e 3 · MENSAGEM à esquerda, PRÉVIA DO E-MAIL à direita')]
    public function testDuasColunas(): void
    {
        self::assertSame(2, $this->doc->filter('section.mc-cp > .mc-cp-grade > .mc-cp-col')->count());
        self::assertSame(['1 · MODELO', '2 · PARA QUEM', '3 · MENSAGEM'], $this->textos('section.mc-cp > .mc-cp-grade > .mc-cp-editor > .mc-cp-passo'));
        self::assertSame(['PRÉVIA DO E-MAIL'], $this->textos('section.mc-cp > .mc-cp-grade > .mc-cp-lado > .mc-cp-passo'));
        self::assertSame('mc-cp-col mc-cp-editor', $this->doc->filter('section.mc-cp > .mc-cp-grade > .mc-cp-col')->eq(0)->attr('class'));
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/master-comercial.css');
        self::assertStringContainsString('.mc-cp-grade { padding: 16px 18px; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr)); gap: 18px; align-items: start; }', $css, 'os 340px do dc, sem passar da caixa em tela estreita');
    }

    #[TestDox('As ações do modelo na ordem do dc (L.235); Salvar e Excluir só para o próprio')]
    public function testAcoesDoModelo(): void
    {
        self::assertSame(['Novo modelo', 'Duplicar', 'Importar HTML', 'Salvar modelo', 'Excluir'], $this->textos('.mc-cp-editor > .mc-cp-acoes > .mc-cp-acao'));
        self::assertSame(2, $this->doc->filter('.mc-cp-editor > .mc-cp-acoes > [data-mc-cp-so-proprio][hidden]')->count());
        self::assertSame('.html,.htm', $this->doc->filter('.mc-cp-acoes > label > input[type="file"]')->attr('accept'));
    }

    #[TestDox('Os campos da mensagem (L.242-247) com os rótulos e as dicas literais')]
    public function testCamposDaMensagem(): void
    {
        $rotulos = $this->doc->filter('.mc-cp-editor > label.mc-cp-campo')->each(static fn (Crawler $c): string => trim((string) $c->getNode(0)?->firstChild?->textContent));
        self::assertSame(['ASSUNTO', 'TÍTULO', 'NOME DO MODELO', 'HTML IMPORTADO'], $rotulos);
        self::assertSame(['use {nome} para o primeiro nome'], $this->textos('[data-mc-cp-simples] > label > .mc-cp-dica'));
        self::assertSame(['monte o layout; a prévia atualiza na hora'], $this->textos('[data-mc-cp-blocos] > .mc-cp-blocos-titulo > .mc-cp-dica'));
    }

    #[TestDox('A prévia é um iframe sandbox=""; a linha L.254 traz Agendar, Baixar HTML, Enviar teste e Enviar campanha, nesta ordem')]
    public function testPreviaEBotoes(): void
    {
        $quadro = $this->doc->filter('.mc-cp-lado > iframe.mc-cp-previa');
        self::assertSame(['', 'Prévia do e-mail'], [$quadro->attr('sandbox'), $quadro->attr('title')]);
        self::assertSame(['Baixar HTML', 'Enviar teste', 'Enviar campanha'], $this->textos('.mc-cp-lado > .mc-cp-botoes > button'));
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > button > i.bi-filetype-html')->count());
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > button > i.bi-send-check')->count());
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > button.mc-cp-enviar > i.bi-send')->count());
        $filhos = $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > *')->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        self::assertSame(['mc-cp-agenda', 'mc-cp-vao', 'mc-cp-botao', 'mc-cp-botao', 'mc-cp-enviar'], $filhos);
        self::assertStringStartsWith('LGPD: só recebe quem se cadastrou ou autorizou.', $this->textos('.mc-cp-lado > .mc-cp-lgpd')[0]);
    }

    #[TestDox('2 · PARA QUEM (L.237-239): os 6 públicos do dc em 2 colunas, com ícone, rótulo, descrição e número; o CSV e o Individual escondidos')]
    public function testPublicos(): void
    {
        $botoes = '.mc-cp-editor > .mc-cp-publicos > button.mc-cp-publico';
        self::assertSame(['leads', 'prim', 'sec', 'todos', 'csv', 'indiv'], $this->doc->filter($botoes)->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-cp-publico')));
        self::assertSame(['Base de contatos', 'Usuários primários', 'Usuários secundários', 'Todos os cadastrados', 'Importar lista (CSV)', 'Individual'], $this->textos($botoes . ' > .mc-cp-publico-texto > .mc-cp-publico-rot'));
        self::assertSame([
            'Inscritos pela página de vagas e campanhas', 'Quem abriu conta, em todas as contas', 'Convidados de todas as contas',
            'Primários e secundários', 'Planilha com e-mail e nome', 'Digite um ou mais e-mails',
        ], $this->textos($botoes . ' > .mc-cp-publico-texto > .mc-cp-publico-desc'));
        self::assertSame(['bi-person-lines-fill', 'bi-person-badge', 'bi-people', 'bi-buildings', 'bi-filetype-csv', 'bi-person'], $this->doc->filter($botoes . ' > i')->each(static fn (Crawler $c): string => trim(str_replace('bi ', '', (string) $c->attr('class')))));
        self::assertSame(6, $this->doc->filter($botoes . ' > b.mc-cp-publico-n')->count());
        self::assertSame('true', $this->doc->filter($botoes)->eq(0)->attr('aria-pressed'), 'Base de contatos é o padrão (dc L.530)');
        // A ordem dos filhos da coluna da esquerda: o público vem entre as ações do modelo e 3 · MENSAGEM (L.235-241).
        $ordem = $this->doc->filter('.mc-cp-grade > .mc-cp-editor > *')->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        self::assertSame(['mc-cp-passo', 'mc-cp-modelos', 'mc-cp-acoes', 'mc-cp-passo mc-cp-passo-seguinte', 'mc-cp-publicos', 'mc-cp-csv', 'mc-cp-individual', 'mc-cp-passo mc-cp-passo-seguinte'], \array_slice($ordem, 0, 8));
        self::assertSame(['Selecionar arquivo .csv'], $this->textos('.mc-cp-editor > label.mc-cp-csv[hidden] > .mc-cp-csv-txt'));
        self::assertSame('.csv,.txt', $this->doc->filter('.mc-cp-editor > label.mc-cp-csv > input[type="file"]')->attr('accept'));
        self::assertSame('E-mails separados por vírgula', $this->doc->filter('.mc-cp-editor > textarea.mc-cp-individual[hidden]')->attr('placeholder'));
        $css = $this->css();
        self::assertStringContainsString('.mc-cp-publicos { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }', $css);
    }

    #[TestDox('Coluna da direita (L.250-256): prévia, destinatários, Frequência com as 4 opções do dc, a linha de Agendar, a mensagem e a LGPD')]
    public function testColunaDaDireita(): void
    {
        $ordem = $this->doc->filter('.mc-cp-grade > .mc-cp-lado > *')->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        self::assertSame(['mc-cp-passo', 'mc-cp-previa', 'mc-cp-dest', 'mc-cp-freq', 'mc-cp-botoes', 'mc-cp-msg', 'mc-cp-lgpd'], $ordem);
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-dest > i.bi-people')->count());
        self::assertSame(['Frequência'], $this->textos('.mc-cp-lado > .mc-cp-freq > .mc-cp-freq-rot'));
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-freq > .mc-cp-freq-rot > i.bi-arrow-repeat')->count());
        self::assertSame(['Envio único', 'Semanal', 'Quinzenal', 'Mensal'], $this->textos('.mc-cp-lado > .mc-cp-freq > .mc-cp-freq-ops > button'));
        self::assertSame(['Agendar'], $this->textos('.mc-cp-lado > .mc-cp-botoes > label.mc-cp-agenda > span'));
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > label.mc-cp-agenda > i.bi-calendar-event')->count());
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > label.mc-cp-agenda > input[type="datetime-local"]')->count());
        $css = $this->css();
        self::assertStringContainsString('.mc-cp-enviar { height: 34px; padding: 0 16px; border: none; border-radius: 3px; background: var(--mc-cp-enviar-bg); color: #fff;', $css);
        self::assertStringContainsString('--mc-cp-enviar-bg: #0a6fa0; --mc-cp-enviar-hover: #0b5f86;', $css);
    }

    #[TestDox('Monitoramento (L.282-286) depois das Automações: cabeçalho literal e a lista que o JS preenche; as colunas da linha seguem o dc')]
    public function testMonitoramento(): void
    {
        self::assertSame(1, $this->doc->filter('[data-mc] > section.mc-mon > .mc-cabeca > i.bi-graph-up')->count());
        self::assertSame(['Monitoramento'], $this->textos('[data-mc] > section.mc-mon > .mc-cabeca > b'));
        self::assertSame(['entregas, aberturas, cliques e descadastros por campanha'], $this->textos('[data-mc] > section.mc-mon > .mc-cabeca > span'));
        self::assertSame(1, $this->doc->filter('[data-mc] > section.mc-mon > .mc-camps[data-mc-camps]')->count());
        $css = $this->css();
        self::assertStringContainsString('.mc-camp { display: grid; grid-template-columns: minmax(200px,2fr) 130px 90px 110px minmax(150px,1fr); align-items: center; gap: 10px; padding: 11px 18px;', $css);
        foreach (['--mc-st-fila-bg: #fff4d6; --mc-st-fila-tx: #9a5b00;', '--mc-st-agendada-bg: #efeaf8; --mc-st-agendada-tx: #5b3f9e;', '--mc-st-recorrente-bg: #e9f6ee; --mc-st-recorrente-tx: #1e7b45;'] as $cor) {
            self::assertStringContainsString($cor, $css);
        }
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-monitoramento.js');
        self::assertStringContainsString('<div class="mc-camps-vazio">Nenhuma campanha ainda.</div>', $js);
        self::assertStringContainsString('Métricas: aguarda servidor', $js);
    }

    #[TestDox('"Baixar HTML" pede a prévia sem o exemplo (sai sempre com "Dra. Ana" e teste@bluejus.com.br); a prévia na tela leva o primeiro destinatário')]
    public function testBaixarSemDestinatarioReal(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-campanha.js');
        self::assertSame(1, preg_match('/function baixar\(\) \{(.*?)\n  \}/s', $js, $baixar));
        self::assertStringContainsString('postar(ROTAS.urlPrevia, pedido())', $baixar[1]);
        self::assertStringNotContainsString('exemplo', $baixar[1]);
        self::assertSame(1, preg_match('/function atualizarPrevia\(\) \{(.*?)\n  \}/s', $js, $previa));
        self::assertStringContainsString('contagem.exemplo', $previa[1]);
    }

    private function css(): string
    {
        return (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/master-comercial.css');
    }

    #[TestDox('Sem script inline (CSP do /master): o bloco traz o próprio arquivo de JS')]
    public function testSemScriptInline(): void
    {
        $scripts = $this->doc->filter('script');
        self::assertSame(0, $scripts->reduce(static fn (Crawler $s): bool => $s->attr('src') === null)->count());
        self::assertSame(1, $this->doc->filter('script[src*="js/master/comercial-campanha.js"]')->count());
        self::assertSame(1, $this->doc->filter('script[src*="js/master/comercial-monitoramento.js"]')->count());
    }
}
