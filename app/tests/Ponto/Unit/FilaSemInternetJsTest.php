<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E14 do desenho novo do Ponto (`docs/specs/ponto-desenho-0811.md`, P20; desenho 16, caixa L127 e lógica L438 e L546):
 * a batida feita sem internet fica guardada, cifrada, no aparelho (`public/js/ponto-fila.js`) e é enviada quando a
 * internet volta, com a hora do aparelho no toque e o mesmo identificador do envio (E13).
 *
 * Lido no texto dos scripts e do CSS (o container não tem node; o smoke no navegador é do dono): a chave e o IV, a
 * fila presa à pessoa e ao escritório da página, a tabela do contrato (409 fica, 422 sai), a trava de uma execução por
 * vez, os disparos (carga, `online`, um minuto), o toque sem internet que não chama o envio, os textos do desenho e a
 * caixa com os valores do CSS dele.
 */
final class FilaSemInternetJsTest extends TestCase
{
    private static function fila(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-fila.js');
    }

    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    /** Corpo de uma função, delimitado por contagem de chaves a partir da assinatura. */
    private static function corpo(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, 'faltou: ' . $assinatura);
        $abre = strpos($fonte, '{', $inicio);
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

    #[TestDox('Chave AES-GCM de 256 bits, não extraível, gerada uma vez e guardada no IndexedDB sem sobrescrever a de outra aba')]
    public function testChaveNaoExtraivel(): void
    {
        $fila = self::fila();

        self::assertStringContainsString(
            "crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])",
            self::corpo($fila, 'function obterChave() {'),
            'extractable: false, a chave não sai do navegador'
        );
        self::assertStringNotContainsString('exportKey', $fila);
        self::assertStringNotContainsString('localStorage', $fila, 'a fila mora no IndexedDB, que guarda a CryptoKey sem expô-la');

        $gravar = self::corpo($fila, 'async function gravarChaveSeNaoHouver(nova) {');
        self::assertMatchesRegularExpression(
            "/const leitura = loja\\.get\\(ID_DA_CHAVE\\);\\s+leitura\\.onsuccess = \\(\\) => \\{\\s+if \\(leitura\\.result\\) \\{\\s+final = leitura\\.result;\\s+return;\\s+\\}\\s+loja\\.add\\(nova, ID_DA_CHAVE\\);/",
            $gravar,
            'grava só se não houver, na mesma transação da leitura: duas abas não trocam a chave uma da outra'
        );
        self::assertStringContainsString("transaction(LOJA_CHAVE, 'readwrite', ESCRITA_DURAVEL)", $gravar);

        // O limite da proteção fica escrito no próprio módulo.
        self::assertStringContainsString('Isto protege de leitura casual, não do dono do aparelho.', $fila);
    }

    #[TestDox('Cada entrada é cifrada com um IV aleatório de 12 bytes próprio, guardado junto dela')]
    public function testIvPorEntrada(): void
    {
        $fila = self::fila();
        self::assertStringContainsString('const TAMANHO_DO_IV = 12;', $fila);

        $cifrar = self::corpo($fila, 'async function cifrar(entrada) {');
        self::assertStringContainsString('const iv = crypto.getRandomValues(new Uint8Array(TAMANHO_DO_IV));', $cifrar, 'IV novo a cada chamada');
        self::assertStringContainsString("{ name: 'AES-GCM', iv, additionalData: bytesDoTexto(entrada.idEnvio) }", $cifrar);
        self::assertStringContainsString('return { idEnvio: entrada.idEnvio, iv, dados };', $cifrar, 'em claro, só o identificador aleatório');

        $decifrar = self::corpo($fila, 'async function decifrarCom(chave, registro) {');
        self::assertStringContainsString("{ name: 'AES-GCM', iv: registro.iv, additionalData: bytesDoTexto(registro.idEnvio) }", $decifrar);

        $guardar = self::corpo($fila, 'async function guardar(entrada) {');
        self::assertStringContainsString('const registro = await cifrar(limpa);', $guardar);
        self::assertStringContainsString("naLoja(LOJA_ENTRADAS, 'readwrite', (loja) => loja.put(registro))", $guardar, 'só o registro cifrado vai para o armazenamento');
    }

    #[TestDox('Sem IndexedDB, sem crypto.subtle (fora de contexto seguro) ou sem o identificador do envio, a fila não existe')]
    public function testDisponivel(): void
    {
        $disponivel = self::corpo(self::fila(), 'function disponivel() {');

        foreach (["typeof indexedDB !== 'undefined'", '!!crypto.subtle', "typeof window.PontoBatida.novoIdDeEnvio === 'function'"] as $condicao) {
            self::assertStringContainsString($condicao, $disponivel);
        }
        self::assertMatchesRegularExpression('/\} catch \(erro\) \{[^}]*return false;/', $disponivel, 'nunca lança');
    }

    #[TestDox('A fila só envia e só conta como pendentes as entradas da pessoa e do escritório da página; as de outra pessoa nunca')]
    public function testSoDaPessoaEDoEscritorio(): void
    {
        $fila = self::fila();

        self::assertStringContainsString(
            'return entrada.usuarioId === usuarioId && entrada.escritorioId === escritorioId;',
            self::corpo($fila, 'function daPessoaEDoEscritorio(entrada, usuarioId, escritorioId) {')
        );
        $envio = self::corpo($fila, 'async function enviarAgora(url, usuarioId, escritorioId) {');
        self::assertMatchesRegularExpression(
            '/for \(const entrada of guardadas\) \{\s+if \(!daPessoaEDoEscritorio\(entrada, usuarioId, escritorioId\) \|\| retidasNestaPagina\.has\(entrada\.idEnvio\)\) \{\s+continue;/',
            $envio,
            'o filtro vem antes do envio'
        );
        $contagem = self::corpo($fila, 'function contagemDe(entradas, usuarioId, escritorioId) {');
        self::assertMatchesRegularExpression('/if \(entrada\.usuarioId !== usuarioId\) \{\s+continue;\s+\}/', $contagem, 'outra pessoa não conta nem em outro escritório');
    }

    #[TestDox('Tabela do contrato: sucesso sai; rede, sessão (401) e servidor fora ficam; 409 fica retida; recusa com mensagem (422, 403) sai')]
    public function testTabelaDoContrato(): void
    {
        $fila = self::fila();
        $destino = self::corpo($fila, 'function destinoDaResposta(resultado) {');

        $ordem = [
            "if (resultado.tipo === 'sucesso') {\n            return 'enviada';",
            "if (resultado.falhaDeRede) {\n            return 'tentar_depois';",
            "if (resultado.status === 409) {\n            return 'retida';",
            "if (resultado.status === 401 || resultado.status === 408 || resultado.status === 429) {\n            return 'tentar_depois';",
            "if (typeof resultado.status === 'number' && resultado.status >= 500) {\n            return 'tentar_depois';",
            "if (resultado.tipo === 'recusa' && resultado.mensagem) {\n            return 'recusada';",
        ];
        $ultima = -1;
        foreach ($ordem as $ramo) {
            $pos = strpos($destino, $ramo);
            self::assertIsInt($pos, 'faltou: ' . $ramo);
            self::assertGreaterThan($ultima, $pos, 'fora de ordem: ' . $ramo);
            $ultima = $pos;
        }
        self::assertMatchesRegularExpression("/return 'recusada';\\s+\\}\\s+return 'tentar_depois';\\s+\\}$/", $destino, 'o resto (403 sem mensagem, corpo que não é JSON) fica');

        $envio = self::corpo($fila, 'async function enviarAgora(url, usuarioId, escritorioId) {');
        self::assertMatchesRegularExpression("/if \\(destino === 'enviada'\\) \\{\\s+await remover\\(entrada\\.idEnvio\\);/", $envio);
        self::assertMatchesRegularExpression("/\\} else if \\(destino === 'recusada'\\) \\{\\s+(\\/\\/[^\\n]*\\n\\s+)*if \\(!\\(await recusar\\(entrada, resposta\\.mensagem\\)\\)\\) \\{/", $envio, '422 tira da fila (pela transação da recusa)');
        self::assertMatchesRegularExpression("/\\} else if \\(destino === 'retida'\\) \\{\\s+retidasNestaPagina\\.add\\(entrada\\.idEnvio\\);\\s+\\} else \\{\\s+break;/", $envio, '409 mantém (sem remover); tentar depois para a rodada');
        self::assertSame(1, substr_count($envio, 'await remover('), 'a enviada sai por remover; a recusada, pela transação da recusa');
        self::assertStringContainsString('mensagem: String(mensagem)', self::corpo($fila, 'async function recusar(entrada, mensagem) {'), 'a mensagem do servidor vai para a tela');
    }

    #[TestDox('O envio da fila é o de sempre, com o performance.now() do próprio envio e semInternet; uma execução por vez')]
    public function testEnvioETrava(): void
    {
        $fila = self::fila();

        self::assertStringContainsString(
            'await window.PontoBatida.enviarBatida(url, payloadDe(entrada), performance.now());',
            self::corpo($fila, 'async function enviarAgora(url, usuarioId, escritorioId) {'),
            'a guarda de 60 s do atraso é da batida normal'
        );
        $payload = self::corpo($fila, 'function payloadDe(entrada) {');
        foreach (['tipo: entrada.tipo,', 'idEnvio: entrada.idEnvio,', 'horaAparelho: entrada.horaAparelho,', 'semInternet: true,', 'usuarioId: entrada.usuarioId,', 'escritorioId: entrada.escritorioId,'] as $campo) {
            self::assertStringContainsString($campo, $payload);
        }
        self::assertStringNotContainsString('fetch(', $fila, 'o envio tem prazo: é o do módulo da batida');

        $enviar = self::corpo($fila, 'async function enviarPendentes(url, usuarioId, escritorioId) {');
        self::assertMatchesRegularExpression(
            '/^async function enviarPendentes\(url, usuarioId, escritorioId\) \{\s+if \(envioEmAndamento\) \{\s+return null;\s+\}\s+envioEmAndamento = true;/',
            $enviar,
            'a trava é posta antes de qualquer espera: dois disparos juntos não mandam a mesma entrada duas vezes'
        );
        self::assertMatchesRegularExpression('/\} finally \{\s+envioEmAndamento = false;\s+\}/', $enviar);
    }

    #[TestDox('O módulo da fila não toca a tela e não tem travessão')]
    public function testNaoTocaATela(): void
    {
        $fila = self::fila();

        foreach (['document.', 'innerHTML', 'alert(', 'textContent'] as $proibido) {
            self::assertStringNotContainsString($proibido, $fila);
        }
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $fila);
        self::assertMatchesRegularExpression('/window\.PontoFila = Object\.freeze\(\{\s+disponivel,\s+guardar,\s+estado,\s+remover,\s+limpar,\s+enviarPendentes,\s+apagarRecusas,\s+\}\);/', $fila);
    }

    #[TestDox('A tela carrega a fila depois do módulo da batida e antes do próprio script')]
    public function testTelaCarregaAFila(): void
    {
        $tela = self::tela();
        $batida = strpos($tela, "<script src=\"{{ asset('js/ponto-batida.js') }}\"></script>");
        $fila = strpos($tela, "<script src=\"{{ asset('js/ponto-fila.js') }}\"></script>");
        $script = strpos($tela, '<script>');

        self::assertIsInt($batida);
        self::assertIsInt($fila);
        self::assertIsInt($script);
        self::assertLessThan($fila, $batida);
        self::assertLessThan($script, $fila);
        self::assertStringContainsString(
            'return !!window.PontoFila && window.PontoFila.disponivel() && FILA_USUARIO_ID > 0 && FILA_ESCRITORIO_ID > 0;',
            self::corpo($tela, 'function filaDisponivel() {'),
            'sem o módulo da fila, a tela segue como antes'
        );
    }

    #[TestDox('Sem internet, o toque não chama o envio: guarda na fila; falha de rede no envio guarda com o mesmo identificador e a mesma hora')]
    public function testToqueSemInternet(): void
    {
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");

        $offline = strpos($clique, "if (navigator.onLine === false && await guardarNaFila(payload)) {\n                return;\n            }");
        $envio = strpos($clique, 'PontoBatida.enviarBatida(');
        $falha = strpos($clique, "if (resultado.falhaDeRede && await guardarNaFila(payload)) {\n                return;\n            }");
        $classificacao = strpos($clique, "if (resultado.tipo === 'atrasado') {");
        self::assertIsInt($offline, 'sem internet, a batida vai para a fila');
        self::assertIsInt($envio);
        self::assertIsInt($falha, 'falha de rede no envio, a batida vai para a fila');
        self::assertIsInt($classificacao);
        self::assertLessThan($envio, $offline, 'offline decide ANTES do envio: o fetch não é chamado');
        self::assertLessThan($falha, $envio);
        self::assertLessThan($classificacao, $falha, 'guardada, o aviso é o da fila; sem fila, a classificação de sempre (sem rede)');
        self::assertSame(2, substr_count($clique, 'guardarNaFila(payload)'), 'os dois pontos e nenhum outro');

        // O payload guardado é o do toque: o mesmo idEnvio e a mesma horaAparelho do envio.
        $guardar = self::corpo(self::tela(), 'async function guardarNaFila(payload) {');
        self::assertStringContainsString('const entrada = Object.assign({}, payload, { usuarioId: FILA_USUARIO_ID, escritorioId: FILA_ESCRITORIO_ID });', $guardar);
        self::assertMatchesRegularExpression('/if \(!payload\.idEnvio \|\| !filaDisponivel\(\)\) \{\s+return false;/', $guardar, 'sem identificador não há reenvio seguro');
        self::assertStringContainsString("mostrarAvisoBatida(aviso, 'info', false, 'guardada');", $guardar, 'sem o botão de atualizar, que levaria a tela sem conexão');
        self::assertMatchesRegularExpression('/if \(!\(await window\.PontoFila\.guardar\(entrada\)\)\) \{\s+return false;\s+\}[\s\S]*await lerEstadoDaFila\(entrada\);\s+return true;/', $guardar, 'gravou: o estado sai de uma leitura nova');
    }

    #[TestDox('A batida guardada aparece como já batida (P7 da E1): verde, sem clique e com a hora do toque')]
    public function testGuardadaApareceComoBatida(): void
    {
        $tela = self::tela();
        $aplicar = self::corpo($tela, 'function aplicarGuardadasNaTela() {');

        self::assertStringContainsString('guardadasDaPagina.forEach((entrada) => {', $aplicar, 'a lista da página, não um parâmetro de quem chama');
        self::assertStringContainsString("FORMATO_DIA_DA_FILA.format(quando) !== dia", $aplicar, 'só as do dia da tela');
        self::assertStringContainsString('const botao = botoes.find((b) => b.dataset.tipo === entrada.tipo);', $aplicar, 'sem montar seletor com dado guardado');
        self::assertStringContainsString('marcarTipoComoBatido(botao, FORMATO_HORA_OFICIAL.format(quando));', $aplicar);
        self::assertMatchesRegularExpression('/\}\);\s+acertarSugestaoDoBotao\(\);\s+\}$/', $aplicar, 'a sugestão é acertada depois de marcar');

        $marcar = self::corpo($tela, 'function marcarTipoComoBatido(botao, horaTexto) {');
        self::assertMatchesRegularExpression("/botao\\.disabled = true;\\s+botao\\.classList\\.add\\('pe-tipo-btn--feito'\\);/", $marcar);
        self::assertStringContainsString('hora.textContent = horaTexto;', $marcar);

        $dia = self::corpo($tela, 'function diaDaTelaAgora() {');
        self::assertMatchesRegularExpression('/if \(!diaVirou\) \{\s+return DIA_DA_TELA;/', $dia, 'antes da virada, o dia da carga');
        self::assertStringContainsString('FORMATO_DIA_DA_FILA.format(new Date(agoraMs))', $dia, 'depois da virada, o dia de agora no servidor');

        foreach (['aplicarGuardadasNaTela', 'marcarTipoComoBatido', 'acertarSugestaoDoBotao'] as $funcao) {
            self::assertStringNotContainsString('btnPonto.disabled', self::corpo($tela, 'function ' . $funcao . '('), 'o botão continua sendo só do envio e do dia completo');
        }
    }

    #[TestDox('A fila é enviada na carga, no evento online e a cada minuto enquanto houver batida guardada')]
    public function testDisparosDoEnvio(): void
    {
        $tela = self::tela();

        self::assertStringContainsString("window.addEventListener('online', enviarFila);", $tela);
        self::assertStringContainsString('const FILA_INTERVALO_MS = 60000;', $tela);
        self::assertStringContainsString('setInterval(() => { if (filaTemPendentes) { enviarFila(); } }, FILA_INTERVALO_MS);', $tela);
        self::assertMatchesRegularExpression('/\n    iniciarFila\(\);\n<\/script>/', $tela, 'na carga, por último, no escopo do script');
        self::assertStringContainsString(
            'const resultado = await window.PontoFila.enviarPendentes(URL_DA_BATIDA, FILA_USUARIO_ID, FILA_ESCRITORIO_ID);',
            self::corpo($tela, 'function rodadaDaFila() {')
        );
        self::assertMatchesRegularExpression(
            '/if \(!filaDisponivel\(\) \|\| navigator\.onLine === false \|\| envioEmAndamento\) \{\s+return;\s+\}\s+await rodadaDaFila\(\);/',
            self::corpo($tela, 'async function enviarFila() {'),
            'o minuto e o online não começam uma rodada no meio de um toque'
        );
        self::assertStringContainsString("const URL_DA_BATIDA = '{{ path('ponto_batida') }}';", $tela);
        // Três recargas na tela, nenhuma da fila: o plano B da batida, o botão "Atualizar" e o plano B da busca depois de
        // excluir uma justificativa (E3), que mora no ouvinte de `ponto:justificativa-excluida` e não recarrega no meio
        // de um toque (`envioEmAndamento`).
        self::assertSame(3, substr_count($tela, 'window.location.reload()'), 'a fila não recarrega a página');
        $exclusao = self::corpo($tela, "document.addEventListener('ponto:justificativa-excluida', () => {");
        self::assertSame(1, substr_count($exclusao, 'window.location.reload()'), 'a terceira é a da exclusão');
        self::assertStringNotContainsString('window.location.reload()', self::corpo($tela, 'async function enviarFila() {'));
        self::assertStringNotContainsString('window.location.reload()', self::corpo($tela, 'async function atualizarDepoisDaFila() {'));
    }

    #[TestDox('Textos do desenho e da decisão do Coordenador: caixa, aviso de guardada, enviadas e recusa definitiva')]
    public function testTextos(): void
    {
        $tela = self::tela();

        self::assertStringContainsString("pendentesEl.textContent = 'Sem internet: ' + contagem.pendentes + ' batida(s) aguardando envio.';", $tela);
        self::assertStringContainsString("outroEl.textContent = contagem.emOutroEscritorio + ' batida(s) guardada(s) sem internet em outro escritório. Entre nele para enviar.';", $tela);
        self::assertStringContainsString("caixaDaFila.classList.toggle('d-none', contagem.pendentes === 0 && contagem.emOutroEscritorio === 0);", $tela, 'some com a fila vazia');
        self::assertStringContainsString("const AVISO_GUARDADA_SEM_INTERNET = 'Sem internet. A batida ficou guardada neste aparelho e será enviada quando a internet voltar.';", $tela);
        self::assertStringContainsString("const AVISO_FILA_ENVIADA = 'Batidas sem internet enviadas.';", $tela);

        $avisar = self::corpo($tela, 'function avisarResultadoDaFila(parte) {');
        self::assertMatchesRegularExpression('/if \(parte\.enviadas > 0 && !filaTemPendentes\) \{\s+linhas\.push\(AVISO_FILA_ENVIADA\);/', $avisar, 'enviadas só quando esvaziou (pelo estado lido de novo)');
        self::assertStringContainsString('parte.recusadas.forEach((recusa) => linhas.push(linhaDaRecusaDaFila(recusa)));', $avisar);
        self::assertMatchesRegularExpression('/if \(parte\.enviadas > 0 \|\| parte\.recusadas\.length > 0\) \{\s+atualizarDepoisDaFila\(\);/', self::corpo($tela, 'function concluirResultadoDaFila(parte) {'), 'a tela se atualiza sem recarregar');
        $escrever = self::corpo($tela, 'function escreverAvisoDaFila(linhas, tom) {');
        self::assertStringContainsString('linha.textContent = texto;', $escrever, 'mensagem do servidor só por textContent');
        self::assertStringNotContainsString('innerHTML', $escrever);
    }

    #[TestDox('Revisão 1: a remarcação das guardadas mora nas funções que trocam os tipos, e a sugestão nunca cai num tipo guardado')]
    public function testRevisaoRemarcacaoNaTrocaDosTipos(): void
    {
        $tela = self::tela();

        // Dentro da função que troca o bloco, depois da sugestão do servidor (que não conhece a fila).
        $atualizar = self::corpo($tela, 'async function atualizarTelaDoPonto() {');
        self::assertMatchesRegularExpression('/tipoRegistro\.value = tipoNovo\.value;\s+(\/\/[^\n]*\n\s+)*aplicarGuardadasNaTela\(\);\s+\}$/', $atualizar, 'o tipo guardado voltaria livre e sugerido');
        $zerar = self::corpo($tela, 'function zerarTiposNaVirada() {');
        self::assertMatchesRegularExpression('/updateButtonState\(\);\s+(\/\/[^\n]*\n\s+)*aplicarGuardadasNaTela\(\);\s+\}$/', $zerar, 'na virada, as do dia novo não ficam livres');

        // E não em cada chamador: um chamador novo esquecido devolveria o defeito.
        foreach (['async function atualizarDepoisDaBatida(recarregarSeFalhar) {', 'async function atualizarDepoisDaFila() {', 'function conferirViradaDoDia() {'] as $chamador) {
            self::assertStringNotContainsString('aplicarGuardadasNaTela', self::corpo($tela, $chamador), $chamador);
        }

        // A sugestão: tipo escolhido sem clique anda para o primeiro livre.
        self::assertMatchesRegularExpression(
            '/if \(escolhido && escolhido\.disabled\) \{\s+tipoRegistro\.value = primeiroTipoLivre\(\);\s+\}/',
            self::corpo($tela, 'function acertarSugestaoDoBotao() {')
        );
        // Toda escrita da sugestão vem de um tipo livre ou é seguida da remarcação: uma nova escrita pede revisão.
        preg_match_all('/tipoRegistro\.value = ([^;]+);/', $tela, $escritas);
        self::assertSame(['botao.dataset.tipo', 'primeiroTipoLivre()', 'tipoNovo.value', 'primeiroTipoLivre()'], $escritas[1]);

    }

    #[TestDox('Revisão 2: com batida guardada pendente, uma rodada da fila vai antes do toque com internet, com prazo')]
    public function testRevisaoFilaAntesDoToque(): void
    {
        $tela = self::tela();
        $clique = self::corpo($tela, "btnPonto.addEventListener('click', async function() {");

        $rodada = strpos($clique, 'await rodarFilaAntesDoToque();');
        $offline = strpos($clique, 'if (navigator.onLine === false && await guardarNaFila(payload)) {');
        $envio = strpos($clique, 'PontoBatida.enviarBatida(');
        self::assertIsInt($rodada, 'o toque espera a fila');
        self::assertIsInt($offline);
        self::assertIsInt($envio);
        self::assertLessThan($offline, $rodada);
        self::assertLessThan($envio, $rodada);
        self::assertSame(1, substr_count($clique, 'rodarFilaAntesDoToque('), 'uma rodada só');

        $antes = self::corpo($tela, 'async function rodarFilaAntesDoToque() {');
        self::assertMatchesRegularExpression('/^async function rodarFilaAntesDoToque\(\) \{\s+if \(!filaTemPendentes \|\| navigator\.onLine === false \|\| !filaDisponivel\(\)\) \{\s+return;/', $antes, 'sem pendente ou sem internet, o toque segue o caminho dele');
        self::assertStringContainsString('rodadaDaFila(),', $antes);
        self::assertStringContainsString('setTimeout(resolve, FILA_PRAZO_ANTES_DO_TOQUE_MS)', $antes, 'com prazo');
        self::assertStringContainsString('await Promise.race([', $antes);
        self::assertStringContainsString('clearTimeout(prazo);', $antes);
        self::assertStringNotContainsString('envioEmAndamento', $antes, 'o próprio toque pôs a trava: ela não pode impedir a rodada dele');
        self::assertStringContainsString('const FILA_PRAZO_ANTES_DO_TOQUE_MS = 10000;', $tela);

        // A rodada em curso é dividida: o toque espera a do minuto em vez de achar a trava e seguir na frente.
        $rodadaDaFila = self::corpo($tela, 'function rodadaDaFila() {');
        self::assertStringContainsString('if (!rodadaDaFilaEmCurso) {', $rodadaDaFila);
        self::assertMatchesRegularExpression('/\} finally \{\s+rodadaDaFilaEmCurso = null;\s+\}/', $rodadaDaFila);
    }

    #[TestDox('Revisão 3: sucesso com avisos e a atualização falhando solta a trava do envio, marca o tipo aceito e nada se reagenda')]
    public function testRevisaoTravaSoltaSemReagendar(): void
    {
        $tela = self::tela();

        self::assertMatchesRegularExpression(
            '/\} catch \(erro\) \{\s+console\.error\([^;]+;\s+if \(!recarregarSeFalhar\) \{\s+marcarBatidaAceitaNaTela\(\);\s+liberarBotaoBatida\(\);\s+\}\s+if \(recarregarSeFalhar\) \{/',
            self::corpo($tela, 'async function atualizarDepoisDaBatida(recarregarSeFalhar) {'),
            'sem isto a trava do envio fica presa para sempre'
        );
        self::assertMatchesRegularExpression('/sucesso = true;\s+batidaAceita = \{ tipo: payload\.tipo, resposta: resultado\.data \};/', $tela);
        $aceita = self::corpo($tela, 'function marcarBatidaAceitaNaTela() {');
        self::assertStringContainsString('marcarTipoComoBatido(botao, hora);', $aceita, 'o tipo aceito não volta livre: sem batida em dobro');
        // A hora gravada mora em `data.hora` do corpo (`PontoController::batida`); os avisos, em `avisos`.
        self::assertStringContainsString('const gravada = aceita.resposta && aceita.resposta.data ? aceita.resposta.data.hora : null;', $aceita);
        self::assertMatchesRegularExpression('/acertarSugestaoDoBotao\(\);\s+\}$/', $aceita);

        // O resultado da fila no meio de um toque espera sem reagendar, e entra quando o botão é solto.
        $mostrar = self::corpo($tela, 'function mostrarResultadoDaFila(resultado) {');
        self::assertStringNotContainsString('setTimeout', $mostrar, 'reagendar a cada 1 s era o defeito');
        self::assertMatchesRegularExpression('/\s+entregarResultadoDaFila\(\);\s+\}$/', $mostrar);
        self::assertMatchesRegularExpression('/^function entregarResultadoDaFila\(\) \{\s+if \(envioEmAndamento\) \{\s+return;\s+\}\s+soltarResultadoDaFilaEmEspera\(\);\s+\}$/', self::corpo($tela, 'function entregarResultadoDaFila() {'));
        self::assertStringNotContainsString('setTimeout', self::corpo($tela, 'function entregarResultadoDaFila() {'));
        self::assertSame(2, substr_count($tela, 'mostrarResultadoDaFila('), 'a declaração e a rodada; ela não chama a si mesma');
        self::assertMatchesRegularExpression('/resultadoDaFilaEmEspera = null;\s+concluirResultadoDaFila\(parte\);/', self::corpo($tela, 'function soltarResultadoDaFilaEmEspera() {'), 'sai da espera antes de mostrar');
        self::assertMatchesRegularExpression('/btnPonto\.innerHTML = rotuloBotaoBatida\(\);\s+updateButtonState\(\);\s+(\/\/[^\n]*\n\s+)*soltarResultadoDaFilaEmEspera\(\);\s+\}$/', self::corpo($tela, 'function liberarBotaoBatida() {'));
    }

    #[TestDox('Revisão 4: as transações de escrita do IndexedDB pedem durabilidade estrita; sem persist()')]
    public function testRevisaoEscritaDuravel(): void
    {
        $fila = self::fila();

        self::assertStringContainsString("const ESCRITA_DURAVEL = { durability: 'strict' };", $fila);
        self::assertStringContainsString(
            "const transacao = modo === 'readwrite' ? banco.transaction(nome, modo, ESCRITA_DURAVEL) : banco.transaction(nome, modo);",
            self::corpo($fila, 'async function naLoja(nome, modo, trabalho) {')
        );
        preg_match_all('/banco\.transaction\(([^)]*)\)/', $fila, $transacoes);
        self::assertCount(5, $transacoes[1], 'naLoja (2), a chave, a leitura das duas lojas e a recusa');
        foreach ($transacoes[1] as $argumentos) {
            if (str_contains($argumentos, 'readwrite') || $argumentos === 'nome, modo, ESCRITA_DURAVEL') {
                self::assertStringEndsWith(', ESCRITA_DURAVEL', $argumentos, 'escrita sem durabilidade: ' . $argumentos);
            }
        }
        self::assertStringNotContainsString('persist(', $fila . self::tela());
    }

    #[TestDox('Revisão 5: a recusa diz o tipo, o dia e a hora, e o resultado da fila nunca apaga um aviso da tela nem esconde o Atualizar')]
    public function testRevisaoRecusaNaoApagaAviso(): void
    {
        $tela = self::tela();

        $recusa = self::corpo($tela, 'function linhaDaRecusaDaFila(recusa) {');
        self::assertStringContainsString(
            "return 'Batida de ' + tipo + ' de ' + FORMATO_DIA_E_MES_DA_FILA.format(quando) + ' às ' + FORMATO_HORA_DA_FILA.format(quando)\n            + ' guardada sem internet não foi registrada: ' + recusa.mensagem;",
            $recusa
        );
        self::assertStringContainsString('Object.prototype.hasOwnProperty.call(ROTULOS_TIPO, recusa.tipo) ? ROTULOS_TIPO[recusa.tipo]', $recusa, 'o rótulo do tipo (Entrada, Saída)');
        self::assertStringContainsString("const FORMATO_DIA_E_MES_DA_FILA = formatoNoFusoDoServidor({ day: '2-digit', month: '2-digit' });", $tela);

        $escrever = self::corpo($tela, 'function escreverAvisoDaFila(linhas, tom) {');
        self::assertStringContainsString("if (!avisoNaTela || avisoBatida.dataset.origem === 'rodada') {", $escrever, 'só troca o aviso de uma rodada anterior');
        self::assertStringContainsString("mostrarAvisoBatida(linhas[0], tom, !btnAtualizarPagina.classList.contains('d-none'), 'rodada');", $escrever, 'o botão fica como estava');
        self::assertStringContainsString('avisoBatida.appendChild(linha);', $escrever, 'o resto entra abaixo');
        self::assertDoesNotMatchRegularExpression('/btnAtualizarPagina\.classList\.(add|remove|toggle)/', $escrever);

        self::assertStringContainsString("avisoBatida.dataset.origem = origem || '';", self::corpo($tela, 'function mostrarAvisoBatida(texto, tom, oferecerAtualizar, origem) {'));
        self::assertStringContainsString("avisoBatida.dataset.origem = '';", self::corpo($tela, 'function limparAvisoBatida() {'));
    }

    #[TestDox('Revisão 6: falha de rede com o aparelho dizendo que tem internet não fala em "Sem internet"')]
    public function testRevisaoSemConexaoComInternet(): void
    {
        $tela = self::tela();

        self::assertStringContainsString("const AVISO_GUARDADA_SEM_CONEXAO = 'Sem conexão com o servidor. A batida ficou guardada neste aparelho e será enviada sozinha quando a conexão voltar.';", $tela);
        self::assertStringContainsString(
            'const aviso = navigator.onLine === false ? AVISO_GUARDADA_SEM_INTERNET : AVISO_GUARDADA_SEM_CONEXAO;',
            self::corpo($tela, 'async function guardarNaFila(payload) {')
        );
        self::assertSame(1, substr_count($tela, 'AVISO_GUARDADA_SEM_INTERNET :'), '"Sem internet" só com navigator.onLine === false');
    }

    #[TestDox('Revisão 7: as retidas por 409 ficam nas entradas do estado (marcadas como batidas) e contam na linha de outro escritório')]
    public function testRevisaoRetidasMarcadasEContadas(): void
    {
        $fila = self::fila();
        $estado = self::corpo($fila, 'async function estado(usuarioId, escritorioId) {');

        self::assertStringContainsString('{ entradas: guardadas.filter((entrada) => daPessoaEDoEscritorio(entrada, usuarioId, escritorioId)) },', $estado, 'a retida não é filtrada das entradas');
        self::assertStringNotContainsString('retidasNestaPagina', $estado);
        self::assertMatchesRegularExpression(
            '/if \(entrada\.escritorioId === escritorioId && !retidasNestaPagina\.has\(entrada\.idEnvio\)\) \{\s+pendentes\+\+;\s+\} else \{\s+emOutroEscritorio\+\+;/',
            self::corpo($fila, 'function contagemDe(entradas, usuarioId, escritorioId) {')
        );
        self::assertStringContainsString('guardadasDaPagina = estado.entradas;', self::corpo(self::tela(), 'function lerEstadoDaFila(gravada) {'));
    }

    #[TestDox('Revisão 8: na carga, a fila apaga de qualquer pessoa o que não abre com a chave e o que passou de 7 dias')]
    public function testRevisaoLimpezaNaCarga(): void
    {
        $fila = self::fila();

        self::assertStringContainsString('const IDADE_MAXIMA_MS = 7 * 24 * 60 * 60 * 1000;', $fila);
        $limpar = self::corpo($fila, 'async function limpar(agoraMs) {');
        $chave = strpos($limpar, 'const chave = await obterChave();');
        $leitura = strpos($limpar, "naLoja(nomeDaLoja, 'readonly', (loja) => loja.getAll())");
        self::assertIsInt($chave, 'sem a chave não apaga nada');
        self::assertIsInt($leitura);
        self::assertLessThan($leitura, $chave);
        self::assertMatchesRegularExpression('/try \{\s+guardado = await abrir\(chave, registro\);\s+\} catch \(erro\) \{\s+guardado = null;/', $limpar, 'a que não abre é apagada');
        self::assertMatchesRegularExpression('/if \(guardado && \(!porIdade \|\| agoraMs - Number\(guardado\.horaAparelho\) <= IDADE_MAXIMA_MS\)\) \{\s+continue;\s+\}\s+if \(await removerDa\(nomeDaLoja, \[registro\.idEnvio\]\)\) \{/', $limpar);
        self::assertStringNotContainsString('daPessoaEDoEscritorio', $limpar, 'de qualquer pessoa: aparelho compartilhado');
        self::assertStringNotContainsString('usuarioId', $limpar);

        // O porquê fica escrito.
        foreach (['LGPD, aparelho compartilhado', 'O servidor já recusa acima', 'de 72 h'] as $motivo) {
            self::assertStringContainsString($motivo, $fila);
        }

        // Na carga, antes de ler o estado e de enviar.
        $iniciar = self::corpo(self::tela(), 'async function iniciarFila() {');
        $limpa = strpos($iniciar, 'await window.PontoFila.limpar(agoraNoServidorMs());');
        $lista = strpos($iniciar, 'await lerEstadoDaFila();');
        $envia = strpos($iniciar, 'enviarFila();');
        self::assertIsInt($limpa);
        self::assertIsInt($lista);
        self::assertIsInt($envia);
        self::assertLessThan($lista, $limpa);
        self::assertLessThan($envia, $lista);
    }

    #[TestDox('3ª revisão, 1: o estado da página sai sempre de uma leitura nova da fila, num só lugar; a rodada só diz o que enviou e o que foi recusado')]
    public function testTerceiraRevisaoEstadoDeLeituraNova(): void
    {
        $fila = self::fila();
        $tela = self::tela();

        // A rodada não devolve retrato da fila: o retrato é do começo dela e perde a batida guardada no meio.
        $envio = self::corpo($fila, 'async function enviarAgora(url, usuarioId, escritorioId) {');
        self::assertStringContainsString('const resultado = { enviadas: 0, recusadas: 0 };', $envio);
        foreach (['restantes', 'pendentes', 'emOutroEscritorio', 'contagemDe('] as $retrato) {
            self::assertStringNotContainsString($retrato, $envio, 'a rodada devolveu estado: ' . $retrato);
        }
        // O estado é de uma leitura só (entradas e contagens da mesma), exposto no lugar de listar e contar.
        $estado = self::corpo($fila, 'async function estado(usuarioId, escritorioId) {');
        self::assertSame(1, substr_count($estado, 'await lerFilaERecusas()'), 'uma leitura só, das duas lojas');
        self::assertStringNotContainsString('todas()', $estado);
        self::assertStringContainsString('contagemDe(guardadas, usuarioId, escritorioId)', $estado);
        self::assertStringNotContainsString('function listar(', $fila);
        self::assertStringNotContainsString('function contar(', $fila);

        // Na tela, um só lugar escreve a lista, a caixa e `filaTemPendentes`.
        $ler = self::corpo($tela, 'function lerEstadoDaFila(gravada) {');
        self::assertMatchesRegularExpression(
            '/const estado = await window\.PontoFila\.estado\(FILA_USUARIO_ID, FILA_ESCRITORIO_ID\);\s+if \(!estado\) \{[\s\S]*?return;\s+\}\s+guardadasDaPagina = estado\.entradas;\s+desenharCaixaDaFila\(estado\);\s+aplicarGuardadasNaTela\(\);/',
            $ler
        );
        self::assertStringContainsString('const leitura = leituraDaFila.then(', $ler, 'as leituras andam uma depois da outra');
        self::assertStringContainsString('leituraDaFila = leitura;', $ler);
        self::assertSame(3, substr_count($tela, 'guardadasDaPagina = '), 'a declaração, a leitura nova e o tipo do toque sem leitura');
        self::assertSame(2, substr_count($ler, 'guardadasDaPagina = '), 'as duas trocas moram na leitura');
        self::assertSame(2, substr_count($tela, 'desenharCaixaDaFila('), 'a declaração e a leitura nova');
        self::assertSame(3, substr_count($tela, 'filaTemPendentes = '), 'a declaração, a caixa e o tipo do toque sem leitura');
        self::assertSame(1, substr_count($ler, 'filaTemPendentes = '));
        self::assertStringNotContainsString('.restantes', $tela);
        self::assertStringNotContainsString('PontoFila.listar', $tela);
        self::assertStringNotContainsString('PontoFila.contar', $tela);

        // Lida depois de cada rodada (antes do aviso, que olha se esvaziou) e de cada gravação.
        self::assertMatchesRegularExpression(
            '/const resultado = await window\.PontoFila\.enviarPendentes\([^;]+;\s+(\/\/[^\n]*\n\s+)*await lerEstadoDaFila\(\);\s+mostrarResultadoDaFila\(resultado\);/',
            self::corpo($tela, 'function rodadaDaFila() {')
        );
        self::assertStringContainsString('await lerEstadoDaFila(entrada);', self::corpo($tela, 'async function guardarNaFila(payload) {'));
        $mostrar = self::corpo($tela, 'function mostrarResultadoDaFila(resultado) {');
        self::assertStringContainsString('resultadoDaFilaEmEspera = juntarPartesDaFila(resultadoDaFilaEmEspera, { enviadas: resultado.enviadas, recusadas: [] });', $mostrar, 'da rodada, só as enviadas');
        foreach (['guardadasDaPagina', 'desenharCaixaDaFila', 'filaTemPendentes', 'aplicarGuardadasNaTela'] as $estadoDaPagina) {
            self::assertStringNotContainsString($estadoDaPagina, $mostrar, 'o resultado da rodada mexeu no estado: ' . $estadoDaPagina);
        }
    }

    #[TestDox('3ª revisão, 2: o aviso do toque guardado tem origem própria; o resultado de uma rodada só troca aviso de rodada')]
    public function testTerceiraRevisaoOrigemDoAvisoDoToque(): void
    {
        $tela = self::tela();

        self::assertSame(1, substr_count($tela, "'info', false, 'guardada');"));
        self::assertSame(1, substr_count($tela, ", 'rodada');"), 'só a rodada marca o aviso como dela');
        self::assertStringContainsString("avisoBatida.dataset.origem === 'rodada'", self::corpo($tela, 'function escreverAvisoDaFila(linhas, tom) {'));
        self::assertStringNotContainsString("'fila')", $tela);
        self::assertStringNotContainsString("=== 'guardada'", $tela, 'nada troca o aviso do toque guardado');
    }

    #[TestDox('3ª revisão, 3: a limpeza mede a idade pelo relógio do servidor; sem ele, só apaga as que não abrem')]
    public function testTerceiraRevisaoLimpezaPeloRelogioDoServidor(): void
    {
        $tela = self::tela();
        $limpar = self::corpo(self::fila(), 'async function limpar(agoraMs) {');

        self::assertStringContainsString('await window.PontoFila.limpar(agoraNoServidorMs());', $tela);
        self::assertStringNotContainsString('limpar(Date.now())', $tela, 'o relógio do aparelho pulando para a frente apagaria batida de menos de 72 h');
        self::assertStringContainsString("const porIdade = typeof agoraMs === 'number' && Number.isFinite(agoraMs);", $limpar);
        self::assertMatchesRegularExpression('/if \(!disponivel\(\)\) \{\s+return 0;\s+\}/', $limpar, 'sem o agora, a que não abre ainda sai');
        self::assertStringContainsString('(!porIdade || agoraMs - Number(guardado.horaAparelho) <= IDADE_MAXIMA_MS)', $limpar);
    }

    #[TestDox('4ª revisão, 1: a entrada recusada sai da fila e a recusa entra na loja dela na MESMA transação do IndexedDB, cifrada e sem posição')]
    public function testQuartaRevisaoRecusaNaMesmaTransacao(): void
    {
        $fila = self::fila();

        // A loja das recusas, criada na versão 2 do banco sem perder a chave nem as entradas.
        self::assertStringContainsString('const VERSAO_DO_BANCO = 2;', $fila);
        self::assertStringContainsString("const LOJA_RECUSAS = 'recusas';", $fila);
        self::assertMatchesRegularExpression(
            "/if \\(!banco\\.objectStoreNames\\.contains\\(LOJA_RECUSAS\\)\\) \\{\\s+banco\\.createObjectStore\\(LOJA_RECUSAS, \\{ keyPath: 'idEnvio' \\}\\);/",
            self::corpo($fila, 'function abrirBanco() {')
        );
        // A troca de versão só acrescenta: apagar uma loja ou o banco levaria a fila e a chave junto.
        foreach (['deleteObjectStore', 'deleteDatabase'] as $apaga) {
            self::assertStringNotContainsString($apaga, $fila, 'a troca de versão não pode apagar a fila nem a chave: ' . $apaga);
        }
        // A aba com a versão antiga fecha o banco dela quando outra pede a versão nova, para não travar a troca.
        self::assertStringContainsString(
            'banco.close();',
            self::corpo($fila, 'banco.onversionchange = () => {'),
            'o onversionchange fecha o banco'
        );

        // Uma transação só, nas duas lojas: a remoção da fila e a gravação da recusa. Ou as duas, ou nenhuma.
        $recusar = self::corpo($fila, 'async function recusar(entrada, mensagem) {');
        self::assertMatchesRegularExpression(
            "/const transacao = banco\\.transaction\\(\\[LOJA_ENTRADAS, LOJA_RECUSAS\\], 'readwrite', ESCRITA_DURAVEL\\);\\s+transacao\\.objectStore\\(LOJA_ENTRADAS\\)\\.delete\\(entrada\\.idEnvio\\);\\s+transacao\\.objectStore\\(LOJA_RECUSAS\\)\\.put\\(registro\\);\\s+transacao\\.oncomplete = \\(\\) => resolve\\(\\);/",
            $recusar
        );
        self::assertSame(1, substr_count($recusar, 'banco.transaction('), 'uma transação só');
        self::assertStringNotContainsString('remover(', $recusar, 'a remoção é a da transação da recusa');
        self::assertStringNotContainsString('naLoja(', $recusar);
        // Cifrada ANTES de a transação abrir: uma espera fora do IndexedDB no meio dela a encerraria.
        $cifra = strpos($recusar, 'const registro = await cifrarRecusa(recusa);');
        self::assertIsInt($cifra);
        self::assertLessThan((int) strpos($recusar, 'banco.transaction('), $cifra);

        // Na rodada, a recusada sai só por aqui; não deu, nada mudou e a rodada para (volta na próxima).
        $envio = self::corpo($fila, 'async function enviarAgora(url, usuarioId, escritorioId) {');
        self::assertMatchesRegularExpression(
            "/\\} else if \\(destino === 'recusada'\\) \\{\\s+(\\/\\/[^\\n]*\\n\\s+)*if \\(!\\(await recusar\\(entrada, resposta\\.mensagem\\)\\)\\) \\{\\s+break;\\s+\\}\\s+resultado\\.recusadas\\+\\+;/",
            $envio
        );
        self::assertStringNotContainsString('recusadas.push(', $envio, 'nada de recusa só na memória da página');

        // Cifrada como a fila, com dado autenticado próprio (registro levado de uma loja para a outra não abre).
        self::assertStringContainsString("const PREFIXO_DA_RECUSA = 'recusa:';", $fila);
        $cifrar = self::corpo($fila, 'async function cifrarRecusa(recusa) {');
        self::assertStringContainsString('const iv = crypto.getRandomValues(new Uint8Array(TAMANHO_DO_IV));', $cifrar);
        self::assertStringContainsString("{ name: 'AES-GCM', iv, additionalData: bytesDoTexto(PREFIXO_DA_RECUSA + recusa.idEnvio) }", $cifrar);
        self::assertStringContainsString(
            "{ name: 'AES-GCM', iv: registro.iv, additionalData: bytesDoTexto(PREFIXO_DA_RECUSA + registro.idEnvio) }",
            self::corpo($fila, 'async function decifrarRecusaCom(chave, registro) {')
        );
        // Só tipo, hora do toque e mensagem, com a pessoa e o escritório; nada de posição.
        $normalizar = self::corpo($fila, 'function normalizarRecusa(recusa) {');
        preg_match_all('/^\s+(\w+): /m', $normalizar, $campos);
        self::assertSame(['idEnvio', 'tipo', 'horaAparelho', 'mensagem', 'usuarioId', 'escritorioId'], $campos[1]);
        foreach (['latitude', 'longitude', 'precisaoGps', 'CAMPOS_DA_POSICAO'] as $posicao) {
            self::assertStringNotContainsString($posicao, $normalizar);
        }

        // O estado devolve as recusas desta pessoa neste escritório, da mesma leitura das entradas.
        self::assertStringContainsString(
            '{ recusas: recusas.filter((recusa) => daPessoaEDoEscritorio(recusa, usuarioId, escritorioId)) }',
            self::corpo($fila, 'async function estado(usuarioId, escritorioId) {')
        );
        self::assertStringContainsString(
            "const transacao = banco.transaction([LOJA_ENTRADAS, LOJA_RECUSAS], 'readonly');",
            self::corpo($fila, 'async function lerFilaERecusas() {')
        );
        // A limpeza de 7 dias e a de "não abre" valem para as duas lojas.
        self::assertStringContainsString(
            '[[LOJA_ENTRADAS, decifrarCom], [LOJA_RECUSAS, decifrarRecusaCom]]',
            self::corpo($fila, 'async function limpar(agoraMs) {')
        );
    }

    #[TestDox('4ª revisão, 1: a tela mostra as recusas da leitura nova e apaga cada uma só depois de mostrar; nada de sessionStorage')]
    public function testQuartaRevisaoTelaMostraDaLeituraEApagaDepois(): void
    {
        $tela = self::tela();

        self::assertStringNotContainsString('sessionStorage', $tela . self::fila(), 'uma fonte só: a loja das recusas');
        self::assertStringNotContainsString('bj-ponto-fila-recusas', $tela);

        // As recusas saem da leitura nova (as ainda não entregues nesta página) e esperam quem pediu a leitura entregar.
        $ler = self::corpo($tela, 'function lerEstadoDaFila(gravada) {');
        self::assertStringContainsString('.filter((recusa) => !recusasNaTela.has(recusa.idEnvio));', $ler, 'duas leituras não repetem a recusa');
        self::assertStringContainsString('resultadoDaFilaEmEspera = juntarPartesDaFila(resultadoDaFilaEmEspera, { enviadas: 0, recusadas: novas });', $ler);
        self::assertStringNotContainsString('apagarRecusas', $ler, 'ler não apaga: só mostrar apaga');

        // Apagadas depois de escritas no aviso, nunca antes, e em nenhum outro lugar.
        $avisar = self::corpo($tela, 'function avisarResultadoDaFila(parte) {');
        $escreve = strpos($avisar, 'escreverAvisoDaFila(linhas, tom);');
        $apaga = strpos($avisar, 'window.PontoFila.apagarRecusas(parte.recusadas.map((recusa) => recusa.idEnvio));');
        self::assertIsInt($escreve);
        self::assertIsInt($apaga);
        self::assertLessThan($apaga, $escreve);
        self::assertSame(1, substr_count($tela, 'PontoFila.apagarRecusas('), 'só depois de mostrar');

        // A rodada não traz recusa nenhuma; a carga entrega o que a leitura achou na loja.
        self::assertStringNotContainsString('resultado.recusadas', $tela);
        self::assertMatchesRegularExpression(
            '/await lerEstadoDaFila\(\);\s+entregarResultadoDaFila\(\);\s+enviarFila\(\);/',
            self::corpo($tela, 'async function iniciarFila() {')
        );
    }

    #[TestDox('4ª revisão, 2: sem leitura logo depois de gravar o toque, o tipo dele é marcado direto e o envio de cada minuto fica ligado')]
    public function testQuartaRevisaoTipoMarcadoSemLeitura(): void
    {
        $tela = self::tela();

        self::assertStringContainsString('await lerEstadoDaFila(entrada);', self::corpo($tela, 'async function guardarNaFila(payload) {'));
        self::assertSame(1, substr_count($tela, 'lerEstadoDaFila(entrada)'), 'só o toque passa a entrada gravada');
        self::assertMatchesRegularExpression(
            '/if \(!estado\) \{\s+if \(gravada && !guardadasDaPagina\.some\(\(entrada\) => entrada\.idEnvio === gravada\.idEnvio\)\) \{\s+guardadasDaPagina = guardadasDaPagina\.concat\(\[gravada\]\);\s+filaTemPendentes = true;\s+aplicarGuardadasNaTela\(\);\s+\}\s+return;\s+\}/',
            self::corpo($tela, 'function lerEstadoDaFila(gravada) {')
        );
    }

    #[TestDox('3ª revisão, 5: a busca da tela é numerada no início e só a resposta da mais nova iniciada entra')]
    public function testTerceiraRevisaoBuscaNumeradaNoInicio(): void
    {
        $tela = self::tela();
        $atualizar = self::corpo($tela, 'async function atualizarTelaDoPonto() {');

        self::assertMatchesRegularExpression('/^async function atualizarTelaDoPonto\(\) \{\s+const estaBusca = \+\+ultimaBuscaDaTela;/', $atualizar, 'numerada antes de qualquer espera');
        $descarta = strpos($atualizar, "if (estaBusca < ultimaBuscaDaTela) {\n            return;\n        }");
        $troca = strpos($atualizar, '.replaceWith(');
        self::assertIsInt($descarta, 'descarta toda resposta mais velha que a última busca iniciada');
        self::assertIsInt($troca);
        self::assertLessThan($troca, $descarta);
        self::assertSame(1, substr_count($tela, '++ultimaBuscaDaTela'), 'a numeração só anda no início da busca');
        self::assertSame(1, substr_count($tela, 'ultimaBuscaDaTela = '), 'só a declaração: nada a faz andar na troca');
        self::assertStringNotContainsString('buscaDaTelaTrocada', $tela);
    }

    #[TestDox('A caixa da fila tem os valores do CSS do desenho (L127), na tela do ponto e com o prefixo pe-')]
    public function testCssDaCaixa(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/ponto-eletronico.css');
        $semComentarios = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        self::assertSame(1, preg_match('/\n\.pe-fila \{([^}]*)\}/', $semComentarios, $regra));
        $declaracoes = [];
        foreach (array_filter(array_map('trim', explode(';', $regra[1]))) as $declaracao) {
            [$propriedade, $valor] = array_map('trim', explode(':', $declaracao, 2));
            $declaracoes[$propriedade] = $valor;
        }
        self::assertSame([
            'display' => 'flex',
            'align-items' => 'center',
            'gap' => '8px',
            'padding' => '9px 11px',
            'border' => '1px solid var(--pe-info-bd)',
            'border-left' => '4px solid var(--pe-azul)',
            'border-radius' => '3px',
            'background' => 'var(--pe-info-bg)',
            'color' => 'var(--pe-info-cor)',
            'font-size' => '12.5px',
        ], $declaracoes);
        self::assertStringContainsString('.pe-fila > .bi { font-size: 15px; }', $semComentarios);

        // Os tokens, no tema claro, são os valores do desenho.
        foreach (['--pe-info-bd:      #9fc3d8;', '--pe-azul:         #0a6fa0;', '--pe-info-bg:      #e5f1f7;', '--pe-info-cor:     #0b4f75;'] as $token) {
            self::assertStringContainsString($token, $css);
        }
    }
}
