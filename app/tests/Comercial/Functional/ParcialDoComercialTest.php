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
 * textos literais. Fatia 2b: os 6 públicos, a Frequência, "Agendar" e "Enviar campanha". O antigo Monitoramento deu
 * lugar ao Relatório (F3d), depois das Automações; o arranjo dele está no ParcialDoRelatorioTest.
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

    #[TestDox('F3b: "Filtrar destinatários" logo depois dos públicos (dc 05 VS 21.10.26, L260-264): busca, 7 atalhos e 8 selects com os textos do dc')]
    public function testFiltrarDestinatarios(): void
    {
        $editor = 'section.mc-cp > .mc-cp-grade > .mc-cp-editor';
        $filhos = $this->doc->filter($editor . ' > *')->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        $depoisDosPublicos = $filhos[array_search('mc-cp-publicos', $filhos, true) + 1] ?? null;
        self::assertSame('mc-cf', $depoisDosPublicos, 'o filtro vem logo abaixo da grade de públicos');
        self::assertSame(['FILTRAR DESTINATÁRIOS'], $this->textos($editor . ' > .mc-cf > .mc-cf-cabeca > .mc-cf-titulo'));
        self::assertSame('E-mail ou nome específico (separe vários por vírgula)', $this->doc->filter($editor . ' > .mc-cf > .mc-cf-painel > .mc-cf-busca > input[data-mc-cf-campo="q"]')->attr('placeholder'));
        self::assertSame(['Todos', 'Só mulheres', 'Só homens', 'Advogados', 'Estagiários', 'Primários', 'Aniversariantes do mês'], $this->textos($editor . ' > .mc-cf > .mc-cf-painel > .mc-cf-atalhos > .mc-cf-atalho'));
        $selects = $editor . ' > .mc-cf > .mc-cf-painel > .mc-cf-selects > select';
        self::assertSame(['gen', 'id', 'uf', 'papel', 'cargo', 'oab', 'plano', 'aniv'], $this->doc->filter($selects)->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-cf-campo')));
        self::assertSame(['Gênero: todos', 'Idade: todas', 'Estado: todos', 'Perfil: todos', 'Cargo: todos', 'OAB: todas', 'Plano: todos', 'Aniversário: todos'], $this->textos($selects . ' > option:first-child'));
        self::assertCount(28, $this->doc->filter($selects . '[data-mc-cf-campo="uf"] > option'), 'as 27 UFs e o "todos"');
        self::assertSame(['', 'adv', 'est', 'ctl', 'fin', 'adm', 'out'], $this->doc->filter($selects . '[data-mc-cf-campo="cargo"] > option')->each(static fn (Crawler $c): string => (string) $c->attr('value')));
        $planos = $this->doc->filter($selects . '[data-mc-cf-campo="plano"] > option')->each(static fn (Crawler $c): string => (string) $c->attr('value'));
        self::assertContains('free', $planos, 'D4: o catálogo, em código');
        self::assertContains('essencial', $planos);
        self::assertSame('Limpar filtro', trim($this->doc->filter($editor . ' > .mc-cf > .mc-cf-painel > .mc-cf-resultado > .mc-cf-limpar')->text()));
    }

    /** @return list<string> */
    private function textos(string $seletor): array
    {
        return $this->doc->filter($seletor)->each(static fn (Crawler $c): string => trim((string) preg_replace('/\s+/', ' ', $c->text())));
    }

    #[TestDox('F3a: a Central vem antes das Automações, com o cabeçalho do dc 21.10 (L251) e o botão "Descadastrados (N)"')]
    public function testPosicaoECabecalho(): void
    {
        self::assertSame(['mc-cp-titulo', 'mc-auto-titulo', 'relatorio'], $this->doc->filter('[data-mc] > section')->each(static fn (Crawler $c): string => $c->attr('data-mc-relatorio') !== null ? 'relatorio' : (string) $c->attr('aria-labelledby')));
        self::assertSame(1, $this->doc->filter('section.mc-cp > .mc-cabeca > i.bi-megaphone')->count());
        self::assertSame(['Central de envios · nova campanha'], $this->textos('section.mc-cp > .mc-cabeca > b'));
        self::assertSame(['Descadastrados (0)'], $this->textos('section.mc-cp > .mc-cabeca > button.mc-cp-descad'));
        self::assertSame(1, $this->doc->filter('section.mc-cp > .mc-cabeca > button.mc-cp-descad > i.bi-slash-circle')->count());
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

    #[TestDox('As ações do modelo (dc 21.10 L256): no fixo, "Salvar como meu modelo"; no próprio, Salvar e Excluir')]
    public function testAcoesDoModelo(): void
    {
        self::assertSame(['Novo modelo', 'Duplicar', 'Importar HTML', 'Salvar como meu modelo', 'Salvar modelo', 'Excluir'], $this->textos('.mc-cp-editor > .mc-cp-acoes > .mc-cp-acao'));
        self::assertSame(2, $this->doc->filter('.mc-cp-editor > .mc-cp-acoes > [data-mc-cp-so-proprio][hidden]')->count());
        self::assertSame(1, $this->doc->filter('.mc-cp-editor > .mc-cp-acoes > [data-mc-cp-so-fixo][data-mc-cp-salvar-como]')->count());
        self::assertSame('.html,.htm', $this->doc->filter('.mc-cp-acoes > label > input[type="file"]')->attr('accept'));
    }

    #[TestDox('F3a: Selo e Nome na prévia entre Assunto e Título (dc 21.10 L270); os 10 emojis no texto simples (L274), sem a imagem (com o dono)')]
    public function testSeloNomeEEmojis(): void
    {
        $editor = '.mc-cp-grade > .mc-cp-editor';
        $filhos = $this->doc->filter($editor . ' > *')->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        $par = array_search('mc-cp-par', $filhos, true);
        self::assertNotFalse($par);
        self::assertSame(['SELO (ACIMA DO TÍTULO)', 'NOME NA PRÉVIA substitui {nome} só aqui'], array_map(static fn (string $t): string => trim($t), $this->doc->filter($editor . ' > .mc-cp-par > label')->each(static fn (Crawler $c): string => trim((string) preg_replace('/\s+/', ' ', $c->text())))));
        self::assertSame(['Ex.: CONVITE · BLUEJUS', 'Dra. Ana'], $this->doc->filter($editor . ' > .mc-cp-par > label > input')->each(static fn (Crawler $c): string => (string) $c->attr('placeholder')));
        self::assertStringStartsWith('ASSUNTO', $this->doc->filter($editor . ' > *')->eq($par - 1)->text());
        self::assertStringStartsWith('TÍTULO', $this->doc->filter($editor . ' > *')->eq($par + 1)->text());
        self::assertSame(['⚖️', '📅', '✅', '📌', '🎉', '💼', '📈', '🔒', '👉', '🙏'], $this->textos($editor . ' > [data-mc-cp-simples] > .mc-cp-emojis > .mc-cp-emoji'));
        self::assertSame(0, $this->doc->filter('input[type="file"][accept="image/*"]')->count(), 'a imagem do modo simples está com o dono');
    }

    #[TestDox('Os campos da mensagem (L.242-247) com os rótulos e as dicas literais')]
    public function testCamposDaMensagem(): void
    {
        $rotulos = $this->doc->filter('.mc-cp-editor > label.mc-cp-campo')->each(static fn (Crawler $c): string => trim((string) $c->getNode(0)?->firstChild?->textContent));
        self::assertSame(['ASSUNTO', 'TÍTULO', 'NOME DO MODELO', 'HTML IMPORTADO'], $rotulos);
        self::assertSame(['use {nome} para o primeiro nome'], $this->textos('[data-mc-cp-simples] > label > .mc-cp-dica'));
        self::assertSame(['monte o layout; a prévia atualiza na hora'], $this->textos('[data-mc-cp-blocos] > .mc-cp-blocos-titulo > .mc-cp-dica'));
    }

    #[TestDox('F3a: a barra do dc 21.10 (L287-292): Enviar com a seta do "Programar envio", os ícones Ver, Teste e Baixar, e a LGPD na dica do escudo')]
    public function testPreviaEBotoes(): void
    {
        $quadro = $this->doc->filter('.mc-cp-lado > iframe.mc-cp-previa');
        self::assertSame(['', 'Prévia do e-mail'], [$quadro->attr('sandbox'), $quadro->attr('title')]);
        $barra = '.mc-cp-lado > .mc-cp-barra';
        self::assertSame(['mc-cp-enviar-grupo', 'mc-cp-vao', 'mc-cp-icone', 'mc-cp-icone', 'mc-cp-icone', 'mc-cp-vao', 'mc-cp-lgpd', 'mc-cp-programar'], $this->doc->filter($barra . ' > *')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertSame(['Enviar'], $this->textos($barra . ' > .mc-cp-enviar-grupo > button[data-mc-cp-enviar]'));
        self::assertSame('Programar envio e frequência', $this->doc->filter($barra . ' > .mc-cp-enviar-grupo > button[data-mc-cp-mais]')->attr('title'));
        self::assertSame(['Ver como vai chegar', 'Enviar teste', 'Baixar HTML'], $this->doc->filter($barra . ' > .mc-cp-icone')->each(static fn (Crawler $c): string => (string) $c->attr('title')));
        self::assertSame(['bi bi-eye', 'bi bi-send-check', 'bi bi-download'], $this->doc->filter($barra . ' > .mc-cp-icone > i')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertStringStartsWith('LGPD: só recebe quem se cadastrou ou autorizou.', (string) $this->doc->filter($barra . ' > .mc-cp-lgpd')->attr('title'));
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
        self::assertSame(['mc-cp-passo', 'mc-cp-modelos', 'mc-cp-acoes', 'mc-cp-passo mc-cp-passo-seguinte', 'mc-cp-publicos', 'mc-cf', 'mc-cp-csv', 'mc-cp-individual', 'mc-cp-passo mc-cp-passo-seguinte'], \array_slice($ordem, 0, 9));
        self::assertSame(['Selecionar arquivo .csv'], $this->textos('.mc-cp-editor > label.mc-cp-csv[hidden] > .mc-cp-csv-txt'));
        self::assertSame('.csv,.txt', $this->doc->filter('.mc-cp-editor > label.mc-cp-csv > input[type="file"]')->attr('accept'));
        self::assertSame('E-mails separados por vírgula', $this->doc->filter('.mc-cp-editor > textarea.mc-cp-individual[hidden]')->attr('placeholder'));
        $css = $this->css();
        self::assertStringContainsString('.mc-cp-publicos { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }', $css);
    }

    #[TestDox('F3a: coluna da direita (dc 21.10 L282-293): prévia, destinatários com o relógio, a barra e o PROGRAMAR ENVIO com o REPETIR')]
    public function testColunaDaDireita(): void
    {
        $ordem = $this->doc->filter('.mc-cp-grade > .mc-cp-lado > *')->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        self::assertSame(['mc-cp-passo', 'mc-cp-previa', 'mc-cp-dest', 'mc-cp-barra', 'mc-cp-msg'], $ordem);
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-dest > i.bi-people')->count());
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-dest > .mc-cp-ag[hidden] > i.bi-clock')->count());
        $programar = '.mc-cp-lado > .mc-cp-barra > .mc-cp-programar';
        self::assertSame(['PROGRAMAR ENVIO', 'REPETIR'], $this->textos($programar . ' > .mc-cp-programar-rot'));
        self::assertSame(1, $this->doc->filter($programar . ' > input[type="datetime-local"][data-mc-cp-agenda]')->count());
        self::assertSame(['Envio único', 'Semanal', 'Quinzenal', 'Mensal'], $this->textos($programar . ' > .mc-cp-freq-ops > button'));
        self::assertSame(['Concluir'], $this->textos($programar . ' > button.mc-cp-concluir'));
        $css = $this->css();
        self::assertStringContainsString('--mc-cp-enviar-bg: #0a6fa0; --mc-cp-enviar-hover: #0b5f86;', $css);
    }

    #[TestDox('F3a: "Como o destinatário vai receber" (dc 21.10 L499-501): computador e celular, o remetente real (com o dono) e o quadro sem scripts')]
    public function testVerComoVaiChegar(): void
    {
        $janela = 'section.mc-cp > .mc-cp-ver[hidden] > .mc-cp-ver-caixa';
        self::assertSame(['Como o destinatário vai receber'], $this->textos($janela . ' > .mc-cp-ver-cabeca > b'));
        self::assertSame(['Computador', 'Celular'], $this->textos($janela . ' > .mc-cp-ver-cabeca > .mc-cp-ver-dev > button'));
        self::assertSame(['B'], $this->textos($janela . ' > .mc-cp-ver-env > .mc-cp-ver-av'));
        self::assertStringContainsString('<', $this->textos($janela . ' > .mc-cp-ver-env > .mc-cp-ver-info [data-mc-cp-ver-de]')[0], 'o remetente real, "Nome <endereço>"');
        self::assertSame('', $this->doc->filter($janela . ' > .mc-cp-ver-quadro > iframe')->attr('sandbox'));
        self::assertStringContainsString('.mc-cp-ver-quadro[data-dev="cel"] > iframe { width: 375px; height: 640px; }', $this->css());
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
        self::assertSame(1, $this->doc->filter('script[src*="js/master/comercial-relatorio.js"]')->count());
    }
}
