<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Janela do alerta de horário do ponto (E8, pedido do coordenador): o alerta que aparece em TODA página
 * (`_modal_alerta_ponto`, incluído no base.html.twig) sai do modal do Bootstrap (cabeçalho amarelo) para a moldura de
 * janela do desenho 16 (L269 a L273, a das janelas da justificativa, e o corpo da janela de alerta, L288), com os
 * mesmos textos e funções. Fora da /ponto o ponto-eletronico.css não carrega: o CSS da moldura vem com a janela.
 *
 * O som (`ponto-alerta-som.js`, SomDoAlertaDePontoTest) continua lendo o `data-perfil-sons` e a classe `show` do mesmo
 * nó. A janela aberta na tela é smoke do dono.
 */
#[Group('ponto')]
final class JanelaDoAlertaDePontoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const JANELA = 'body #modalAlertaPonto';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->cliente();
    }

    /** @return array{0: User, 1: Tenant} */
    private function colaboradorComJornada(): array
    {
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'ana');
        $jornada = new JornadaColaborador();
        $jornada->setUser($ana)->setTenant($tenant);
        $this->em()->persist($jornada);
        $this->em()->flush();

        return [$ana, $tenant];
    }

    private function pagina(string $url): Crawler
    {
        [$ana, $tenant] = $this->colaboradorComJornada();
        $this->logarComTenant($this->client, $ana, $tenant);
        $tela = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    #[TestDox('Fora da /ponto: a janela na moldura do desenho, fechada, com o CSS dela junto e sem o modal do Bootstrap')]
    public function testMolduraDoDesenhoForaDoPonto(): void
    {
        $tela = $this->pagina('/notificacoes');

        $janela = $tela->filter(self::JANELA);
        self::assertCount(1, $janela);
        self::assertSame('pe-alerta-ponto pe-janela-fundo', $janela->attr('class'), 'sem .modal nem .fade');
        self::assertNotNull($janela->attr('hidden'), 'fechada até o alerta chegar');
        self::assertNull($janela->attr('data-bs-backdrop'));
        self::assertNull($janela->attr('data-bs-keyboard'));
        self::assertSame('1', $janela->attr('data-perfil-sons'), 'o som do alerta continua lendo o mesmo nó');
        self::assertCount(0, $tela->filter('#modalAlertaPonto .modal-dialog, #modalAlertaPonto .modal-content, #modalAlertaPonto [data-bs-dismiss]'));
        self::assertCount(1, $tela->filter('link[rel="stylesheet"][href*="/css/ponto-alerta-janela.css"]'), 'o CSS da moldura vai junto');
        self::assertCount(0, $tela->filter('link[href*="/css/ponto-eletronico.css"]'), 'a página não é a /ponto');
        self::assertCount(1, $tela->filter('script[src*="/js/ponto-alerta-som.js"]'));
    }

    #[TestDox('Os mesmos textos de antes, com os ícones do desenho (L766): título com o triângulo, o "x" "Fechar aviso", a mensagem, o horário e "Ir para o Ponto Eletrônico" sem ícone')]
    public function testMesmosTextos(): void
    {
        $tela = $this->pagina('/notificacoes');

        $dialogo = $tela->filter(self::JANELA . ' > .pe-janela[role="dialog"][aria-modal="true"]');
        self::assertCount(1, $dialogo);
        self::assertSame('modalAlertaPontoLabel', $dialogo->attr('aria-labelledby'));

        $topo = $tela->filter(self::JANELA . ' .pe-janela > .pe-janela-topo');
        self::assertCount(1, $topo->filter('.pe-janela-topo > i.bi.bi-exclamation-triangle:first-child'));
        self::assertCount(0, $topo->filter('.bi-clock-history'));
        self::assertSame('Registro de Ponto', trim($topo->filter('#modalAlertaPontoLabel.pe-janela-titulo')->text()));
        $fechar = $topo->filter('button.pe-janela-fechar[data-alerta-ponto-fechar]');
        self::assertSame('Fechar aviso', $fechar->attr('aria-label'));
        self::assertCount(1, $fechar->filter('.bi.bi-x-lg'));

        $corpo = $tela->filter(self::JANELA . ' .pe-janela > .pe-alerta-ponto-corpo');
        self::assertCount(1, $corpo->filter('p#alertaPontoMensagem'));
        self::assertCount(1, $corpo->filter('p.pe-alerta-ponto-nota#alertaPontoHorario'));

        $ir = $tela->filter(self::JANELA . ' .pe-janela > .pe-janela-rodape > a.pe-janela-acao#btnIrParaPonto');
        self::assertCount(1, $ir);
        self::assertSame('/ponto/', $ir->attr('href'));
        self::assertSame('Ir para o Ponto Eletrônico', trim($ir->text()));
        self::assertCount(0, $ir->filter('i, .bi'), 'o botão do desenho não tem ícone');
    }

    #[TestDox('Na /ponto a janela também está lá, uma vez só')]
    public function testNaTelaDoPonto(): void
    {
        $tela = $this->pagina('/ponto/');

        self::assertCount(1, $tela->filter('#modalAlertaPonto'));
        self::assertCount(1, $tela->filter('link[href*="/css/ponto-alerta-janela.css"]'));
    }

    #[TestDox('O script: abre e fecha a janela pela classe show e pelo hidden, sem bootstrap.Modal; o "x" e o Esc fecham e marcam o dia')]
    public function testScriptDaJanela(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $partial = (string) file_get_contents($dir . '/templates/ponto/_modal_alerta_ponto.html.twig');

        self::assertStringNotContainsString('bootstrap.Modal', $partial);
        self::assertStringNotContainsString("addEventListener('hidden.bs.modal'", $partial);
        self::assertStringNotContainsString('modal.show()', $partial);

        $abrir = self::corpo($partial, 'function abrirJanela() {');
        self::assertStringContainsString('modalEl.hidden = false;', $abrir);
        self::assertStringContainsString("modalEl.classList.add('show');", $abrir, 'o som pendente só toca com o aviso aberto');

        $fechar = self::corpo($partial, 'function fecharJanela() {');
        self::assertStringContainsString('modalEl.hidden = true;', $fechar);
        self::assertStringContainsString("modalEl.classList.remove('show');", $fechar);
        self::assertStringContainsString('pararPiscarTitulo();', $fechar);
        self::assertStringContainsString('marcarComoDispensado(modalEl._tipoAtual);', $fechar, 'o "x" marca o alerta do dia como visto');

        // O "x" fecha; "Ir para o Ponto Eletrônico" marca e segue o link.
        self::assertMatchesRegularExpression("/evento\\.target\\.closest\\('\\[data-alerta-ponto-fechar\\]'\\)\\) \\{\\s+fecharJanela\\(\\);/", $partial);
        self::assertMatchesRegularExpression('/btnIr\.addEventListener\(\'click\', function \(\) \{\s+if \(modalEl\._tipoAtual\) \{\s+marcarComoDispensado\(modalEl\._tipoAtual\);\s+\}\s+pararPiscarTitulo\(\);/', $partial);

        // A exibição e a notificação abrem a janela; o som continua sendo chamado com o mesmo nó.
        $exibir = self::corpo($partial, 'function exibirAlerta(dados) {');
        self::assertStringContainsString('window.BJSomDoAlertaDePonto.tocar(dados.tipo, modalEl);', $exibir);
        self::assertStringContainsString('abrirJanela();', $exibir);
        self::assertMatchesRegularExpression('/notif\.close\(\);\s+abrirJanela\(\);/', $partial, 'o clique na notificação reabre a janela');

        // Esc fecha como o "x" (desenho L440), sem chegar ao que está por baixo; clicar fora continua sem fechar.
        self::assertMatchesRegularExpression("/if \\(evento\\.key === 'Escape'\\) \\{\\s+evento\\.stopPropagation\\(\\);\\s+fecharJanela\\(\\);\\s+\\} else if \\(evento\\.key === 'Tab'\\) \\{/", $partial);
        self::assertSame(2, substr_count($partial, 'fecharJanela();'), 'só o "x" e o Esc fecham');
        self::assertStringNotContainsString('evento.target === modalEl', $partial, 'clicar no fundo não fecha');
    }

    #[TestDox('O CSS da janela: os valores da moldura do desenho, tudo sob .pe-alerta-ponto (carrega em toda página)')]
    public function testCssDaJanela(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $css = (string) file_get_contents($dir . '/public/css/ponto-alerta-janela.css');
        $semComentarios = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // Cada regra vale só para a janela do alerta (nenhum seletor solto num CSS que carrega em toda página).
        preg_match_all('/([^{}]+)\{[^{}]*\}/', (string) preg_replace('/@keyframes[^{]+\{(?:[^{}]*\{[^{}]*\})*\s*\}/', '', $semComentarios), $regras);
        self::assertNotEmpty($regras[1]);
        foreach ($regras[1] as $grupo) {
            foreach (explode(',', $grupo) as $seletor) {
                self::assertMatchesRegularExpression('/^(\[data-bs-theme="dark"\] )?\.pe-alerta-ponto\b/', trim($seletor), 'seletor fora da janela: ' . trim($seletor));
            }
        }

        // Os valores do desenho (L270 a L272, L288 e o rodapé).
        self::assertStringContainsString('z-index: 2147483100;', $css);
        self::assertStringContainsString('background: rgba(11, 53, 80, .55);', $css);
        self::assertStringContainsString('max-width: 480px;', $css);
        self::assertStringContainsString('box-shadow: 0 18px 50px rgba(0, 0, 0, .35);', $css);
        self::assertStringContainsString('--pe-azul:         #0a6fa0;', $css);
        self::assertStringContainsString('.pe-alerta-ponto .pe-janela-topo { display: flex; align-items: center; gap: 9px; padding: 12px 16px;', $css);
        self::assertStringContainsString('.pe-alerta-ponto-corpo { padding: 14px 16px; font-size: 14px; line-height: 1.5; color: var(--pe-corpo); }', $css);
        self::assertStringContainsString('margin-top: 8px; font-size: 12.5px; color: var(--pe-mudo);', $css);
        self::assertStringContainsString('height: 36px;', $css);
        self::assertStringContainsString('[data-bs-theme="dark"] .pe-alerta-ponto {', $css, 'tema escuro');
    }

    #[TestDox('Revisão: a janela entra na fila dos avisos globais (seletor nos 4 JS e hidden.bs.modal ao fechar) e espera a tela cheia fechar')]
    public function testFilaDosAvisosEATelaCheia(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $partial = (string) file_get_contents($dir . '/templates/ponto/_modal_alerta_ponto.html.twig');

        // Ao fechar, `hidden.bs.modal` com bolha: quem esperava a janela abre o próprio aviso. Sem a classe `modal`.
        $fechar = self::corpo($partial, 'function fecharJanela() {');
        self::assertStringContainsString("modalEl.dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));", $fechar);
        self::assertGreaterThan((int) strpos($fechar, "modalEl.classList.remove('show');"), (int) strpos($fechar, 'dispatchEvent'), 'depois de sair do seletor');
        self::assertDoesNotMatchRegularExpression('/id="modalAlertaPonto"[^>]*class="[^"]*\\bmodal\\b/', $partial);
        self::assertDoesNotMatchRegularExpression('/class="[^"]*\\bmodal\\b[^"]*"[^>]*id="modalAlertaPonto"/', $partial);

        foreach (['aviso-espaco.js', 'janela-genero.js', 'contribua-cora.js', 'meu-perfil.js'] as $arquivo) {
            $js = (string) file_get_contents($dir . '/public/js/' . $arquivo);
            self::assertStringContainsString("document.querySelector('.modal.show, #modalAlertaPonto.show')", $js, $arquivo);
            self::assertStringNotContainsString("document.querySelector('.modal.show')", $js, $arquivo);
        }

        // Com a tela cheia do registro aberta, nem a janela nem o som do alerta saem: esperam ela fechar.
        $abrir = self::corpo($partial, 'function abrirJanela() {');
        self::assertMatchesRegularExpression('/if \(telaCheiaAberta\(\)\) \{\s+window\.PontoTelaCheia\.quandoFechar\(abrirJanela\);\s+return;/', $abrir);
        $exibir = self::corpo($partial, 'function exibirAlerta(dados) {');
        self::assertLessThan((int) strpos($exibir, 'window.BJSomDoAlertaDePonto.tocar('), (int) strpos($exibir, 'if (telaCheiaAberta()) {'), 'antes do som e da notificação');
        self::assertStringContainsString('window.PontoTelaCheia.quandoFechar(function () {', $exibir);
        self::assertStringContainsString("tela.fase() !== ''", $partial);
    }

    #[TestDox('Os testes de navegador que fecham a janela por conta própria não usam mais o modal do Bootstrap')]
    public function testSpecsDoNavegador(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/../e2e/tests/';

        $cerca = (string) file_get_contents($dir . 'ponto-batida-cerca.spec.js');
        self::assertStringNotContainsString('Modal.getOrCreateInstance(alerta)', $cerca);
        self::assertStringContainsString('alerta.hidden = true;', $cerca);
        $perfil = (string) file_get_contents($dir . 'perfil.spec.js');
        self::assertStringContainsString("const alertaPonto = document.getElementById('modalAlertaPonto');", $perfil);
        self::assertStringContainsString('alertaPonto.hidden = true;', $perfil);
        $expediente = (string) file_get_contents($dir . 'expediente-lista-visual.spec.js');
        self::assertSame(2, substr_count($expediente, "'.modal.show, #modalAlertaPonto, .modal-backdrop, .sf-toolbar'"));
    }

    private static function corpo(string $codigo, string $assinatura): string
    {
        $inicio = strpos($codigo, $assinatura);
        self::assertIsInt($inicio, 'não achei: ' . $assinatura);
        $profundidade = 0;
        $tamanho = strlen($codigo);
        for ($i = $inicio + strlen($assinatura) - 1; $i < $tamanho; ++$i) {
            if ('{' === $codigo[$i]) {
                ++$profundidade;
            } elseif ('}' === $codigo[$i]) {
                --$profundidade;
                if (0 === $profundidade) {
                    return substr($codigo, $inicio, $i - $inicio + 1);
                }
            }
        }
        self::fail('fim não achado: ' . $assinatura);
    }
}
