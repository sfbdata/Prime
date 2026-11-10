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
        $textos = self::corpo(self::modulo(), 'function textosDoForaDoRaio(posicao, sedes, trabalhoExternoPermitido) {');

        self::assertStringContainsString("const TITULO_FORA_DO_RAIO = 'Você está fora do raio permitido';", self::modulo());
        self::assertStringContainsString("let detalhe = 'O registro exige estar dentro do raio de uma sede do escritório.';", $textos, 'sem sede, a frase do desenho');
        self::assertStringContainsString(
            "detalhe = 'A sede mais próxima é ' + String(maisProxima.sede.nome) + ', a ' + Math.round(maisProxima.distancia).toLocaleString('pt-BR')\n"
            . "                + ' m. O raio permitido é de ' + Math.round(Number(maisProxima.sede.raio)).toLocaleString('pt-BR') + ' m.';",
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

    #[TestDox('A sede citada no texto local é a válida mais próxima em metros (L528, como o servidor); a cerca segue decidindo pela do menor excedente')]
    public function testSedeCitadaEAMaisProxima(): void
    {
        // Caso da revisão: A a 2.002 m com raio 100 e B a 3.002 m com raio 2.500. A cerca decide pela B (excedente 502
        // contra 1.902), e o texto cita a A, "a 2.002 m", raio 100. O container não tem node: o caso roda no node do
        // host fora da suíte; aqui fica travada a regra de cada lado.
        $maisProxima = self::corpo(self::modulo(), 'function sedeMaisProxima(posicao, sedes) {');
        self::assertStringContainsString('const medida = distanciaEmMetros(posicao.latitude, posicao.longitude, candidata.latitude, candidata.longitude);', $maisProxima, 'mede cada sede, com a conta da cerca');
        self::assertStringContainsString('const raio = Number(candidata.raio);', $maisProxima);
        self::assertStringContainsString(
            'if (Number.isFinite(medida) && Number.isFinite(raio) && raio > 0 && (maisProxima === null || medida < maisProxima.distancia)) {',
            $maisProxima,
            'escolhe só pela distância, entre as válidas (raio positivo); no empate fica a primeira, como no servidor'
        );
        self::assertStringContainsString('maisProxima = { sede: candidata, distancia: medida };', $maisProxima);
        self::assertDoesNotMatchRegularExpression('/medida\s*-|-\s*raio|excedente|avaliarPosicao\(|onde\./', $maisProxima, 'nada do menor excedente na sede citada');

        $textos = self::corpo(self::modulo(), 'function textosDoForaDoRaio(posicao, sedes, trabalhoExternoPermitido) {');
        self::assertStringContainsString('const maisProxima = sedeMaisProxima(posicao, sedes);', $textos, 'o bloco do toque cita a sede de sedeMaisProxima');
        self::assertDoesNotMatchRegularExpression('/excedente|avaliarPosicao\(|onde\.|distanciaEmMetros/', $textos, 'uma escolha só, a de sedeMaisProxima');
        self::assertMatchesRegularExpression('/window\.PontoBatida = Object\.freeze\(\{[^}]*\bsedeMaisProxima,\s+textosDoForaDoRaio,/', self::modulo(), 'exportada para a caixa da tela');

        $cerca = self::corpo(self::modulo(), 'function avaliarPosicao(posicao, sedes) {');
        self::assertStringContainsString('if ((distancia - sede.raio) < menorExcedente) {', $cerca, 'a cerca da tela continua pela decisiva');
        self::assertMatchesRegularExpression("/situacao: certamenteFora \? 'fora' : 'indeterminado',\s+distancia: distanciaDecisiva,\s+sede: decisiva,/", $cerca);
    }

    #[TestDox('A caixa da localização e o bloco do toque citam a mesma sede (a mais próxima, da mesma posição e das mesmas sedes); o "fora" segue o de hoje')]
    public function testCaixaEBlocoCitamAMesmaSede(): void
    {
        $tela = self::tela();

        // A caixa: o "fora" vem de avaliarPosicao, e a sede citada de sedeMaisProxima (com o módulo antigo, a de antes).
        self::assertMatchesRegularExpression(
            "/const onde = PontoBatida\.avaliarPosicao\(currentLocation, SEDES\);\s+if \(onde\.situacao === 'fora'\) \{\s+(?:\/\/[^\n]*\n\s+)*mostrarForaDaArea\(sedeCitadaNaTela\(currentLocation\) \|\| onde\);/",
            $tela
        );
        self::assertSame(1, substr_count($tela, 'mostrarForaDaArea(sedeCitadaNaTela('), 'a caixa nasce num lugar só');
        self::assertSame(2, substr_count($tela, 'mostrarForaDaArea('), 'a declaração e a chamada');
        self::assertStringContainsString(
            "return typeof PontoBatida.sedeMaisProxima === 'function' ? PontoBatida.sedeMaisProxima(posicao, SEDES) : null;",
            self::corpo($tela, 'function sedeCitadaNaTela(posicao) {')
        );
        $caixa = self::corpo($tela, 'function mostrarForaDaArea(onde) {');
        self::assertStringContainsString('nomeDaSede.textContent = onde.sede.nome;', $caixa);
        self::assertStringContainsString('`Você está a ${Math.round(onde.distancia)}m de `,', $caixa, 'a distância é a da sede citada');

        // O bloco (na tela vermelha da E8 ou, sem ela, na caixa): a mesma posição e as mesmas sedes, pela mesma função do
        // módulo (textosDoForaDoRaio usa sedeMaisProxima).
        self::assertStringContainsString('mostrarForaDoRaioDoToque(foraDoRaioDaTela(currentLocation), tipoRegistro.value, null);', $tela);
        self::assertStringContainsString('return PontoBatida.textosDoForaDoRaio(posicao, SEDES, TRABALHO_EXTERNO_PERMITIDO);', self::corpo($tela, 'function foraDoRaioDaTela(posicao) {'));
        self::assertStringContainsString('const maisProxima = sedeMaisProxima(posicao, sedes);', self::corpo(self::modulo(), 'function textosDoForaDoRaio(posicao, sedes, trabalhoExternoPermitido) {'));
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
        $bloco = self::corpo(self::tela(), 'function mostrarForaDoRaio(alvo, foraDoRaio, tipo) {');

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
        self::assertStringContainsString('abrirJanelaDoTrabalhoExterno(botao, tipo)', $botao, 'abre a janela "Trabalho externo" para o tipo que falhou');
        // Dois lugares: o bloco da caixa (função `ponto-animacao` desligada) e o botão da tela vermelha da E8, os dois só
        // com `trabalhoExterno` true (ver testBotaoExternoNaTelaVermelha).
        self::assertSame(2, substr_count(self::tela(), "'Registrar como trabalho externo'"), 'o bloco da caixa e a tela vermelha');
        self::assertStringContainsString(
            "'Registrar como trabalho externo'",
            self::corpo(self::corpo(self::tela(), 'function mostrarForaDoRaioDoToque(foraDoRaio, tipo, externo) {'), 'if (foraDoRaio.trabalhoExterno === true) {')
        );

        // Nenhum innerHTML da tela recebe o bloco, o motivo ou a resposta do servidor.
        preg_match_all('/\.innerHTML\s*=\s*(.*?);[ \t]*$/ms', self::tela(), $atribuicoes);
        self::assertNotEmpty($atribuicoes[1]);
        foreach ($atribuicoes[1] as $valor) {
            foreach (['foraDoRaio', 'motivo', 'Externo', 'resultado'] as $proibido) {
                self::assertStringNotContainsString($proibido, $valor, 'dado em innerHTML: ' . $valor);
            }
        }
    }

    #[TestDox('O "fora" local não envia: mostra o bloco (na tela vermelha da E8 ou, sem ela, na caixa de hoje) e sai antes da fila e do envio; o toque do trabalho externo passa')]
    public function testForaLocalNaoEnvia(): void
    {
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");

        $ramo = strpos($clique, "if (externo === null && onde.situacao === 'fora') {");
        self::assertIsInt($ramo, 'o toque de trabalho externo não para no "fora" local');
        $corpoDoRamo = self::corpo(substr($clique, $ramo), "if (externo === null && onde.situacao === 'fora') {");
        // E8: a mesma porta do fora do raio do servidor (`mostrarForaDoRaioDoToque`): a tela vermelha ou, com a função
        // `ponto-animacao` desligada, o bloco na caixa de hoje (testChaveDesligadaMantemOBlocoNaCaixa). Sem `externo`: este
        // toque é comum (o externo não passa por aqui).
        self::assertMatchesRegularExpression(
            "/\{\s+(?:\/\/[^\n]*\n\s+)*mostrarForaDoRaioDoToque\(foraDoRaioDaTela\(currentLocation\), tipoRegistro\.value, null\);\s+return;\s+\}$/",
            $corpoDoRamo,
            'o bloco do fora do raio para o tipo deste toque, e sai'
        );
        self::assertLessThan(strpos($clique, 'PontoBatida.enviarBatida('), $ramo, 'decide antes do envio');
        self::assertLessThan(strpos($clique, 'guardarNaFila(payload)'), $ramo, 'e antes da fila sem internet');
        self::assertLessThan(strpos($clique, 'envioEmAndamento = true;'), $ramo, 'nada começa: o botão não trava');

        $local = self::corpo(self::tela(), 'function foraDoRaioDaTela(posicao) {');
        self::assertStringContainsString('return PontoBatida.textosDoForaDoRaio(posicao, SEDES, TRABALHO_EXTERNO_PERMITIDO);', $local, 'a posição e as sedes da cerca; o botão local segue a opção do escritório');
        self::assertStringContainsString("typeof PontoBatida.textosDoForaDoRaio === 'function'", $local, 'módulo antigo no cache não lança no meio do toque');
        self::assertStringContainsString('trabalhoExterno: false', $local, 'sem o módulo novo, sem o botão');
    }

    #[TestDox('A recusa do servidor com foraDoRaio (403 fora, 422 GPS impreciso) vai à mesma porta do bloco, no ramo da recusa; a recusa do texto externo devolve à janela')]
    public function testRecusaDoServidorMostraOBloco(): void
    {
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");

        // E8: com foraDoRaio, a tela vermelha (ou o bloco na caixa) do tipo deste envio; a externa recusada de novo fora do
        // raio leva o `externo` para o "Tentar novamente". A recusa do texto da batida EXTERNA volta para a janela, sem a
        // tela vermelha. As outras recusas: a frase do servidor como título da tela vermelha.
        self::assertMatchesRegularExpression(
            "/\} else if \(resultado\.tipo === 'recusa'\) \{\s+(?:\/\/[^\n]*\n\s+)*"
            . "const foraDoRaio = foraDoRaioDaResposta\(resultado\.data\);\s+if \(foraDoRaio\) \{\s+mostrarForaDoRaioDoToque\(foraDoRaio, payload\.tipo, externo\);\s+\}"
            . " else if \(payload\.trabalhoExterno === true && resultado\.status === 422 && RECUSAS_DO_TEXTO_EXTERNO\.includes\(resultado\.mensagem\)\) \{\s+devolverExternoParaAJanela\(payload, resultado\.mensagem, false\);\s+\}"
            . " else \{\s+mostrarFalhaDoToque\(payload\.tipo, resultado\.mensagem, '', \[\], externo\);\s+\}\s+\} else if/",
            $clique,
            'sem foraDoRaio, a frase do servidor; com ele, o bloco do tipo deste envio; a recusa do texto externo reabre a janela'
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
            . "if \(externo !== null\) \{\s+payload\.tipo = externo\.tipo;\s+payload\.trabalhoExterno = true;\s+payload\.motivoTrabalhoExterno = externo\.motivo;\s+\}/",
            $clique,
            'o idEnvio é feito no toque (novo); o tipo que falhou e os dois campos entram no mesmo corpo, antes da fila e do envio'
        );
        $leitura = strpos($clique, 'const externo = trabalhoExternoDoToque;');
        self::assertIsInt($leitura);
        self::assertLessThan(strpos($clique, 'await '), $leitura, 'o tipo e o motivo são lidos antes do primeiro await, enquanto o clique ainda está em curso');
        self::assertLessThan(strpos($clique, 'guardarNaFila(payload)'), strpos($clique, 'payload.motivoTrabalhoExterno'), 'sem internet, a fila recebe o corpo com os dois campos');

        $registrar = self::corpo(self::tela(), 'function registrarComoTrabalhoExterno(motivo, tipo) {');
        self::assertMatchesRegularExpression(
            '/trabalhoExternoDoToque = \{ tipo: tipo \|\| tipoRegistro\.value, motivo \};\s+try \{\s+btnPonto\.focus\(\);\s+btnPonto\.click\(\);\s+\} finally \{\s+trabalhoExternoDoToque = null;\s+\}/',
            $registrar,
            'o mesmo toque do botão de bater; o tipo e o motivo não sobram para o toque seguinte'
        );
        self::assertStringNotContainsString('idEnvio', $registrar, 'nenhum identificador reaproveitado: o handler faz outro');
        self::assertStringNotContainsString('enviarBatida', $registrar, 'um envio só, o do botão');
        self::assertMatchesRegularExpression(
            "/if \(btnPonto\.disabled\) \{\s+mostrarAvisoBatida\(envioEmAndamento \? AVISO_EXTERNO_EM_ANDAMENTO : AVISO_EXTERNO_DIA_COMPLETO, 'warning'\);\s+return;\s+\}/",
            $registrar,
            'durante um envio ou com o dia completo, nada sai, e a caixa do resultado diz por quê'
        );
        self::assertStringContainsString("const AVISO_EXTERNO_EM_ANDAMENTO = 'Um registro ainda está em andamento. Espere a resposta e registre o trabalho externo de novo.';", self::tela());
        self::assertStringContainsString("const AVISO_EXTERNO_DIA_COMPLETO = 'Não há registro livre hoje. O trabalho externo não foi enviado.';", self::tela());
        self::assertSame(1, substr_count(self::tela(), 'trabalhoExternoDoToque = {'), 'só a janela põe o tipo e o motivo');
    }

    #[TestDox('A janela "Trabalho externo" usa a moldura do Ponto: abre e mostra o erro pelo PontoAbas, com a frase do desenho (L808)')]
    public function testJanelaNaMolduraDoPonto(): void
    {
        $abrir = self::corpo(self::tela(), 'function abrirJanelaDoTrabalhoExterno(origem, tipo, erro) {');
        self::assertStringContainsString('window.PontoAbas.abrirJanela(JANELA_DO_TRABALHO_EXTERNO, origem);', $abrir);
        self::assertMatchesRegularExpression(
            '/campo\.value = textoDoTrabalhoExterno;\s+if \(erro\) \{\s+window\.PontoAbas\.mostrarErro\(fundo, erro\);\s+\}\s+campo\.focus\(\);/',
            $abrir,
            'abre com o texto guardado na página (vazio na primeira vez, fMot: \'\' do desenho), o porquê na linha de erro e o foco no campo'
        );
        self::assertStringContainsString("const JANELA_DO_TRABALHO_EXTERNO = 'ponto-janela-externo';", self::tela());

        $registrar = self::corpo(self::tela(), "botaoRegistrarExterno.addEventListener('click', function () {");
        self::assertStringContainsString('const motivo = campo.value.trim();', $registrar);
        self::assertMatchesRegularExpression(
            "/if \(motivo === ''\) \{\s+window\.PontoAbas\.mostrarErro\(fundo, 'Informe o local e o motivo\.'\);\s+campo\.focus\(\);\s+return;\s+\}/",
            $registrar,
            'vazio: a frase do desenho na linha de erro da janela, e nada sai'
        );
        self::assertMatchesRegularExpression('/window\.PontoAbas\.fecharJanela\(\);\s+registrarComoTrabalhoExterno\(motivo, tipoDoTrabalhoExterno\);/', $registrar, 'com texto: fecha e envia a batida que falhou');

        // A marcação, sem o comentário do parcial (que cita o modal do Bootstrap para dizer que ele não entra).
        $janela = (string) preg_replace('/\{#.*?#\}/s', '', self::arquivo('templates/ponto/_janela_trabalho_externo.html.twig'));
        self::assertStringContainsString('class="pe-janela-fundo" id="ponto-janela-externo" hidden', $janela);
        self::assertDoesNotMatchRegularExpression('/class="[^"]*\b(?:modal|modal-dialog|btn|form-control|alert)\b/', $janela, 'componente do desenho, nada do Bootstrap');
        self::assertStringNotContainsString('data-bs-', $janela);
        self::assertStringNotContainsString('modal', $abrir);
        self::assertStringNotContainsString('bootstrap', $abrir);
    }

    #[TestDox('A batida externa vai com o tipo que falhou (L824, ctx.t), não com o escolhido na hora do "Registrar"')]
    public function testTipoQueFalhou(): void
    {
        $tela = self::tela();
        $clique = self::corpo($tela, "btnPonto.addEventListener('click', async function() {");

        // O bloco recebe o tipo do toque que falhou: o local (o escolhido no toque) e o do servidor (o do corpo enviado).
        // E8: as duas portas vão à mesma função, que leva o tipo à caixa e ao botão da tela vermelha.
        self::assertStringContainsString('mostrarForaDoRaioDoToque(foraDoRaioDaTela(currentLocation), tipoRegistro.value, null);', $clique);
        self::assertStringContainsString('mostrarForaDoRaioDoToque(foraDoRaio, payload.tipo, externo);', $clique);
        self::assertSame(3, substr_count($tela, 'mostrarForaDoRaioDoToque('), 'a declaração e as duas portas');
        $porta = self::corpo($tela, 'function mostrarForaDoRaioDoToque(foraDoRaio, tipo, externo) {');
        self::assertSame(1, substr_count($tela, 'mostrarForaDoRaio(avisoBatida,'), 'a caixa nasce num lugar só');
        self::assertStringContainsString('mostrarForaDoRaio(avisoBatida, foraDoRaio, tipo);', $porta, 'a caixa com o tipo que falhou');
        self::assertStringContainsString("abrirJanelaDoTrabalhoExterno(document.getElementById('ponto-registrar-titulo') || btnPonto, tipo)", $porta, 'a tela vermelha com o tipo que falhou');
        self::assertStringContainsString('mostrarFalhaDoToque(tipo, foraDoRaio.titulo, foraDoRaio.detalhe, botoes, externo);', $porta);

        // A janela guarda o tipo ao abrir, e o "Registrar" o leva ao toque.
        self::assertStringContainsString('let tipoDoTrabalhoExterno = null;', $tela);
        self::assertStringContainsString('tipoDoTrabalhoExterno = tipo || tipoRegistro.value;', self::corpo($tela, 'function abrirJanelaDoTrabalhoExterno(origem, tipo, erro) {'));
        self::assertSame(2, substr_count($tela, 'tipoDoTrabalhoExterno = '), 'a declaração e a abertura da janela: só ela muda o tipo');

        // O corpo troca o tipo do botão pelo da batida que falhou; o resto (sucesso, fila) lê payload.tipo.
        self::assertStringContainsString('payload.tipo = externo.tipo;', $clique);
        self::assertStringContainsString('batidaAceita = { tipo: payload.tipo, resposta: resultado.data };', $clique);
    }

    #[TestDox('O local e o motivo ficam guardados na página até a batida externa entrar ou ir para a fila; a janela volta com eles só quando a batida certamente não entrou (nunca sem confirmação nem sem rede) e nunca por cima de outra janela')]
    public function testTextoGuardadoNasBordas(): void
    {
        $tela = self::tela();
        self::assertStringContainsString("let textoDoTrabalhoExterno = '';", $tela);
        self::assertStringContainsString(
            'textoDoTrabalhoExterno = campoDoTrabalhoExterno.value;',
            self::corpo($tela, "campoDoTrabalhoExterno.addEventListener('input', function () {"),
            'o que a pessoa digita fica na página, mesmo fechando a janela'
        );
        self::assertMatchesRegularExpression(
            "/textoDoTrabalhoExterno = campo\\.value;\\s+const motivo = campo\\.value\\.trim\\(\\);/",
            self::corpo($tela, "botaoRegistrarExterno.addEventListener('click', function () {")
        );

        // Só sai em dois lugares: a batida externa entrou, ou ficou na fila (a declaração é o terceiro).
        self::assertSame(3, substr_count($tela, "textoDoTrabalhoExterno = '';"));
        $clique = self::corpo($tela, "btnPonto.addEventListener('click', async function() {");
        self::assertMatchesRegularExpression(
            "/atualizarDepoisDaBatida\\(avisos\\.length === 0\\);\\s+(?:\\/\\/[^\\n]*\\n\\s+)*if \\(payload\\.trabalhoExterno === true\\) \\{\\s+textoDoTrabalhoExterno = '';\\s+\\}\\s+\\} else if \\(resultado\\.tipo === 'recusa'\\) \\{/",
            $clique
        );
        self::assertMatchesRegularExpression(
            "/if \\(!\\(await window\\.PontoFila\\.guardar\\(entrada\\)\\)\\) \\{\\s+return false;\\s+\\}\\s+if \\(payload\\.trabalhoExterno === true\\) \\{\\s+textoDoTrabalhoExterno = '';\\s+\\}/",
            self::corpo($tela, 'async function guardarNaFila(payload) {'),
            'só depois de a fila confirmar que guardou'
        );

        // A janela volta com o texto e o porquê só quando a batida certamente não entrou: atrasado, não entrou e a recusa
        // do texto. Sem rede e sem confirmação, a batida pode ter entrado: fica só o aviso de hoje.
        // E8: a externa que não entrou não vai para a tela vermelha; `devolverExternoParaAJanela` fecha a validação, diz o
        // porquê na caixa e reabre a janela (testExternoQueNaoEntrouVoltaParaAJanela). O "não entrou" mantém o "Atualizar".
        self::assertMatchesRegularExpression(
            "/\\} else if \\(resultado\\.tipo === 'nao_entrou'\\) \\{\\s+(?:\\/\\/[^\\n]*\\n\\s+)*if \\(payload\\.trabalhoExterno === true\\) \\{\\s+devolverExternoParaAJanela\\(payload, AVISO_NAO_ENTROU, true\\);\\s+\\}"
            . " else \\{\\s+mostrarAvisoBatida\\(AVISO_NAO_ENTROU, 'danger', true\\);\\s+\\}\\s+\\} else if/",
            $clique
        );
        self::assertMatchesRegularExpression(
            "/\\} else if \\(resultado\\.tipo === 'sem_rede'\\) \\{\\s+(?:\\/\\/[^\\n]*\\n\\s+)*mostrarAvisoBatida\\(AVISO_SEM_REDE, 'danger', false\\);\\s+\\} else \\{/",
            $clique,
            'sem rede: só o aviso de hoje, sem convite a repetir (a batida pode ter entrado)'
        );
        self::assertMatchesRegularExpression(
            "/mostrarAvisoBatida\\(AVISO_SEM_CONFIRMACAO, 'danger', true\\);\\s+\\}\\s+\\} catch/",
            $clique,
            'sem confirmação (o else da classificação): só o aviso com o Conferir (P11 b), a janela não volta'
        );
        self::assertMatchesRegularExpression(
            "/if \\(resultado\\.tipo === 'atrasado'\\) \\{\\s+(?:\\/\\/[^\\n]*\\n\\s+)*if \\(payload\\.trabalhoExterno === true\\) \\{\\s+devolverExternoParaAJanela\\(payload, AVISO_ATRASADO, false\\);\\s+\\}"
            . " else \\{\\s+mostrarFalhaDoToque\\(payload\\.tipo, AVISO_ATRASADO, ''\\);\\s+\\}\\s+\\} else if/",
            $clique
        );
        self::assertStringContainsString(
            "const AVISO_ATRASADO = 'Demorou demais para enviar e a hora da batida ficaria errada. Aperte de novo com a tela ligada.';",
            $tela,
            'o porquê do atraso é o mesmo texto da E12'
        );
        self::assertSame(4, substr_count($tela, 'devolverExternoParaAJanela('), 'a função e as três respostas');
        self::assertSame(2, substr_count($tela, 'reabrirTrabalhoExterno('), 'a função e a volta à janela, num lugar só');
        self::assertStringContainsString('reabrirTrabalhoExterno(payload, porque);', self::corpo($tela, 'function devolverExternoParaAJanela(payload, porque, oferecerAtualizar) {'));
        self::assertStringNotContainsString('devolverExternoParaAJanela(payload, AVISO_SEM_CONFIRMACAO', $tela, 'sem confirmação a batida pode ter entrado');
        self::assertStringNotContainsString('devolverExternoParaAJanela(payload, AVISO_SEM_REDE', $tela, 'sem rede também');
        self::assertStringNotContainsString('reabrirTrabalhoExterno(payload, AVISO_SEM_CONFIRMACAO)', $tela, 'sem confirmação a batida pode ter entrado: um Registrar novo, com outro idEnvio, duplicaria');
        self::assertStringNotContainsString('reabrirTrabalhoExterno(payload, AVISO_SEM_REDE)', $tela, 'sem rede também: a batida pode ter entrado');
        self::assertStringContainsString(
            "const RECUSAS_DO_TEXTO_EXTERNO = ['Informe o local e o motivo.', 'O local e o motivo podem ter até 500 caracteres.'];",
            $tela,
            'as duas recusas do texto no servidor (TrabalhoExternoDaBatida); as outras 422 ficam na caixa'
        );

        $reabrir = self::corpo($tela, 'function reabrirTrabalhoExterno(payload, porque) {');
        self::assertMatchesRegularExpression(
            '/if \\(payload\\.trabalhoExterno !== true \\|\\| haJanelaDoPontoAberta\\(\\)\\) \\{\\s+return;\\s+\\}\\s+abrirJanelaDoTrabalhoExterno\\(btnPonto, payload\\.tipo, porque\\);/',
            $reabrir,
            'só a batida externa e só sem outra janela do Ponto aberta; com o tipo dela e o porquê na linha de erro'
        );
        // Outra janela aberta: a do PontoAbas é a .pe-janela-fundo sem hidden (o módulo não expõe o janelaAberta).
        self::assertStringContainsString(
            "return document.querySelector('.pe-janela-fundo:not([hidden])') !== null;",
            self::corpo($tela, 'function haJanelaDoPontoAberta() {')
        );
        $abas = self::arquivo('public/js/ponto-abas.js');
        self::assertMatchesRegularExpression('/fundo\\.hidden = false;\\s+janelaAberta = fundo;/', self::corpo($abas, 'function abrirJanela(id, origem) {'), 'abrir tira o hidden');
        self::assertMatchesRegularExpression('/janelaAberta\\.hidden = true;\\s+janelaAberta = null;/', self::corpo($abas, 'function fecharJanela() {'), 'fechar põe o hidden');
    }

    #[TestDox('E8: fora do raio com trabalhoExterno true, a tela vermelha leva o botão do desenho (L385), que fecha a tela cheia e abre a janela para o tipo que falhou')]
    public function testBotaoExternoNaTelaVermelha(): void
    {
        $tela = self::tela();
        $porta = self::corpo($tela, 'function mostrarForaDoRaioDoToque(foraDoRaio, tipo, externo) {');

        self::assertMatchesRegularExpression(
            '/const botoes = \[\];\s+if \(foraDoRaio\.trabalhoExterno === true\) \{\s+botoes\.push\(\{/',
            $porta,
            'o botão só com trabalhoExterno true (o do servidor ou o da opção do escritório, no local)'
        );
        $botao = self::corpo($porta, 'if (foraDoRaio.trabalhoExterno === true) {');
        self::assertStringContainsString("texto: 'Registrar como trabalho externo',", $botao, 'o rótulo do desenho');
        self::assertStringContainsString("icone: 'bi-briefcase',", $botao, 'a maleta do desenho (L385)');
        self::assertStringContainsString("classe: 'pe-tc-btn--externo',", $botao, 'o botão de borda branca do desenho');
        self::assertStringContainsString(
            "aoClicar: () => abrirJanelaDoTrabalhoExterno(document.getElementById('ponto-registrar-titulo') || btnPonto, tipo),",
            $botao,
            'abre a janela "Trabalho externo" com o tipo que falhou; ao fechar, o foco volta ao título do cartão'
        );
        self::assertMatchesRegularExpression(
            '/\}\s+mostrarFalhaDoToque\(tipo, foraDoRaio\.titulo, foraDoRaio\.detalhe, botoes, externo\);\s+\}$/',
            $porta,
            'o título e o detalhe do desenho (L528) na tela vermelha, com os botões a mais'
        );
        self::assertStringContainsString('botoes: Array.isArray(botoes) ? botoes : [],', self::corpo($tela, 'function mostrarFalhaDoToque(tipo, titulo, detalhe, botoes, externo) {'));

        // A tela vermelha põe o botão entre o "Tentar novamente" e o "Fechar", com o ícone e a classe dados, e fecha
        // antes de chamar o `aoClicar` (a janela abre com a tela cheia já fechada).
        $telaCheia = self::arquivo('public/js/ponto-tela-cheia.js');
        $aMais = self::corpo($telaCheia, 'function botaoAMais(botao) {');
        self::assertStringContainsString("el.className = 'pe-tc-btn ' + (botao.classe || 'pe-tc-btn--externo');", $aMais);
        self::assertStringContainsString("icone.className = 'bi ' + botao.icone;", $aMais);
        self::assertStringContainsString('el.appendChild(document.createTextNode(String(botao.texto || \'\')));', $aMais, 'o rótulo por texto, nunca por innerHTML');
        self::assertMatchesRegularExpression("/fechar\(\);\s+if \(typeof botao\.aoClicar === 'function'\) \{\s+botao\.aoClicar\(evento\);/", $aMais);
        self::assertStringContainsString('fecharBotao.parentNode.insertBefore(botaoAMais(botao), fecharBotao);', self::corpo($telaCheia, 'function erro(dados) {'));
        self::assertStringContainsString('.pe-tc-btn--externo { border: 1px solid #fff; background: transparent; color: #fff; font-weight: bold; }', self::arquivo('public/css/ponto-eletronico.css'));
    }

    #[TestDox('E8: a batida externa que com certeza não entrou (atrasada, não entrou, texto recusado) fecha a validação sem resultado e volta para a janela, sem a tela vermelha')]
    public function testExternoQueNaoEntrouVoltaParaAJanela(): void
    {
        $tela = self::tela();
        $devolver = self::corpo($tela, 'function devolverExternoParaAJanela(payload, porque, oferecerAtualizar) {');

        self::assertMatchesRegularExpression(
            "/\{\s+if \(window\.PontoTelaCheia\) \{\s+window\.PontoTelaCheia\.fecharSeValidando\(\);\s+\}\s+"
            . "mostrarAvisoBatida\(porque, 'danger', oferecerAtualizar\);\s+reabrirTrabalhoExterno\(payload, porque\);\s+\}$/",
            $devolver,
            'a tela cheia fecha ANTES de a janela abrir (o fechamento levaria o foco para fora dela); a caixa diz o porquê'
        );
        foreach (['mostrarFalhaDoToque', '.erro(', 'tocarResultado'] as $proibido) {
            self::assertStringNotContainsString($proibido, $devolver, 'a externa que não entrou não vai para a tela vermelha: ' . $proibido);
        }

        // As três respostas, cada uma só para a batida externa; o "Atualizar" só no "não entrou" (a sessão pode ter vencido).
        $clique = self::corpo($tela, "btnPonto.addEventListener('click', async function() {");
        self::assertStringContainsString('devolverExternoParaAJanela(payload, AVISO_ATRASADO, false);', $clique);
        self::assertStringContainsString('devolverExternoParaAJanela(payload, AVISO_NAO_ENTROU, true);', $clique);
        self::assertStringContainsString('devolverExternoParaAJanela(payload, resultado.mensagem, false);', $clique);
        self::assertSame(3, preg_match_all('/payload\.trabalhoExterno === true(?: &&[^{]*)?\) \{\s+devolverExternoParaAJanela\(/', $clique), 'só a batida externa volta para a janela');

        // A validação fecha só se ainda estiver na fase de validação, sem resultado na fila (a tela vermelha ou a verde
        // agendadas seguem).
        self::assertMatchesRegularExpression(
            "/if \(fase === 'val' && !resultadoAgendado\) \{\s+fechar\(\);\s+\}/",
            self::corpo(self::arquivo('public/js/ponto-tela-cheia.js'), 'function fecharSeValidando() {')
        );
    }

    #[TestDox('E8: o "Tentar novamente" da batida externa refaz a batida externa, com o mesmo motivo e um envio novo; nunca manda uma batida comum no lugar')]
    public function testTentarDeNovoDoExternoNuncaViraComum(): void
    {
        $tela = self::tela();

        self::assertStringContainsString('aoTentar: () => tentarDeNovo(tipo, externo || null),', self::corpo($tela, 'function mostrarFalhaDoToque(tipo, titulo, detalhe, botoes, externo) {'));

        $tentar = self::corpo($tela, 'function tentarDeNovo(tipo, externo) {');
        $ramoExterno = strpos($tentar, 'if (externo) {');
        self::assertIsInt($ramoExterno, 'o "Tentar novamente" sabe que a batida era externa');
        self::assertMatchesRegularExpression(
            '/if \(externo\) \{\s+registrarComoTrabalhoExterno\(externo\.motivo, tipo\);\s+return;\s+\}/',
            $tentar,
            'refaz pelo mesmo caminho do "Registrar" da janela: o tipo que falhou, o mesmo motivo e um idEnvio novo'
        );
        self::assertLessThan(strpos($tentar, 'btnPonto.click();'), $ramoExterno, 'sai antes do toque comum');
        self::assertLessThan($ramoExterno, strpos($tentar, 'if (!botao || botao.disabled) {'), 'o tipo já batido por outra via continua não sendo enviado');

        // As portas da tela vermelha no toque: as que podem ser de uma batida externa levam o `externo`; a do atraso sem
        // ele é só a da batida comum (a externa atrasada volta para a janela).
        $clique = self::corpo($tela, "btnPonto.addEventListener('click', async function() {");
        preg_match_all('/mostrarFalhaDoToque\([^;]*\);/', $clique, $chamadas);
        self::assertSame([
            'mostrarFalhaDoToque(externo !== null ? externo.tipo : tipoRegistro.value, TITULO_SEM_LOCALIZACAO, DETALHE_SEM_LOCALIZACAO, [], externo);',
            "mostrarFalhaDoToque(payload.tipo, AVISO_ATRASADO, '');",
            "mostrarFalhaDoToque(payload.tipo, resultado.mensagem, '', [], externo);",
        ], $chamadas[0]);
        self::assertMatchesRegularExpression(
            "/if \(payload\.trabalhoExterno === true\) \{\s+devolverExternoParaAJanela\(payload, AVISO_ATRASADO, false\);\s+\} else \{\s+mostrarFalhaDoToque\(payload\.tipo, AVISO_ATRASADO, ''\);/",
            $clique
        );
        self::assertStringContainsString('mostrarFalhaDoToque(tipo, foraDoRaio.titulo, foraDoRaio.detalhe, botoes, externo);', self::corpo($tela, 'function mostrarForaDoRaioDoToque(foraDoRaio, tipo, externo) {'));
    }

    #[TestDox('E8: com a função ponto-animacao desligada (sem a tela cheia), o fora do raio continua no bloco da E12 na caixa de hoje, com o botão do trabalho externo')]
    public function testChaveDesligadaMantemOBlocoNaCaixa(): void
    {
        $tela = self::tela();
        $porta = self::corpo($tela, 'function mostrarForaDoRaioDoToque(foraDoRaio, tipo, externo) {');

        self::assertMatchesRegularExpression(
            "/^function mostrarForaDoRaioDoToque\(foraDoRaio, tipo, externo\) \{\s+if \(!telaCheiaVale\(\)\) \{\s+"
            . "tocarResultado\('erro'\);\s+mostrarAvisoBatida\('', 'danger'\);\s+mostrarForaDoRaio\(avisoBatida, foraDoRaio, tipo\);\s+return;\s+\}/",
            $porta,
            'o bloco (com o botão dele) na caixa de hoje; o aviso flutuante não leva botão, então não é usado aqui'
        );
        self::assertStringContainsString(
            'return ANIMACAO_LIGADA && !!window.PontoTelaCheia && window.PontoTelaCheia.disponivel();',
            self::corpo($tela, 'function telaCheiaVale() {'),
            'a chave da função e o módulo carregado'
        );
        self::assertStringContainsString('abrirJanelaDoTrabalhoExterno(botao, tipo)', self::corpo($tela, 'function mostrarForaDoRaio(alvo, foraDoRaio, tipo) {'), 'o botão do bloco abre a mesma janela');
    }

    #[TestDox('Fila antiga no cache (sem LEVA_TRABALHO_EXTERNO): sem internet antes do envio, não guarda e diz por quê; envio sem resposta, o aviso de sem confirmação')]
    public function testFilaAntigaNaoGuardaOExterno(): void
    {
        self::assertMatchesRegularExpression(
            '/window\\.PontoFila = Object\\.freeze\\(\\{[^}]*\\bLEVA_TRABALHO_EXTERNO: true,\\s+\\}\\);/',
            self::arquivo('public/js/ponto-fila.js'),
            'a fila nova diz que leva os dois campos'
        );

        $guardar = self::corpo(self::tela(), 'async function guardarNaFila(payload) {');
        self::assertMatchesRegularExpression(
            "/if \\(!payload\\.idEnvio \\|\\| !filaDisponivel\\(\\)\\) \\{\\s+return false;\\s+\\}\\s+"
            . "if \\(filaAntigaParaOExterno\\(payload\\)\\) \\{\\s+"
            . "mostrarAvisoBatida\\(AVISO_EXTERNO_SEM_FILA, 'danger'\\);\\s+return true;\\s+\\}\\s+const entrada = /",
            $guardar,
            'antes de guardar; true encerra o toque (nada é enviado), e o texto fica na página'
        );
        self::assertStringContainsString(
            'return payload.trabalhoExterno === true && filaDisponivel() && window.PontoFila.LEVA_TRABALHO_EXTERNO !== true;',
            self::corpo(self::tela(), 'function filaAntigaParaOExterno(payload) {')
        );
        self::assertStringContainsString(
            "const AVISO_EXTERNO_SEM_FILA = 'Sem internet, o trabalho externo não pôde ser guardado. Tente de novo quando a conexão voltar.';",
            self::tela()
        );
        self::assertSame(2, substr_count(self::tela(), 'AVISO_EXTERNO_SEM_FILA'), 'a declaração e o uso no guardarNaFila');

        // O "Sem internet ... tente de novo" só vale ANTES do envio (o caminho offline do toque, antes de enviarBatida).
        // Depois de um envio sem resposta, a batida pode ter entrado: sem internet, o aviso de sem rede SEM o botão de
        // atualizar; com internet, o de sem confirmação com o Conferir (P11 b). O toque para antes de chegar à fila.
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");
        self::assertMatchesRegularExpression(
            "/if \\(resultado\\.falhaDeRede && filaAntigaParaOExterno\\(payload\\)\\) \\{\\s+"
            . "const semRede = resultado\\.tipo === 'sem_rede';\\s+"
            . "mostrarAvisoBatida\\(semRede \\? AVISO_SEM_REDE : AVISO_SEM_CONFIRMACAO, 'danger', !semRede\\);\\s+return;\\s+\\}\\s+"
            . "(?:\\/\\/[^\\n]*\\n\\s+)*if \\(resultado\\.falhaDeRede && await guardarNaFila\\(payload\\)\\) \\{/",
            $clique
        );
        self::assertLessThan(
            strpos($clique, 'if (resultado.falhaDeRede && filaAntigaParaOExterno(payload)) {'),
            strpos($clique, 'PontoBatida.enviarBatida('),
            'depois do envio'
        );
        self::assertLessThan(
            strpos($clique, 'PontoBatida.enviarBatida('),
            strpos($clique, 'if (navigator.onLine === false && await guardarNaFila(payload)) {'),
            'o caminho que chega ao AVISO_EXTERNO_SEM_FILA é o de antes do envio'
        );
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
        $posicaoDoMotivo = strpos($toque, 'payload.motivoTrabalhoExterno = externo.motivo;');
        self::assertIsInt($posicaoDoMotivo);
        self::assertGreaterThan($posicaoDoMotivo, strpos($toque, 'guardarNaFila(payload)'));
        self::assertStringContainsString(
            'const entrada = Object.assign({}, payload, { usuarioId: FILA_USUARIO_ID, escritorioId: FILA_ESCRITORIO_ID });',
            self::corpo(self::tela(), 'async function guardarNaFila(payload) {'),
            'o corpo inteiro vai para a fila, que escolhe os campos'
        );
    }

    #[TestDox('"Registrar assim mesmo" (E11) de uma batida de trabalho externo refaz o toque externo, com o tipo e o motivo, e não deixa a marca para o próximo toque')]
    public function testAlertaDaBatidaExternaMantemOTrabalhoExterno(): void
    {
        $abrir = self::corpo(self::tela(), 'function abrirAntesDeRegistrar(texto, payload) {');
        self::assertMatchesRegularExpression(
            '/if \(payload\.trabalhoExterno === true\) \{\s*alertaEmEspera\.externo = \{ tipo: payload\.tipo, motivo: payload\.motivoTrabalhoExterno \};\s*\}/',
            $abrir,
            'a janela do alerta guarda a marca e o motivo da batida externa'
        );

        $registrar = self::corpo(self::tela(), "janelaDoAlerta.querySelector('[data-alerta-registrar]').addEventListener('click', function () {");
        $marca = strpos($registrar, 'trabalhoExternoDoToque = batida.externo || null;');
        self::assertIsInt($marca, 'a confirmação refaz o toque como externo');
        self::assertGreaterThan($marca, strpos($registrar, 'btnPonto.click();'), 'a marca vem antes do toque');
        self::assertGreaterThan(
            strpos($registrar, 'btnPonto.click();'),
            strrpos($registrar, 'trabalhoExternoDoToque = null;'),
            'e sai logo depois, para o próximo toque ser comum'
        );

        // A confirmação muda só o tipo, o envio e a própria marca; a do trabalho externo e o motivo ficam como o toque montou.
        $aplicar = self::corpo(self::tela(), 'function aplicarConfirmacaoDoAlerta(payload) {');
        self::assertStringNotContainsString('trabalhoExterno', $aplicar);
        self::assertStringNotContainsString('motivoTrabalhoExterno', $aplicar);
        self::assertStringNotContainsString('delete ', $aplicar);

        // O toque lê a marca antes da cerca da tela e aplica a confirmação depois de montar o corpo externo.
        $toque = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");
        self::assertGreaterThan(
            strpos($toque, 'payload.motivoTrabalhoExterno = externo.motivo;'),
            strpos($toque, 'aplicarConfirmacaoDoAlerta(payload);')
        );
    }

    #[TestDox('Sem travessão nos textos novos que a pessoa vê')]
    public function testSemTravessao(): void
    {
        $textos = [
            self::corpo(self::modulo(), 'function textosDoForaDoRaio(posicao, sedes, trabalhoExternoPermitido) {'),
            self::corpo(self::tela(), 'function mostrarForaDoRaio(alvo, foraDoRaio, tipo) {'),
            self::corpo(self::tela(), 'function registrarComoTrabalhoExterno(motivo, tipo) {'),
            (string) preg_replace('/^(?!\s*const (?:AVISO_EXTERNO_|RECUSAS_DO_TEXTO_EXTERNO)).*$/m', '', self::tela()),
            self::arquivo('templates/ponto/_abas_justificativas.html.twig'),
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
