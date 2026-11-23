<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Tests\Arquitetura\SemTextoFixoNosExtraidosArquiteturaTest;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Spec idioma-do-sistema §29 (idioma-ponto-js-2): datas, horas e números do JS do Ponto seguem o idioma da pessoa
 * (`BJFormato`), menos o que é máquina (chave do localStorage, comparação com o dia do servidor), que fica em 'pt-BR'.
 * E os `ponto-*.js` não trazem frase fixa de tela além das exceções listadas aqui (texto técnico e legal).
 */
final class FormatosEFrasesDoJsDoPontoTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js';

    /**
     * Frases que ficam fixas nos `ponto-*.js`, por arquivo: texto técnico (`console.*`, `new Error`, cabeçalhos HTTP, nomes de
     * tecla) e as duas linhas de janelas de documento legal, que ficam sempre em português (pt_BR).
     */
    private const EXCECOES = [
        'ponto-abas.js' => ["'Escape'", "'Tab'"],
        'ponto-batida.js' => ["'Não consegui guardar a posição para a próxima batida:'", "'Content-Type'", "'Erro ao registrar ponto:'", "'Accept'", "'Tela do ponto respondeu '", "'A sincronização da fila não entrou na auditoria:'", "'Notification'"],
        'ponto-comprovantes.js' => ["'Sem resposta do servidor. Confira a conexão e tente de novo.'", "'Content-Disposition'", "'Escape'", "'Tab'"],
        'ponto-configuracoes.js' => ["'Accept'", "'Content-Type'"],
        'ponto-configuracoes-sedes.js' => ["'Escape'", "'Tab'"],
        'ponto-equipe.js' => ["'Content-Type'"],
        'ponto-fila.js' => [
            "'O banco da fila está preso por outra aba.'", "'A transação da fila foi desfeita.'", "'A transação da chave foi desfeita.'",
            "'Batida guardada que não pôde ser lida:'", "'Recusa guardada que não pôde ser lida:'", "'A transação da recusa foi desfeita.'",
            "'Não foi possível guardar a recusa da batida:'", "'Não foi possível guardar a batida neste aparelho:'",
            "'Não foi possível ler as batidas guardadas:'", "'Não foi possível limpar as batidas guardadas:'",
            "'Não foi possível apagar da fila:'", "'Falha ao enviar as batidas guardadas:'",
        ],
        'ponto-folha.js' => ["'Descreva a ressalva.'", "'Content-Disposition'"],
        'ponto-paineis.js' => ["'Accept'"],
        'ponto-pendente.js' => ["'×'", "'Content-Type'"],
        'ponto-tela-cheia.js' => ["'Falha depois de fechar a tela cheia:'", "'Escape'", "'Tab'"],
    ];

    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(self::JS . '/' . $arquivo);
    }

    private static function semComentario(string $js): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~(^|[^:\'"`\\\\])//[^\n]*~m'], ['', '$1'], $js);
    }

    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    /** @return list<string> */
    private static function arquivosDoPonto(): array
    {
        $arquivos = array_map('basename', glob(self::JS . '/ponto-*.js') ?: []);
        sort($arquivos);

        return $arquivos;
    }

    #[TestDox('Nenhum ponto-*.js tem locale fixo em pt-BR: o milhar da distância e da precisão sai por BJFormato.numero')]
    public function testJsDoPontoSemLocaleFixo(): void
    {
        foreach (self::arquivosDoPonto() as $arquivo) {
            $codigo = self::semComentario(self::js($arquivo));
            self::assertStringNotContainsString("'pt-BR'", $codigo, $arquivo . ' tem locale fixo');
            self::assertStringNotContainsString('"pt-BR"', $codigo, $arquivo . ' tem locale fixo');
        }
        $batida = self::semComentario(self::js('ponto-batida.js'));
        self::assertStringContainsString('window.BJFormato.numero(Math.round(maisProxima.distancia))', $batida);
        self::assertStringContainsString('window.BJFormato.numero(Math.round(Number(maisProxima.sede.raio)))', $batida);
        // 'sv-SE' do ponto-pendente.js é máquina (data ISO aaaa-mm-dd que vai ao servidor): fica.
        self::assertStringContainsString("toLocaleDateString('sv-SE')", self::js('ponto-pendente.js'));
    }

    #[TestDox('No script da tela, o texto que a pessoa lê usa o idioma dela e o que é chave ou comparação fica em pt-BR')]
    public function testScriptDaTelaFormatosDeExibicaoEDeMaquina(): void
    {
        $codigo = self::semComentario(self::tela());

        // O único 'pt-BR' fixo é a constante de máquina e o recuo de quando o BJFormato não existe.
        self::assertSame(2, substr_count($codigo, "'pt-BR'"));
        self::assertStringContainsString("const IDIOMA_DE_MAQUINA = 'pt-BR';", $codigo);
        self::assertStringContainsString("const IDIOMA_DA_TELA = window.BJFormato ? window.BJFormato.idioma() : 'pt-BR';", $codigo);
        self::assertStringNotContainsString('toLocaleString', $codigo);

        // Máquina: chave do localStorage do lembrete e comparação com o dia da tela (DIA_DA_TELA, dd/mm/aaaa).
        self::assertSame(2, substr_count($codigo, 'formatoNoFusoDoServidor(IDIOMA_DE_MAQUINA,'));
        self::assertStringContainsString("const FORMATO_DIA_DO_LEMBRETE = formatoNoFusoDoServidor(IDIOMA_DE_MAQUINA, { day: '2-digit', month: '2-digit', year: 'numeric' });", $codigo);
        self::assertStringContainsString("const FORMATO_DIA_DA_FILA = formatoNoFusoDoServidor(IDIOMA_DE_MAQUINA, { day: '2-digit', month: '2-digit', year: 'numeric' });", $codigo);

        // Exibição: relógio, data por extenso, hora e dia/mês da recusa da fila. O fuso do servidor e as opções ficam.
        self::assertSame(4, substr_count($codigo, 'formatoNoFusoDoServidor(IDIOMA_DA_TELA,'));
        self::assertStringContainsString("const FORMATO_HORA_OFICIAL = formatoNoFusoDoServidor(IDIOMA_DA_TELA, { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });", $codigo);
        self::assertStringContainsString("const FORMATO_DATA_OFICIAL = formatoNoFusoDoServidor(IDIOMA_DA_TELA, { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });", $codigo);
        self::assertStringContainsString("const FORMATO_HORA_DA_FILA = formatoNoFusoDoServidor(IDIOMA_DA_TELA, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });", $codigo);
        self::assertStringContainsString("const FORMATO_DIA_E_MES_DA_FILA = formatoNoFusoDoServidor(IDIOMA_DA_TELA, { day: '2-digit', month: '2-digit' });", $codigo);
        self::assertStringContainsString('new Intl.DateTimeFormat(idioma, Object.assign({ timeZone: FUSO_DO_SERVIDOR }, opcoes))', $codigo);
        self::assertStringContainsString('window.BJFormato.numero(Math.round(accuracy))', $codigo);
    }

    #[TestDox('O bj-formato.js carrega antes do script da tela, que monta o relógio no carregamento')]
    public function testFormatoCarregaAntesDoScriptDaTela(): void
    {
        $tela = self::tela();
        $formato = strpos($tela, "asset('js/bj-formato.js')");
        self::assertNotFalse($formato);
        self::assertLessThan((int) strrpos($tela, "<script>\n"), $formato);
        self::assertLessThan((int) strpos($tela, "asset('js/ponto-batida.js')"), $formato);
    }

    #[TestDox('Os ponto-*.js não trazem frase fixa de tela além das exceções (texto técnico e janelas de documento legal)')]
    public function testJsDoPontoSemFraseFixa(): void
    {
        $falhas = [];
        foreach (self::arquivosDoPonto() as $arquivo) {
            $achados = SemTextoFixoNosExtraidosArquiteturaTest::textosFixosNoJs(self::js($arquivo), []);
            $sobra = array_diff($achados, self::EXCECOES[$arquivo] ?? []);
            if ($sobra !== []) {
                $falhas[] = $arquivo . ': ' . implode(' | ', $sobra);
            }
        }

        self::assertSame([], $falhas, 'Texto fixo em ponto-*.js: ponha no catálogo ponto_cartao.js (BJ_T.t) ou liste como exceção técnica.');
    }

    #[TestDox('Cada exceção listada ainda existe no arquivo (a lista não apodrece)')]
    public function testExcecoesExistem(): void
    {
        foreach (self::EXCECOES as $arquivo => $literais) {
            foreach ($literais as $literal) {
                self::assertStringContainsString($literal, self::js($arquivo), $arquivo . ' não tem mais ' . $literal);
            }
        }
    }

    #[TestDox('Todo ponto-*.js que usa BJ_T tem o catálogo entregue por alguma página que o carrega')]
    public function testCatalogoEntregueAondeOJsCarrega(): void
    {
        $templates = __DIR__ . '/../../../templates';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templates, \FilesystemIterator::SKIP_DOTS));
        $conteudo = [];
        foreach ($it as $arquivo) {
            if (str_ends_with((string) $arquivo, '.twig')) {
                $conteudo[(string) $arquivo] = (string) file_get_contents((string) $arquivo);
            }
        }
        $catalogoDoJs = ['ponto_cartao.js' => 'ponto/index.html.twig', 'ponto_config.js' => 'ponto/_config_funcoes.html.twig', 'ponto_folha.js' => 'ponto/_folha_table.html.twig'];
        foreach ($catalogoDoJs as $catalogo => $relativo) {
            self::assertStringContainsString("catalogo_js('" . $catalogo . "')", $conteudo[$templates . '/' . $relativo], $relativo . ' não entrega ' . $catalogo);
        }
        // Os ponto-*.js que usam texto: cada um carrega onde o catálogo do seu bloco é entregue.
        foreach (self::arquivosDoPonto() as $arquivo) {
            if (!preg_match("/BJ_T\\.t\\('(ponto_[a-z]+\\.js)/", self::js($arquivo), $m)) {
                continue;
            }
            self::assertArrayHasKey($m[1], $catalogoDoJs, $arquivo . ' usa um catálogo sem página registrada: ' . $m[1]);
        }
        self::assertStringContainsString("catalogo_js('ponto_cartao.js.presenca', 'ponto_cartao.js.batida')", $conteudo[$templates . '/menu_usuario/_presenca.html.twig'], 'o cabeçalho carrega ponto-batida.js e entrega as chaves dele');
    }
}
