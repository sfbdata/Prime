<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E12 do desenho novo do Ponto (`docs/specs/ponto-desenho-0811.md`, P18 e P74; desenho 16 L289, L381 a L385, L528,
 * L766, L808 e L820): fora do raio, a caixa de hoje mostra o título e o detalhe do desenho e, com o trabalho externo
 * permitido, o botão "Registrar como trabalho externo", que abre a janela "Trabalho externo" e manda a batida com o
 * local e o motivo.
 *
 * Lido no texto dos scripts e dos parciais (o container não tem node; o smoke no navegador é do dono): os textos do
 * desenho, o botão só com `trabalhoExterno` true, o corpo do envio com os dois campos e um `idEnvio` novo, o "fora"
 * local que não envia, a janela na moldura `pe-janela` sem o modal do Bootstrap e tudo por `textContent`.
 */
final class TrabalhoExternoForaDoRaioJsTest extends TestCase
{
    private static function modulo(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-batida.js');
    }

    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    private static function configuracoes(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-configuracoes.js');
    }

    private static function arquivo(string $relativo): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $relativo);
    }

    /** Corpo de uma função, delimitado por contagem de chaves a partir da assinatura. */
    private static function corpo(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, 'faltou: ' . $assinatura);
        $abre = strpos($fonte, '{', $inicio + strlen($assinatura) - 1);
        self::assertIsInt($abre);

        $profundidade = 0;
        for ($i = $abre, $n = strlen($fonte); $i < $n; $i++) {
            if ($fonte[$i] === '{') {
                $profundidade++;
            } elseif ($fonte[$i] === '}' && --$profundidade === 0) {
                return substr($fonte, $inicio, $i - $inicio + 1);
            }
        }

        self::fail('o corpo não fecha: ' . $assinatura);
    }

    #[TestDox('O módulo monta os textos do desenho (L528) para o "fora" local, na forma do foraDoRaio do servidor')]
    public function testTextosDoDesenhoNoModulo(): void
    {
        $textos = self::corpo(self::modulo(), 'function textosDoForaDoRaio(onde, trabalhoExternoPermitido) {');

        self::assertStringContainsString("const TITULO_FORA_DO_RAIO = 'Você está fora do raio permitido';", self::modulo());
        self::assertStringContainsString("let detalhe = 'O registro exige estar dentro do raio de uma sede do escritório.';", $textos, 'sem sede, a frase do desenho');
        self::assertStringContainsString(
            "detalhe = 'A sede mais próxima é ' + String(sede.nome) + ', a ' + Math.round(distancia).toLocaleString('pt-BR')\n"
            . "                + ' m. O raio permitido é de ' + Math.round(raio).toLocaleString('pt-BR') + ' m.';",
            $textos,
            'a sede, a distância e o raio com o milhar em pt-BR, como o servidor (PosicaoNaCerca)'
        );
        self::assertMatchesRegularExpression(
            "/const permitido = trabalhoExternoPermitido === true;\s+if \(permitido\) \{\s+detalhe \+= ' Aproxime-se ou informe o trabalho externo\.';\s+\}/",
            $textos,
            'a frase do trabalho externo só com a função permitida no escritório'
        );
        self::assertStringContainsString('return { titulo: TITULO_FORA_DO_RAIO, detalhe, trabalhoExterno: permitido };', $textos, 'a forma do foraDoRaio do servidor');
        self::assertStringNotContainsString('document', $textos, 'puro: quem mostra é a tela');
        self::assertStringNotContainsString('innerHTML', self::modulo());

        self::assertMatchesRegularExpression('/window\.PontoBatida = Object\.freeze\(\{[^}]*\btextosDoForaDoRaio,\s+foraDoRaioDaResposta,/', self::modulo(), 'exportados');
    }

    #[TestDox('A resposta do servidor é conferida: sem foraDoRaio com título, null; o botão só com trabalhoExterno === true')]
    public function testRespostaDoServidorConferida(): void
    {
        $resposta = self::corpo(self::modulo(), 'function foraDoRaioDaResposta(data) {');

        self::assertStringContainsString("const fora = data && typeof data === 'object' ? data.foraDoRaio : null;", $resposta);
        self::assertMatchesRegularExpression(
            "/if \(!fora \|\| typeof fora !== 'object' \|\| typeof fora\.titulo !== 'string' \|\| fora\.titulo === ''\) \{\s+return null;/",
            $resposta,
            'as outras recusas (motivo vazio, interjornada) não viram o bloco'
        );
        self::assertStringContainsString('trabalhoExterno: fora.trabalhoExterno === true,', $resposta, 'só true liga o botão');
    }

    #[TestDox('O bloco "fora do raio" é uma função da tela que escreve no alvo dado, só por textContent, e o botão só com trabalhoExterno true')]
    public function testBlocoForaDoRaio(): void
    {
        $bloco = self::corpo(self::tela(), 'function mostrarForaDoRaio(alvo, foraDoRaio) {');

        self::assertStringContainsString('titulo.textContent = foraDoRaio.titulo;', $bloco);
        self::assertStringContainsString('detalhe.textContent = foraDoRaio.detalhe;', $bloco);
        self::assertStringNotContainsString('innerHTML', $bloco, 'o detalhe traz o nome da sede, digitado por alguém');
        self::assertStringContainsString('alvo.replaceChildren(...partes);', $bloco, 'a E8 muda só o alvo');
        self::assertStringNotContainsString('avisoBatida', $bloco, 'o bloco não sabe qual é o alvo');

        $botao = self::corpo($bloco, 'if (foraDoRaio.trabalhoExterno === true) {');
        self::assertStringContainsString("icone.className = 'bi bi-briefcase';", $botao, 'o ícone do desenho (L385)');
        self::assertStringContainsString("botao.append(icone, 'Registrar como trabalho externo');", $botao, 'o rótulo do desenho');
        self::assertStringContainsString("botao.className = 'pe-fora-externo';", $botao);
        self::assertStringContainsString("botao.type = 'button';", $botao);
        self::assertStringContainsString('abrirJanelaDoTrabalhoExterno(botao)', $botao, 'abre a janela "Trabalho externo"');
        self::assertSame(1, substr_count(self::tela(), "'Registrar como trabalho externo'"), 'o botão nasce num lugar só');

        // Nenhum innerHTML da tela recebe o bloco, o motivo ou a resposta do servidor.
        preg_match_all('/\.innerHTML\s*=\s*(.*?);[ \t]*$/ms', self::tela(), $atribuicoes);
        self::assertNotEmpty($atribuicoes[1]);
        foreach ($atribuicoes[1] as $valor) {
            foreach (['foraDoRaio', 'motivo', 'Externo', 'resultado'] as $proibido) {
                self::assertStringNotContainsString($proibido, $valor, 'dado em innerHTML: ' . $valor);
            }
        }
    }

    #[TestDox('O "fora" local não envia: escreve o bloco na caixa de hoje e sai antes da fila e do envio; o toque do trabalho externo passa')]
    public function testForaLocalNaoEnvia(): void
    {
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");

        $ramo = strpos($clique, "if (motivoExterno === null && onde.situacao === 'fora') {");
        self::assertIsInt($ramo, 'o toque de trabalho externo não para no "fora" local');
        $corpoDoRamo = self::corpo(substr($clique, $ramo), "if (motivoExterno === null && onde.situacao === 'fora') {");
        self::assertMatchesRegularExpression(
            "/mostrarAvisoBatida\('', 'danger'\);\s+mostrarForaDoRaio\(avisoBatida, foraDoRaioDaTela\(onde\)\);\s+return;\s+\}$/",
            $corpoDoRamo,
            'o bloco na caixa de hoje (o tom de erro e o botão de atualizar escondido vêm do mostrarAvisoBatida), e sai'
        );
        self::assertLessThan(strpos($clique, 'PontoBatida.enviarBatida('), $ramo, 'decide antes do envio');
        self::assertLessThan(strpos($clique, 'guardarNaFila(payload)'), $ramo, 'e antes da fila sem internet');
        self::assertLessThan(strpos($clique, 'envioEmAndamento = true;'), $ramo, 'nada começa: o botão não trava');

        $local = self::corpo(self::tela(), 'function foraDoRaioDaTela(onde) {');
        self::assertStringContainsString('return PontoBatida.textosDoForaDoRaio(onde, TRABALHO_EXTERNO_PERMITIDO);', $local, 'o botão local segue a opção do escritório');
        self::assertStringContainsString("typeof PontoBatida.textosDoForaDoRaio === 'function'", $local, 'módulo antigo no cache não lança no meio do toque');
        self::assertStringContainsString('trabalhoExterno: false', $local, 'sem o módulo novo, sem o botão');
    }

    #[TestDox('A recusa do servidor com foraDoRaio (403 fora, 422 GPS impreciso) mostra o mesmo bloco, no ramo da recusa')]
    public function testRecusaDoServidorMostraOBloco(): void
    {
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");

        self::assertMatchesRegularExpression(
            "/\} else if \(resultado\.tipo === 'recusa'\) \{\s+mostrarAvisoBatida\(resultado\.mensagem, 'danger'\);\s+(?:\/\/[^\n]*\n\s+)*"
            . "const foraDoRaio = foraDoRaioDaResposta\(resultado\.data\);\s+if \(foraDoRaio\) \{\s+mostrarForaDoRaio\(avisoBatida, foraDoRaio\);\s+\}\s+\} else if/",
            $clique,
            'sem foraDoRaio, a frase do servidor como sempre; com ele, o bloco'
        );
        self::assertStringContainsString(
            "return typeof PontoBatida.foraDoRaioDaResposta === 'function' ? PontoBatida.foraDoRaioDaResposta(data) : null;",
            self::corpo(self::tela(), 'function foraDoRaioDaResposta(data) {')
        );
    }

    #[TestDox('O envio do trabalho externo é um toque novo: o corpo de sempre, com idEnvio novo, mais trabalhoExterno e o motivo')]
    public function testCorpoDoEnvio(): void
    {
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");

        self::assertMatchesRegularExpression(
            "/const payload = \{ tipo: tipoRegistro\.value, idEnvio: idDoEnvio\(\), horaAparelho \};\s+(?:\/\/[^\n]*\n\s+)*"
            . "if \(motivoExterno !== null\) \{\s+payload\.trabalhoExterno = true;\s+payload\.motivoTrabalhoExterno = motivoExterno;\s+\}/",
            $clique,
            'o idEnvio é feito no toque (novo), e os dois campos entram no mesmo corpo, antes da fila e do envio'
        );
        $leitura = strpos($clique, 'const motivoExterno = motivoDoTrabalhoExterno;');
        self::assertIsInt($leitura);
        self::assertLessThan(strpos($clique, 'await '), $leitura, 'o motivo é lido antes do primeiro await, enquanto o clique ainda está em curso');
        self::assertLessThan(strpos($clique, 'guardarNaFila(payload)'), strpos($clique, 'payload.motivoTrabalhoExterno'), 'sem internet, a fila recebe o corpo com os dois campos');

        $registrar = self::corpo(self::tela(), 'function registrarComoTrabalhoExterno(motivo) {');
        self::assertMatchesRegularExpression(
            '/motivoDoTrabalhoExterno = motivo;\s+try \{\s+btnPonto\.focus\(\);\s+btnPonto\.click\(\);\s+\} finally \{\s+motivoDoTrabalhoExterno = null;\s+\}/',
            $registrar,
            'o mesmo toque do botão de bater; o motivo não sobra para o toque seguinte'
        );
        self::assertStringNotContainsString('idEnvio', $registrar, 'nenhum identificador reaproveitado: o handler faz outro');
        self::assertStringNotContainsString('enviarBatida', $registrar, 'um envio só, o do botão');
        self::assertMatchesRegularExpression('/if \(btnPonto\.disabled\) \{\s+return;\s+\}/', $registrar, 'durante um envio ou com o dia completo, nada sai');
        self::assertSame(1, substr_count(self::tela(), 'motivoDoTrabalhoExterno = motivo;'), 'só a janela põe o motivo');
    }

    #[TestDox('A janela "Trabalho externo" usa a moldura do Ponto: abre e mostra o erro pelo PontoAbas, com a frase do desenho (L808)')]
    public function testJanelaNaMolduraDoPonto(): void
    {
        $abrir = self::corpo(self::tela(), 'function abrirJanelaDoTrabalhoExterno(origem) {');
        self::assertStringContainsString('window.PontoAbas.abrirJanela(JANELA_DO_TRABALHO_EXTERNO, origem);', $abrir);
        self::assertMatchesRegularExpression("/campo\.value = '';\s+campo\.focus\(\);/", $abrir, 'abre vazia, com o foco no campo (fMot: \'\' do desenho)');
        self::assertStringContainsString("const JANELA_DO_TRABALHO_EXTERNO = 'ponto-janela-externo';", self::tela());

        $registrar = self::corpo(self::tela(), "botaoRegistrarExterno.addEventListener('click', function () {");
        self::assertStringContainsString('const motivo = campo.value.trim();', $registrar);
        self::assertMatchesRegularExpression(
            "/if \(motivo === ''\) \{\s+window\.PontoAbas\.mostrarErro\(fundo, 'Informe o local e o motivo\.'\);\s+campo\.focus\(\);\s+return;\s+\}/",
            $registrar,
            'vazio: a frase do desenho na linha de erro da janela, e nada sai'
        );
        self::assertMatchesRegularExpression('/window\.PontoAbas\.fecharJanela\(\);\s+registrarComoTrabalhoExterno\(motivo\);/', $registrar, 'com texto: fecha e envia');

        // A marcação, sem o comentário do parcial (que cita o modal do Bootstrap para dizer que ele não entra).
        $janela = (string) preg_replace('/\{#.*?#\}/s', '', self::arquivo('templates/ponto/_janela_trabalho_externo.html.twig'));
        self::assertStringContainsString('class="pe-janela-fundo" id="ponto-janela-externo" hidden', $janela);
        self::assertDoesNotMatchRegularExpression('/class="[^"]*\b(?:modal|modal-dialog|btn|form-control|alert)\b/', $janela, 'componente do desenho, nada do Bootstrap');
        self::assertStringNotContainsString('data-bs-', $janela);
        self::assertStringNotContainsString('modal', self::corpo(self::tela(), 'function abrirJanelaDoTrabalhoExterno(origem) {'));
        self::assertStringNotContainsString('bootstrap', self::corpo(self::tela(), 'function abrirJanelaDoTrabalhoExterno(origem) {'));
    }

    #[TestDox('A caixa da localização: com o trabalho externo permitido, a segunda frase é a do desenho; sem ele, a de hoje')]
    public function testCaixaDaLocalizacao(): void
    {
        $tela = self::tela();
        $fora = self::corpo($tela, 'function mostrarForaDaArea(onde) {');

        self::assertMatchesRegularExpression(
            "/TRABALHO_EXTERNO_PERMITIDO\s+\? 'Aproxime-se ou informe o trabalho externo\.'\s+: 'Se está em trabalho externo ou home office, peça ao gestor a liberação do dia e depois atualize esta página\.'/",
            $fora
        );
        self::assertStringContainsString("const TRABALHO_EXTERNO_PERMITIDO = (document.getElementById('ponto-fila') || { dataset: {} }).dataset.trabalhoExterno === '1';", $tela);
        self::assertLessThan(
            strpos($tela, 'function guardarPosicao('),
            strpos($tela, 'const TRABALHO_EXTERNO_PERMITIDO = '),
            'declarada antes do retorno do GPS que a usa (const não é içado)'
        );
        self::assertStringContainsString(
            'data-trabalho-externo="{{ trabalho_externo_permitido|default(false) ? \'1\' : \'0\' }}"',
            $tela,
            'junto dos data-atributos da E14, sem quebrar o render enquanto o servidor não manda a variável'
        );
    }

    #[TestDox('Configurações: a caixa grava {permitir}; erro volta a caixa e mostra o erro do servidor; sucesso usa o permitir devolvido')]
    public function testCaixaDaConfiguracao(): void
    {
        $alternar = self::corpo(self::configuracoes(), 'async function alternarTrabalhoExterno(caixa) {');

        self::assertStringContainsString('const antes = !caixa.checked;', $alternar, 'o change chega com a caixa já trocada');
        self::assertStringContainsString('const resultado = await enviar(secao.dataset.url, { permitir: caixa.checked }, null);', $alternar, 'o token ajax vem do interceptador, como a chave do escritório');
        self::assertMatchesRegularExpression(
            '/if \(!resultado\.ok\) \{\s+caixa\.checked = antes;\s+mostrarErroDoExterno\(secao, resultado\.erro\);\s+return;\s+\}\s+caixa\.checked = resultado\.dados\.permitir === true;/',
            $alternar
        );
        self::assertMatchesRegularExpression('/caixa\.disabled = true;[\s\S]*await enviar[\s\S]*caixa\.disabled = false;/', $alternar, 'sem segundo clique durante a gravação');
        self::assertStringContainsString("erro.textContent = texto || '';", self::corpo(self::configuracoes(), 'function mostrarErroDoExterno(secao, texto) {'));
        self::assertStringContainsString("const caixa = elemento(evento.target, '[data-config-externo-caixa]');", self::configuracoes(), 'por delegação: o painel chega depois');

        $parcial = self::arquivo('templates/ponto/_config_trabalho_externo.html.twig');
        self::assertStringContainsString('data-url="/ponto/configuracoes/trabalho-externo"', $parcial, 'a rota ponto_configuracao_trabalho_externo, escrita');
        self::assertStringNotContainsString("path('ponto_configuracao_trabalho_externo')", $parcial, 'path() de rota ainda inexistente quebraria o painel');
    }

    #[TestDox('A fila sem internet guarda e reenvia a marca e o local e o motivo do trabalho externo; entrada antiga segue comum')]
    public function testFilaSemInternetLevaOsDoisCampos(): void
    {
        $fila = self::arquivo('public/js/ponto-fila.js');
        self::assertStringContainsString('const LIMITE_DO_MOTIVO_EXTERNO = 500;', $fila, 'o limite do servidor e o da janela');

        // Guardar: os dois campos só com a marca true; sem ela (entrada de antes da E12, batida comum), nenhum dos dois.
        $normalizar = self::corpo($fila, 'function normalizar(entrada) {');
        $externoGuardado = self::corpo($normalizar, 'if (entrada.trabalhoExterno === true) {');
        self::assertStringContainsString("const motivo = typeof entrada.motivoTrabalhoExterno === 'string' ? entrada.motivoTrabalhoExterno : '';", $externoGuardado);
        self::assertStringContainsString('limpa.trabalhoExterno = true;', $externoGuardado);
        self::assertStringContainsString("limpa.motivoTrabalhoExterno = Array.from(motivo).slice(0, LIMITE_DO_MOTIVO_EXTERNO).join('');", $externoGuardado, 'até 500, cortado por caractere');
        self::assertSame(1, substr_count($normalizar, 'limpa.trabalhoExterno'), 'a marca só entra dentro do if');
        self::assertSame(1, substr_count($normalizar, 'limpa.motivoTrabalhoExterno'), 'o motivo só entra dentro do if');
        self::assertMatchesRegularExpression('/limpa\.motivoTrabalhoExterno = [^\n]+\n\s+\}\s+return limpa;\s+\}$/', $normalizar, 'o if vem depois do resto e antes do retorno');

        // Reenviar: o corpo da batida guardada leva os dois campos, também só com a marca true.
        $payload = self::corpo($fila, 'function payloadDe(entrada) {');
        $externoEnviado = self::corpo($payload, 'if (entrada.trabalhoExterno === true) {');
        self::assertStringContainsString('payload.trabalhoExterno = true;', $externoEnviado);
        self::assertStringContainsString("payload.motivoTrabalhoExterno = typeof entrada.motivoTrabalhoExterno === 'string' ? entrada.motivoTrabalhoExterno : '';", $externoEnviado);
        self::assertSame(1, substr_count($payload, 'payload.trabalhoExterno'));
        self::assertSame(1, substr_count($payload, 'payload.motivoTrabalhoExterno'));
        self::assertMatchesRegularExpression('/payload\.motivoTrabalhoExterno = [^\n]+\n\s+\}\s+return payload;\s+\}$/', $payload);

        // A recusa guardada continua só com os campos dela (sem o motivo).
        self::assertStringNotContainsString('TrabalhoExterno', self::corpo($fila, 'function normalizarRecusa(recusa) {'));

        // A tela põe os dois campos no corpo ANTES de entregá-lo à fila (as duas chamadas de `guardarNaFila`).
        $toque = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");
        $posicaoDoMotivo = strpos($toque, 'payload.motivoTrabalhoExterno = motivoExterno;');
        self::assertIsInt($posicaoDoMotivo);
        self::assertGreaterThan($posicaoDoMotivo, strpos($toque, 'guardarNaFila(payload)'));
        self::assertStringContainsString(
            'const entrada = Object.assign({}, payload, { usuarioId: FILA_USUARIO_ID, escritorioId: FILA_ESCRITORIO_ID });',
            self::corpo(self::tela(), 'async function guardarNaFila(payload) {'),
            'o corpo inteiro vai para a fila, que escolhe os campos'
        );
    }

    #[TestDox('Sem travessão nos textos novos que a pessoa vê')]
    public function testSemTravessao(): void
    {
        $textos = [
            self::corpo(self::modulo(), 'function textosDoForaDoRaio(onde, trabalhoExternoPermitido) {'),
            self::corpo(self::tela(), 'function mostrarForaDoRaio(alvo, foraDoRaio) {'),
            self::corpo(self::tela(), "botaoRegistrarExterno.addEventListener('click', function () {"),
            self::corpo(self::tela(), 'function mostrarForaDaArea(onde) {'),
            self::arquivo('templates/ponto/_janela_trabalho_externo.html.twig'),
            self::arquivo('templates/ponto/_config_trabalho_externo.html.twig'),
        ];
        foreach ($textos as $texto) {
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $texto);
        }
    }
}
