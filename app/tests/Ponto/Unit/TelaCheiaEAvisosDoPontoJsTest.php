<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E8 do desenho novo do Ponto (desenho 16, spec docs/specs/ponto-desenho-0811.md, P79 a P83): o que o JavaScript e o
 * CSS da tela cheia, do aviso flutuante, dos sons e do lembrete precisam guardar. PHPUnit não roda o JavaScript: aqui
 * ficam travados os números e os textos do desenho, as guardas (Esc, mudo, chave desligada, movimento reduzido) e a
 * convivência com o som do alerta de horário. A marcação é do `TelaCheiaEAvisosDoPontoTest`; a tela em ação é do smoke
 * do dono.
 */
#[Group('ponto')]
final class TelaCheiaEAvisosDoPontoJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../..';

    #[TestDox('Tela cheia: os tempos do desenho (2.800 ms; erro em 55%, sucesso no fim; fecha em 10 s) e a espera da resposta')]
    public function testTemposDaTelaCheia(): void
    {
        $js = self::arquivo('public/js/ponto-tela-cheia.js');

        self::assertStringContainsString('const VAL_T = 2800;', $js);
        self::assertStringContainsString('const ERRO_EM_MS = Math.round(VAL_T * 0.55);', $js);
        self::assertStringContainsString('const OK_EM_MS = VAL_T;', $js);
        self::assertStringContainsString('const FECHA_OK_MS = 10000;', $js);
        self::assertStringContainsString('const TENTAR_DEPOIS_MS = 60;', $js);
        // A resposta que chega depois do instante do desenho aparece na hora; antes dele, espera o instante.
        self::assertStringContainsString("const espera = fase === 'val' ? inicio + emMs - performance.now() : 0;", $js);
        self::assertStringContainsString('setTimeout(mostrar, Math.max(0, espera));', $js);
        self::assertStringContainsString('agendar(ERRO_EM_MS, function () {', $js);
        self::assertStringContainsString('agendar(OK_EM_MS, function () {', $js);
        self::assertStringContainsString('tempoDoFechamento = setTimeout(fechar, FECHA_OK_MS);', $js);
    }

    #[TestDox('Tela cheia: Esc fecha só no sucesso e no erro (nunca na validação), o foco fica preso nela e vai ao botão principal')]
    public function testTecladoEFocoDaTelaCheia(): void
    {
        $js = self::arquivo('public/js/ponto-tela-cheia.js');

        self::assertStringContainsString("if (evento.key === 'Escape' && (fase === 'ok' || fase === 'erro')) {", $js);
        self::assertSame(1, substr_count($js, "'Escape'"), 'nenhum outro caminho do Esc');
        self::assertStringContainsString("} else if (evento.key === 'Tab') {", $js);
        self::assertStringContainsString("focar('[data-tc-concluir]');", $js);
        self::assertStringContainsString("focar('[data-tc-tentar]');", $js);
        // Ao fechar, o foco vai ao destino configurado pela tela (na /ponto, o título do cartão); sem ele, a quem o tinha.
        self::assertStringContainsString('const destino = focoAoFechar ? focoAoFechar() : focoAntes;', $js);
        self::assertStringContainsString('destino.focus();', $js);
        // Sem resultado para a tela (sem rede, sem confirmação, guardada), a validação fecha e a caixa responde.
        self::assertStringContainsString("if (fase === 'val' && !resultadoAgendado) {", $js);
    }

    #[TestDox('Tela cheia: a API da E12 (botões a mais na tela vermelha, antes do "Fechar"), só com textContent')]
    public function testApiDaTelaVermelha(): void
    {
        $js = self::arquivo('public/js/ponto-tela-cheia.js');

        self::assertStringContainsString('window.PontoTelaCheia = Object.freeze({', $js);
        foreach (['disponivel,', 'validar,', 'ok,', 'erro,', 'fechar,', 'fecharSeValidando,'] as $parte) {
            self::assertStringContainsString($parte, $js);
        }
        self::assertStringContainsString("el.className = 'pe-tc-btn ' + (botao.classe || 'pe-tc-btn--externo');", $js);
        self::assertStringContainsString('fecharBotao.parentNode.insertBefore(botaoAMais(botao), fecharBotao);', $js);
        self::assertStringContainsString("const fecharBotao = el.querySelector('[data-tc-fase=\"erro\"] [data-tc-fechar]');", $js);
        self::assertStringContainsString('no.textContent = String(texto || \'\');', $js);
        self::assertStringNotContainsString('innerHTML', $js);

        $css = self::arquivo('public/css/ponto-eletronico.css');
        self::assertStringContainsString('.pe-tc-btn--externo { border: 1px solid #fff; background: transparent; color: #fff; font-weight: bold; }', $css);
        self::assertStringContainsString('.pe-tc-btn--externo:hover { background: rgba(255, 255, 255, .14); }', $css);
    }

    #[TestDox('Tela cheia no CSS: os valores do desenho (fundo, z-index, cortinas, anel) e o movimento reduzido zerando as animações')]
    public function testCssDaTelaCheia(): void
    {
        $css = self::arquivo('public/css/ponto-eletronico.css');

        self::assertMatchesRegularExpression('/\.pe-tc \{[^}]*position: fixed;[^}]*inset: 0;[^}]*z-index: 2147483646;[^}]*overflow-y: auto;[^}]*background: #0b3550;[^}]*color: #fff;/s', $css);
        self::assertStringContainsString('.pe-tc[hidden] { display: none; }', $css);
        self::assertStringContainsString('.pe-tc-fase { display: contents; }', $css);
        self::assertStringContainsString('.pe-tc-fase[hidden] { display: none; }', $css);
        self::assertStringContainsString('.pe-tc-anel { --r: min(31vmin, 168px);', $css);
        self::assertStringContainsString('.pe-tc-anel--mini { --r: min(19vmin, 96px); }', $css);
        self::assertStringContainsString('.pe-tc-cortina--ok { background: #1e7b45; animation: pe-tc-wipe .6s ease-out both; }', $css);
        self::assertStringContainsString('.pe-tc-cortina--erro { background: #a3232b; animation: pe-tc-wipe .45s ease-out both; }', $css);
        self::assertStringContainsString('.pe-tc-barra--on { animation: pe-tc-bar-on .28s ease-out forwards; }', $css);
        self::assertStringContainsString('animation: pe-tc-barra 10s linear both;', $css);
        self::assertStringContainsString('.pe-tc-btn--tentar { border: none; background: #fff; color: #7f1c23; font-weight: bold; }', $css);
        self::assertStringContainsString('.pe-tc-btn--tentar:hover { background: #fdecee; }', $css);
        foreach (['pe-tc-bar-on', 'pe-tc-bar-ok', 'pe-tc-draw', 'pe-tc-wipe', 'pe-tc-in', 'pe-tc-barra', 'pe-tc-treme', 'pe-tc-etapa', 'pe-tc-up'] as $quadro) {
            self::assertStringContainsString('@keyframes ' . $quadro . ' {', $css);
        }

        self::assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{\s*\.pe-tc-barra--on, \.pe-tc-barra--ok, \.pe-tc-mov \{ animation-duration: \.01ms !important; animation-delay: 0s !important; \}\s*\}/',
            $css,
            'o movimento reduzido do desenho (L72 a L75)',
        );
        $marcacao = self::arquivo('templates/ponto/_tela_cheia.html.twig');
        // Os `bjpMov` do desenho: etapas, as duas cortinas, o check e os dois traços do X, textos e botões do sucesso, a
        // barra do tempo e o tremor do erro.
        // 11 no fonte: a linha das etapas se divide em duas (a última, o selo, sem o atraso no `style`).
        self::assertSame(11, substr_count($marcacao, 'pe-tc-mov"'));
        self::assertStringContainsString('class="pe-tc-traco pe-tc-traco--check pe-tc-mov"', $marcacao);
        self::assertStringContainsString('class="pe-tc-traco pe-tc-traco--x1 pe-tc-mov"', $marcacao);
        self::assertStringContainsString('class="pe-tc-traco pe-tc-traco--x2 pe-tc-mov"', $marcacao);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $marcacao, 'sem travessão');
    }

    #[TestDox('Aviso flutuante (P80, L266): some em 3.400 ms, um por vez, nos quatro tons do desenho, só com textContent')]
    public function testAvisoFlutuante(): void
    {
        $js = self::arquivo('public/js/ponto-aviso-flutuante.js');

        self::assertStringContainsString('const TEMPO_MS = 3400;', $js);
        self::assertStringContainsString("const TONS = ['ok', 'erro', 'lembrete', 'gerando'];", $js);
        self::assertStringContainsString('clearTimeout(prazo);', $js, 'um aviso novo troca o da tela');
        self::assertStringContainsString("el.setAttribute('role', 'status');", $js);
        self::assertStringContainsString('el.textContent = String(texto || \'\');', $js);
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringContainsString("el.getAttribute('data-aviso-na-carga')", $js);

        $css = self::arquivo('public/css/ponto-eletronico.css');
        self::assertMatchesRegularExpression('/\.pe-flutuante \{[^}]*position: fixed;[^}]*left: 50%;[^}]*bottom: 22px;[^}]*transform: translateX\(-50%\);[^}]*z-index: 2147483000;[^}]*max-width: calc\(100vw - 28px\);[^}]*padding: 11px 16px;[^}]*border-radius: 3px;[^}]*box-shadow: 0 8px 24px rgba\(18, 36, 47, \.25\);[^}]*font-size: 14px;[^}]*animation: pe-tc-up \.2s ease both;/s', $css);
        self::assertStringContainsString('.pe-flutuante--ok { background: #1e7b45; }', $css);
        self::assertStringContainsString('.pe-flutuante--erro { background: #a3232b; }', $css);
        self::assertStringContainsString('.pe-flutuante--lembrete { background: #8a6200; }', $css);
        self::assertStringContainsString('.pe-flutuante--gerando { background: #0a6fa0; }', $css);
    }

    #[TestDox('Sons (P81): os números do desenho, a vibração, o mudo em localStorage dentro de try/catch e a chave desligada')]
    public function testSons(): void
    {
        $js = self::arquivo('public/js/ponto-sons.js');

        foreach (['tom(1568, 0, 0.55, 0.26);', 'tom(3136, 0, 0.25, 0.06);', 'tom(2093, 0.1, 0.85, 0.3);', 'tom(4186, 0.1, 0.35, 0.07);'] as $tom) {
            self::assertStringContainsString($tom, $js, 'somOk do desenho');
        }
        self::assertStringContainsString("tom(233, 0, 0.2, 0.3, 'square');", $js);
        self::assertStringContainsString("tom(185, 0.24, 0.36, 0.3, 'square');", $js);
        self::assertStringContainsString('ganho.gain.exponentialRampToValueAtTime(volume, t + 0.012);', $js);
        self::assertStringContainsString('oscilador.stop(t + duracao + 0.05);', $js);
        self::assertStringContainsString('vibrar(40);', $js);
        self::assertStringContainsString('vibrar([80, 60, 80]);', $js);
        self::assertMatchesRegularExpression('/try \{\s*if \(navigator\.vibrate\) \{\s*navigator\.vibrate\(padrao\);/', $js);

        self::assertStringContainsString("const CHAVE_MUDO = 'bj-ponto-mudo';", $js);
        self::assertMatchesRegularExpression('/try \{\s*return JSON\.parse\(localStorage\.getItem\(CHAVE_MUDO\)\) === true;\s*\} catch/', $js);
        self::assertMatchesRegularExpression('/try \{\s*localStorage\.setItem\(CHAVE_MUDO, JSON\.stringify\(!!valor\)\);\s*\} catch/', $js);
        self::assertStringContainsString('return ligados && !mudo();', $js, 'a chave desligada e o mudo calam o som');
        self::assertMatchesRegularExpression('/function liberar\(\) \{\s*if \(!ligados\) \{\s*return;/', $js, 'chave desligada: nem cria o contexto');
        self::assertStringContainsString("const DICA_SILENCIAR = 'Silenciar sons';", $js);
        self::assertStringContainsString("const DICA_ATIVAR = 'Ativar sons';", $js);
        self::assertStringContainsString("botao.setAttribute('aria-label', dica);", $js);
    }

    #[TestDox('O som do alerta de horário continua liberado pela primeira interação: os módulos novos não param nem cancelam o evento')]
    public function testAudioDoAlertaIntacto(): void
    {
        $alerta = self::arquivo('public/js/ponto-alerta-som.js');
        self::assertStringContainsString("addEventListener('pointerdown', liberarAudio, { once: true, capture: true });", $alerta);
        self::assertStringContainsString("addEventListener('keydown', liberarAudio, { once: true, capture: true });", $alerta);
        self::assertStringContainsString("addEventListener('touchend', liberarAudio, { once: true, capture: true });", $alerta);

        foreach (['public/js/ponto-sons.js', 'public/js/ponto-tela-cheia.js', 'public/js/ponto-aviso-flutuante.js'] as $arquivo) {
            $js = self::arquivo($arquivo);
            self::assertStringNotContainsString('stopPropagation', $js, $arquivo);
            self::assertStringNotContainsString('stopImmediatePropagation', $js, $arquivo);
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $js, 'sem travessão: ' . $arquivo);
        }
        // O som da batida tem o próprio contexto: não chama nem troca o do alerta.
        self::assertDoesNotMatchRegularExpression('/BJSomDoAlertaDePonto\s*[.(=]/', self::arquivo('public/js/ponto-sons.js'));
        // O contexto nasce ou volta no gesto do toque, antes de qualquer `await`.
        self::assertMatchesRegularExpression(
            '/window\.PontoBatida\.pedirPermissaoDeNotificacao\(\);\s*(?:\/\/[^\n]*\n\s*)*if \(window\.PontoSons\) \{\s*window\.PontoSons\.liberar\(\);/',
            self::tela(),
        );
    }

    #[TestDox('Toque: o gênero de cada tipo, os textos do desenho e o resultado sem tela cheia no aviso flutuante')]
    public function testTextosDoToque(): void
    {
        $tela = self::tela();

        self::assertStringContainsString('const TIPO_MASCULINO = { repouso: true, retorno: true };', $tela, 'Entrada e Saída registradas; Repouso e Retorno registrados');
        self::assertStringContainsString("return TIPO_MASCULINO[tipo] === true ? 'registrado' : 'registrada';", $tela);
        self::assertStringContainsString("const texto = jaRegistrada ? AVISO_JA_REGISTRADA : rotuloDoTipo(tipo) + ' ' + registradoDoTipo(tipo) + ' às ' + horaDaBatida + '.';", $tela);
        self::assertStringContainsString("const texto = rotuloDoTipo(tipo) + ' não ' + registradoDoTipo(tipo) + ': ' + String(titulo || '').replace(/[.\\s]+$/, '') + '.';", $tela);
        self::assertStringContainsString("const TITULO_SEM_LOCALIZACAO = 'Não foi possível obter a sua localização';", $tela);
        self::assertStringContainsString("const DETALHE_SEM_LOCALIZACAO = 'Ative o GPS do aparelho, permita a localização no navegador e tente de novo.';", $tela);

        // Com a chave valendo e a tela cheia na página, a validação abre no toque e o resultado sai nela.
        self::assertStringContainsString('return ANIMACAO_LIGADA && !!window.PontoTelaCheia && window.PontoTelaCheia.disponivel();', $tela);
        // E12 + E8: entre os dois, só o tipo e os campos da batida externa (a validação mostra o tipo que vai ser enviado).
        self::assertMatchesRegularExpression('/const payload = \{ tipo: tipoRegistro\.value, idEnvio: idDoEnvio\(\), horaAparelho \};\s*(?:\/\/[^\n]*\n\s*)*if \(externo !== null\) \{[^{}]*\}\s*(?:\/\/[^\n]*\n\s*)*iniciarValidacaoDoToque\(payload\.tipo\);/', $tela);
        $registrada = self::corpo($tela, 'function mostrarBatidaRegistrada(tipoDoToque, dados) {');
        // O texto do sucesso sai de `data.tipo` e `data.hora` do servidor, sem o comprovante (E15).
        self::assertStringContainsString("const tipo = typeof gravada.tipo === 'string' && Object.prototype.hasOwnProperty.call(ROTULOS_TIPO, gravada.tipo) ? gravada.tipo : tipoDoToque;", $registrada);
        self::assertStringContainsString("const horaDaBatida = typeof gravada.hora === 'string' && gravada.hora ? gravada.hora : horaOficialAgora();", $registrada);
        self::assertStringNotContainsString('Comprovante', $registrada);
        self::assertMatchesRegularExpression('/if \(telaCheiaVale\(\)\) \{\s*window\.PontoTelaCheia\.ok\(/', $registrada);
        self::assertStringContainsString("tocarResultado('ok');", $registrada);
        self::assertStringContainsString("if (!avisarFlutuante(texto, 'ok')) {", $registrada);
        $falha = self::corpo($tela, 'function mostrarFalhaDoToque(tipo, titulo, detalhe, botoes, externo) {');
        self::assertStringContainsString('window.PontoTelaCheia.erro({', $falha);
        // E12 + E8: o "Tentar novamente" da batida externa a refaz externa (TrabalhoExternoForaDoRaioJsTest).
        self::assertStringContainsString('aoTentar: () => tentarDeNovo(tipo, externo || null),', $falha);
        self::assertStringContainsString("tocarResultado('erro');", $falha);
        self::assertStringContainsString("if (!avisarFlutuante(texto, 'erro')) {", $falha);
        // A validação sem resultado fecha no `finally`, e a caixa responde (sem rede, sem confirmação, guardada).
        self::assertMatchesRegularExpression('/\} finally \{\s*(?:\/\/[^\n]*\n\s*)*if \(window\.PontoTelaCheia\) \{\s*window\.PontoTelaCheia\.fecharSeValidando\(\);\s*\}\s*if \(!sucesso\) \{\s*liberarBotaoBatida\(\);/', $tela);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', self::corpo($tela, 'function mostrarFalhaDoToque(tipo, titulo, detalhe, botoes, externo) {'));
    }

    #[TestDox('Lembrete de saída (P83): só com a chave; uma vez por dia; plim, aviso âmbar e notificação permitida; desligado, sem "Saída prevista"')]
    public function testLembreteDeSaida(): void
    {
        $tela = self::tela();

        $aviso = self::corpo($tela, 'function atualizarAvisoDoDia(agora) {');
        self::assertMatchesRegularExpression(
            "/if \\(!LEMBRETE_LIGADO\\) \\{\\s*mostrarAvisoDoDia\\('', '', ''\\);\\s*return;\\s*\\}\\s*const previsao = tSaida \\? null : saidaPrevista\\(\\);/",
            $aviso,
            'desligado: some a saída prevista, antes de qualquer conta',
        );
        self::assertMatchesRegularExpression("/if \\(agora > previsao\\) \\{\\s*mostrarAvisoDoDia\\('warning', 'bi-bell', 'Passou da saída prevista \\(' \\+ hora \\+ '\\)\\. Não esqueça de registrar a saída\\.'\\);\\s*return;/", $aviso);
        self::assertStringNotContainsString('dispararLembreteDeSaida();', $aviso, 'o lembrete não depende da escolha da caixa');
        self::assertStringContainsString("mostrarAvisoDoDia('info', 'bi-bell', 'Saída prevista às ' + hora + '.');", $aviso);

        $lembrete = self::corpo($tela, 'function dispararLembreteDeSaida() {');
        self::assertMatchesRegularExpression('/^\{\s*if \(!LEMBRETE_LIGADO\) \{\s*return;/', $lembrete);
        self::assertStringContainsString('if (lembreteDadoNoDia === dia) {', $lembrete, 'uma vez por dia na página');
        self::assertMatchesRegularExpression('/try \{\s*if \(localStorage\.getItem\(chave\) === dia\) \{\s*return;\s*\}\s*localStorage\.setItem\(chave, dia\);\s*\} catch/', $lembrete, 'uma vez por dia no aparelho');
        self::assertStringContainsString("'bj-ponto-lembrete:' + (caixaDaPessoa ? caixaDaPessoa.dataset.usuarioId + ':' + caixaDaPessoa.dataset.escritorioId : '')", $lembrete, 'por pessoa e escritório');
        self::assertMatchesRegularExpression('/window\.PontoSons\.liberar\(\);\s*window\.PontoSons\.ok\(\);/', $lembrete);
        self::assertStringContainsString("avisarFlutuante(AVISO_LEMBRETE_DE_SAIDA, 'lembrete');", $lembrete);
        self::assertMatchesRegularExpression("/try \\{\\s*if \\(window\\.Notification && Notification\\.permission === 'granted'\\) \\{\\s*new Notification\\('BlueJus Ponto', \\{ body: 'Não esqueça de registrar a saída\\.' \\}\\);/", $lembrete);
        self::assertStringContainsString("const AVISO_LEMBRETE_DE_SAIDA = 'Passou do horário previsto. Não esqueça de registrar a saída.';", $tela);

        // As chaves são declaradas antes do relógio, que já chama o lembrete na carga (`const` não é içado).
        self::assertLessThan(strpos($tela, 'setInterval(updateClock, 1000);'), strpos($tela, 'const LEMBRETE_LIGADO = '));
        self::assertLessThan(strpos($tela, 'setInterval(updateClock, 1000);'), strpos($tela, 'const AVISO_LEMBRETE_DE_SAIDA = '));
        self::assertLessThan(strpos($tela, 'setInterval(updateClock, 1000);'), strpos($tela, 'let lembreteDadoNoDia = '));
    }

    #[TestDox('Batida confirmada pelo servidor: entra no registro da página e nenhuma busca descartada ou que falhou devolve o tipo livre')]
    public function testBatidaConfirmadaNaoVoltaLivre(): void
    {
        $tela = self::tela();

        // O toque aceito registra a batida com o tipo e a hora que o servidor gravou (`data.tipo`, `data.hora`).
        self::assertMatchesRegularExpression('/sucesso = true;\s+batidaAceita = \{ tipo: payload\.tipo, resposta: resultado\.data \};\s+registrarBatidaConfirmada\(payload\.tipo, resultado\.data && resultado\.data\.data\);/', $tela);
        $registrar = self::corpo($tela, 'function registrarBatidaConfirmada(tipoDoToque, dados) {');
        self::assertStringContainsString("const tipo = typeof gravada.tipo === 'string' && Object.prototype.hasOwnProperty.call(ROTULOS_TIPO, gravada.tipo) ? gravada.tipo : tipoDoToque;", $registrar);
        self::assertStringContainsString("hora: typeof gravada.hora === 'string' && gravada.hora !== '' ? gravada.hora : horaOficialAgora(),", $registrar);
        self::assertStringContainsString('dia: diaDaTelaAgora(),', $registrar);
        self::assertStringContainsString('depoisDaBusca: ultimaBuscaDaTela,', $registrar, 'o número da última busca no aceite');

        // A busca descartada não sai em silêncio: remarca antes de sair (a mais nova pode falhar depois).
        $atualizar = self::corpo($tela, 'async function atualizarTelaDoPonto() {');
        self::assertMatchesRegularExpression('/if \(estaBusca < ultimaBuscaDaTela\) \{\s+(?:\/\/[^\n]*\n\s+)*aplicarGuardadasNaTela\(\);\s+return;\s+\}/', $atualizar);
        // A busca boa seguinte manda: só sai do registro o que foi confirmado antes de ela começar.
        $limpa = strpos($atualizar, 'confirmadasDaPagina = confirmadasDaPagina.filter((confirmada) => confirmada.depoisDaBusca >= estaBusca);');
        self::assertIsInt($limpa);
        self::assertGreaterThan((int) strpos($atualizar, '.replaceWith('), $limpa, 'só depois da troca');
        self::assertLessThan((int) strrpos($atualizar, 'aplicarGuardadasNaTela();'), $limpa, 'e antes da remarcação');

        // Toda remarcação dos tipos aplica as confirmadas do dia, antes de acertar a sugestão do botão.
        $aplicar = self::corpo($tela, 'function aplicarGuardadasNaTela() {');
        self::assertMatchesRegularExpression('/confirmadasDaPagina\.forEach\(\(confirmada\) => \{\s+const botao = botoes\.find\(\(b\) => b\.dataset\.tipo === confirmada\.tipo\);\s+if \(botao && !botao\.disabled && dia !== null && confirmada\.dia === dia\) \{\s+marcarTipoComoBatido\(botao, confirmada\.hora\);/', $aplicar);
        self::assertLessThan(strrpos($aplicar, 'acertarSugestaoDoBotao();'), (int) strpos($aplicar, 'confirmadasDaPagina.forEach'));

        // Declarado junto das guardadas, antes de qualquer remarcação (`let` não é içado).
        self::assertLessThan(strpos($tela, 'function aplicarGuardadasNaTela() {'), strpos($tela, 'let confirmadasDaPagina = [];'));
        self::assertSame(1, substr_count($tela, 'ultimaBuscaDaTela = '), 'o registro não mexe na numeração das buscas');
    }

    #[TestDox('Revisão 1: o lembrete de saída é conferido pelo relógio a cada segundo, fora da caixa, e vale em repouso sem retorno')]
    public function testLembreteConferidoPeloRelogio(): void
    {
        $tela = self::tela();

        $checar = self::corpo($tela, 'function checarLembreteDeSaida(agora) {');
        self::assertMatchesRegularExpression('/^\{\s*if \(!LEMBRETE_LIGADO \|\| !tEntrada \|\| tSaida\) return;\s*const previsao = saidaPrevista\(\);\s*if \(previsao && agora > previsao\) \{\s*dispararLembreteDeSaida\(\);/', $checar);
        self::assertStringNotContainsString('tRetorno', $checar, 'em repouso sem retorno também (a caixa mostra o intervalo)');
        // No relógio (a cada 1 s), ao lado da caixa e não dentro dela; e a caixa não chama mais o lembrete.
        $relogio = self::corpo($tela, 'function updateClock() {');
        self::assertMatchesRegularExpression('/atualizarAvisoDoDia\(agora\);\s*checarLembreteDeSaida\(agora\);/', $relogio);
        self::assertSame(1, substr_count($tela, 'dispararLembreteDeSaida();'), 'um só caminho para o lembrete');
        self::assertStringContainsString('setInterval(updateClock, 1000);', $tela);
    }

    #[TestDox('Revisão 4: com a caixa na batida guardada, "Batidas sem internet enviadas." entra também nela, abaixo (só acrescenta)')]
    public function testCaixaGuardadaRecebeAsEnviadas(): void
    {
        $tela = self::tela();

        $avisar = self::corpo($tela, 'function avisarResultadoDaFila(parte) {');
        self::assertMatchesRegularExpression("/if \\(parte\\.enviadas > 0 && !filaTemPendentes\\) \\{\\s*const flutuou = avisarFlutuante\\(AVISO_FILA_ENVIADA, 'ok'\\);\\s*if \\(!flutuou \\|\\| avisoBatida\\.dataset\\.origem === 'guardada'\\) \\{\\s*linhas\\.push\\(AVISO_FILA_ENVIADA\\);/", $avisar);
        // A linha entra por `escreverAvisoDaFila`, que nunca troca um aviso que não seja de outra rodada: fica abaixo.
        self::assertStringContainsString('escreverAvisoDaFila(linhas, tom);', $avisar);
        $escrever = self::corpo($tela, 'function escreverAvisoDaFila(linhas, tom) {');
        self::assertStringContainsString("if (!avisoNaTela || avisoBatida.dataset.origem === 'rodada') {", $escrever);
        self::assertStringContainsString("mostrarAvisoBatida(aviso, 'info', false, 'guardada');", $tela, 'a origem da caixa da batida guardada');
    }

    #[TestDox('Revisão 5: aviso flutuante pedido com a tela cheia aberta espera ela fechar; um por vez (o mais novo)')]
    public function testAvisoEsperaATelaCheia(): void
    {
        $js = self::arquivo('public/js/ponto-aviso-flutuante.js');

        $mostrar = self::corpo($js, 'function mostrar(texto, tom) {');
        self::assertMatchesRegularExpression('/^\{\s*if \(telaCheiaAberta\(\)\) \{\s*const pedir = emEspera === null;\s*emEspera = \{ texto: texto, tom: tom \};\s*if \(pedir\) \{\s*window\.PontoTelaCheia\.quandoFechar\(/', $mostrar);
        self::assertStringContainsString("tela.fase() !== ''", $js);

        $tc = self::arquivo('public/js/ponto-tela-cheia.js');
        $quando = self::corpo($tc, 'function quandoFechar(fn) {');
        // Re-revisão (achado 2): nos 60 ms do "Tentar novamente" a tela está fechada, mas quem pede espera do mesmo jeito.
        self::assertMatchesRegularExpression("/if \\(fase === '' && !refazendo\\) \\{\\s*fn\\(\\);\\s*return;\\s*\\}\\s*aoFechar\\.push\\(fn\\);/", $quando);
        self::assertMatchesRegularExpression('/^\{\s*fecharTela\(true\);\s*\}$/', self::corpo($tc, 'function fechar() {'), 'o fechar de sempre solta quem espera');
        $fechar = self::corpo($tc, 'function fecharTela(soltar) {');
        self::assertSame(2, preg_match_all('/if \(soltar\) \{\s*soltarQuemEspera\(\);\s*\}/', $fechar), 'nos dois caminhos do fechar');
        self::assertLessThan(strrpos($fechar, 'soltarQuemEspera();'), (int) strrpos($fechar, "fase = '';"), 'depois de a fase zerar');
        self::assertStringContainsString('quandoFechar,', $tc);
        self::assertStringContainsString('configurar,', $tc);
    }

    #[TestDox('Revisão 7: "Tentar novamente" com o tipo já batido não envia nada e avisa "{Tipo} já registrado(a)."')]
    public function testTentarDeNovoComOTipoJaBatido(): void
    {
        $tela = self::tela();

        $tentar = self::corpo($tela, 'function tentarDeNovo(tipo, externo) {');
        self::assertMatchesRegularExpression("/if \\(!botao \\|\\| botao\\.disabled\\) \\{\\s*avisarFlutuante\\(rotuloDoTipo\\(tipo\\) \\+ ' já ' \\+ registradoDoTipo\\(tipo\\) \\+ '\\.', 'ok'\\);\\s*return;\\s*\\}/", $tentar);
        // O toque só sai depois da guarda (com o tipo livre).
        self::assertGreaterThan((int) strpos($tentar, 'return;'), (int) strpos($tentar, 'btnPonto.click();'));
        self::assertSame(1, substr_count($tentar, 'btnPonto.click();'));
        // A tela cheia fecha antes de refazer (o aviso não fica por baixo dela), sem soltar quem espera
        // (testTentarDeNovoNaoSoltaQuemEspera).
        $tc = self::arquivo('public/js/ponto-tela-cheia.js');
        self::assertMatchesRegularExpression('/function tentarDeNovo\(\) \{\s*const refazer = aoTentar;\s*fecharTela\(false\);\s*refazendo = true;\s*setTimeout\(function \(\) \{/', $tc);
        self::assertMatchesRegularExpression('/if \(refazer\) \{\s*refazer\(\);\s*\}/', self::corpo($tc, 'function tentarDeNovo() {'));
        self::assertStringContainsString('}, TENTAR_DEPOIS_MS);', self::corpo($tc, 'function tentarDeNovo() {'));
    }

    #[TestDox('Re-revisão (achado 2): o "Tentar novamente" não solta quem espera antes do toque novo; a espera segue até a tela cheia fechar de verdade')]
    public function testTentarDeNovoNaoSoltaQuemEspera(): void
    {
        $tc = self::arquivo('public/js/ponto-tela-cheia.js');
        $tentar = self::corpo($tc, 'function tentarDeNovo() {');

        // Fecha sem soltar e marca os 60 ms do refazer; o alerta de horário e o aviso flutuante pedidos neles esperam.
        self::assertMatchesRegularExpression('/const refazer = aoTentar;\s*fecharTela\(false\);\s*refazendo = true;/', $tentar);
        self::assertStringNotContainsString('fechar();', $tentar, 'o fechar de sempre soltaria o alerta antes da validação nova');
        self::assertStringContainsString('let refazendo = false;', $tc);
        self::assertSame(3, substr_count($tc, 'refazendo = '), 'a declaração, o começo e o fim do refazer');
        // Refeito o toque: se ele abriu a validação nova, quem espera segue esperando (o fechamento dela solta); se não
        // abriu (o tipo já batido, o botão ocupado), a tela fechou de verdade e quem espera sai agora, mesmo com erro.
        self::assertMatchesRegularExpression(
            "/setTimeout\(function \(\) \{\s*refazendo = false;\s*try \{\s*if \(refazer\) \{\s*refazer\(\);\s*\}\s*\} finally \{\s*if \(fase === ''\) \{\s*soltarQuemEspera\(\);\s*\}\s*\}\s*\}, TENTAR_DEPOIS_MS\);/",
            $tentar
        );
        // `fase()` diz `tentar` nos 60 ms: o aviso flutuante e o alerta de horário (que olham `fase() !== ''`) esperam.
        self::assertStringContainsString("fase: function () { return fase !== '' ? fase : (refazendo ? 'tentar' : ''); },", $tc);
        self::assertStringContainsString("tela.fase() !== ''", self::arquivo('public/js/ponto-aviso-flutuante.js'));
        self::assertStringContainsString("tela.fase() !== ''", self::arquivo('templates/ponto/_modal_alerta_ponto.html.twig'));
        // O botão "Tentar novamente" usa este caminho; o "Fechar" e os botões a mais usam o fechar de sempre.
        self::assertMatchesRegularExpression("/if \(alvo\.hasAttribute\('data-tc-tentar'\)\) \{\s*tentarDeNovo\(\);/", $tc);
    }

    #[TestDox('Re-revisão (achado 4): o plim do lembrete de saída espera a tela cheia fechar, junto com o aviso âmbar; a notificação do navegador sai na hora')]
    public function testLembreteEsperaATelaCheia(): void
    {
        $tela = self::tela();
        $lembrete = self::corpo($tela, 'function dispararLembreteDeSaida() {');

        self::assertMatchesRegularExpression(
            "/depoisDaTelaCheia\(\(\) => \{\s*if \(window\.PontoSons\) \{\s*window\.PontoSons\.liberar\(\);\s*window\.PontoSons\.ok\(\);\s*\}\s*avisarFlutuante\(AVISO_LEMBRETE_DE_SAIDA, 'lembrete'\);\s*\}\);/",
            $lembrete,
            'o plim e o aviso saem juntos, depois da tela cheia'
        );
        self::assertSame(1, substr_count($lembrete, 'window.PontoSons.ok();'), 'nenhum plim fora da espera');
        self::assertLessThan(strpos($lembrete, 'depoisDaTelaCheia('), strpos($lembrete, 'localStorage.setItem(chave, dia);'), 'o dia é marcado na hora: o lembrete esperando não se repete');
        self::assertLessThan(strpos($lembrete, "new Notification('BlueJus Ponto'"), strpos($lembrete, 'depoisDaTelaCheia('), 'a notificação do navegador não espera (ela não aparece por cima da tela cheia)');
        self::assertMatchesRegularExpression(
            "/if \(window\.PontoTelaCheia && typeof window\.PontoTelaCheia\.quandoFechar === 'function'\) \{\s*window\.PontoTelaCheia\.quandoFechar\(fn\);\s*return;\s*\}\s*fn\(\);/",
            self::corpo($tela, 'function depoisDaTelaCheia(fn) {'),
            'sem a tela cheia na página, na hora'
        );
    }

    #[TestDox('Revisão 8: fechada a tela cheia, o foco vai ao título do cartão (tabindex -1), não ao "Bater Ponto"')]
    public function testFocoNoTituloDoCartao(): void
    {
        $tela = self::tela();

        self::assertStringContainsString('<span class="pe-registrar-titulo" id="ponto-registrar-titulo" tabindex="-1">Registrar Ponto</span>', $tela);
        self::assertStringContainsString("window.PontoTelaCheia.configurar({ focoAoFechar: () => document.getElementById('ponto-registrar-titulo') });", $tela);
        self::assertStringNotContainsString("focoAoFechar: () => btnPonto", $tela);
        $tc = self::arquivo('public/js/ponto-tela-cheia.js');
        self::assertMatchesRegularExpression('/function configurar\(opcoes\) \{\s*if \(opcoes && typeof opcoes\.focoAoFechar === \'function\'\) \{\s*focoAoFechar = opcoes\.focoAoFechar;/', $tc);
    }

    #[TestDox('Revisão 9: o plano B de recarregar espera a tela cheia fechar')]
    public function testRecargaEsperaATelaCheia(): void
    {
        $tela = self::tela();

        $depois = self::corpo($tela, 'async function atualizarDepoisDaBatida(recarregarSeFalhar) {');
        self::assertStringContainsString('setTimeout(() => depoisDaTelaCheia(() => window.location.reload()), 1500);', $depois);
        self::assertStringNotContainsString('setTimeout(() => window.location.reload(), 1500);', $tela);
        $espera = self::corpo($tela, 'function depoisDaTelaCheia(fn) {');
        self::assertStringContainsString('window.PontoTelaCheia.quandoFechar(fn);', $espera);
    }

    #[TestDox('Revisão 11: "Registro selado" só marca depois de o servidor confirmar, no tempo dela ou quando a resposta chegar')]
    public function testSeloSoDepoisDaConfirmacao(): void
    {
        $tc = self::arquivo('public/js/ponto-tela-cheia.js');

        // Só o `ok` (a confirmação) agenda o selo; a validação nova o apaga; o erro não o toca.
        $ok = self::corpo($tc, 'function ok(dados) {');
        self::assertStringContainsString('tempoDoSelo = setTimeout(function () { selar(true); }, Math.max(0, inicio + seloEmMs() - performance.now()));', $ok);
        self::assertStringContainsString('selar(false);', self::corpo($tc, 'function validar(dados) {'));
        self::assertStringNotContainsString('selar(', self::corpo($tc, 'function erro(dados) {'));
        self::assertSame(1, substr_count($tc, 'selar(true)'));
        self::assertStringContainsString('clearTimeout(tempoDoSelo);', self::corpo($tc, 'function limparTempos() {'));

        // A etapa vem sem o atraso no `style` e com o tempo do desenho (2.800 x 0,94) num atributo; o CSS a deixa apagada.
        $marcacao = self::arquivo('templates/ponto/_tela_cheia.html.twig');
        self::assertStringContainsString('<span class="pe-tc-etapa pe-tc-etapa--selo pe-tc-mov" data-tc-selo data-tc-selo-em="{{ (valT * 0.94)|round }}">', $marcacao);
        $css = self::arquivo('public/css/ponto-eletronico.css');
        self::assertStringContainsString('.pe-tc-etapa--selo { animation: none; }', $css);
        self::assertStringContainsString('.pe-tc-etapa--selo.pe-tc-etapa--selada { animation: pe-tc-etapa .2s linear forwards; }', $css);
    }

    #[TestDox('Revisão 12: a batida já registrada mostra o texto do sistema na linha de baixo do sucesso')]
    public function testJaRegistradaNaLinhaDoSucesso(): void
    {
        $tela = self::tela();

        self::assertStringContainsString("const AVISO_JA_REGISTRADA = '{{ constant('App\\\\Ponto\\\\Service\\\\EnvioDaBatida::MENSAGEM_JA_REGISTRADA')|e('js') }}';", $tela);
        $registrada = self::corpo($tela, 'function mostrarBatidaRegistrada(tipoDoToque, dados) {');
        self::assertStringContainsString('const jaRegistrada = gravada.jaRegistrada === true;', $registrada);
        self::assertStringContainsString("met: jaRegistrada ? AVISO_JA_REGISTRADA : ''", $registrada);
        self::assertStringContainsString("const texto = jaRegistrada ? AVISO_JA_REGISTRADA : rotuloDoTipo(tipo) + ' ' + registradoDoTipo(tipo) + ' às ' + horaDaBatida + '.';", $registrada);

        $tc = self::arquivo('public/js/ponto-tela-cheia.js');
        self::assertStringContainsString("no.textContent = dados.met ? String(dados.met) : String(no.getAttribute('data-padrao') || '');", $tc);
        $marcacao = self::arquivo('templates/ponto/_tela_cheia.html.twig');
        self::assertStringContainsString('<div class="pe-tc-ok-met" data-tc-ok-met data-padrao="{{ metPadrao }}">{{ metPadrao }}</div>', $marcacao);
    }

    #[TestDox('Revisão 13: o comentário do GPS negado fala da falha do toque de agora, não do texto velho na caixa')]
    public function testComentarioDoGpsAtualizado(): void
    {
        $erroGps = self::corpo(self::tela(), 'function erroGps(error) {');

        self::assertStringNotContainsString('A localização é obrigatória para registrar o ponto.', $erroGps);
        self::assertStringContainsString('`mostrarFalhaDoToque`', $erroGps);
    }

    private static function tela(): string
    {
        return self::arquivo('templates/ponto/index.html.twig');
    }

    private static function arquivo(string $caminho): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $caminho);
        self::assertIsString($conteudo, $caminho);

        return $conteudo;
    }

    /** O corpo de uma função (das chaves), pelo balanceamento delas. */
    private static function corpo(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, sprintf('não achei `%s`', $assinatura));
        $abre = strpos($fonte, '{', $inicio + strlen($assinatura) - 1);
        self::assertIsInt($abre);
        $profundidade = 0;
        for ($i = $abre, $total = strlen($fonte); $i < $total; ++$i) {
            if ($fonte[$i] === '{') {
                ++$profundidade;
            } elseif ($fonte[$i] === '}') {
                --$profundidade;
                if ($profundidade === 0) {
                    return substr($fonte, $abre, $i - $abre + 1);
                }
            }
        }
        self::fail(sprintf('`%s` sem o fecho', $assinatura));
    }
}
