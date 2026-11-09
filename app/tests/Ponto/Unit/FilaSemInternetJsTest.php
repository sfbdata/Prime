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
        self::assertStringContainsString("transaction(LOJA_CHAVE, 'readwrite')", $gravar);

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

        $decifrar = self::corpo($fila, 'async function decifrar(registro) {');
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
        self::assertMatchesRegularExpression("/\\} else if \\(destino === 'recusada'\\) \\{\\s+await remover\\(entrada\\.idEnvio\\);/", $envio, '422 tira da fila');
        self::assertMatchesRegularExpression("/\\} else if \\(destino === 'retida'\\) \\{\\s+retidasNestaPagina\\.add\\(entrada\\.idEnvio\\);\\s+\\} else \\{\\s+break;/", $envio, '409 mantém (sem remover); tentar depois para a rodada');
        self::assertSame(2, substr_count($envio, 'await remover('), 'só a enviada e a recusada saem da fila');
        self::assertStringContainsString('mensagem: String(resposta.mensagem)', $envio, 'a mensagem do servidor vai para a tela');
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
        self::assertMatchesRegularExpression('/window\.PontoFila = Object\.freeze\(\{\s+disponivel,\s+guardar,\s+listar,\s+contar,\s+remover,\s+enviarPendentes,\s+\}\);/', $fila);
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
        self::assertStringContainsString("mostrarAvisoBatida(AVISO_GUARDADA_SEM_INTERNET, 'info', false);", $guardar, 'sem o botão de atualizar, que levaria a tela sem conexão');
        self::assertStringContainsString('marcarGuardadasNaTela([entrada]);', $guardar);
    }

    #[TestDox('A batida guardada aparece como já batida (P7 da E1): verde, sem clique e com a hora do toque')]
    public function testGuardadaApareceComoBatida(): void
    {
        $marcar = self::corpo(self::tela(), 'function marcarGuardadasNaTela(entradas) {');

        self::assertStringContainsString('if (diaVirou || !Array.isArray(entradas)) {', $marcar);
        self::assertStringContainsString('FORMATO_DIA_DA_FILA.format(quando) !== DIA_DA_TELA', $marcar, 'só as do dia da tela');
        self::assertStringContainsString('const botao = botoes.find((b) => b.dataset.tipo === entrada.tipo);', $marcar, 'sem montar seletor com dado guardado');
        self::assertMatchesRegularExpression("/botao\\.disabled = true;\\s+botao\\.classList\\.add\\('pe-tipo-btn--feito'\\);/", $marcar);
        self::assertStringContainsString('hora.textContent = FORMATO_HORA_OFICIAL.format(quando);', $marcar);
        self::assertStringContainsString('tipoRegistro.value = primeiroTipoLivre();', $marcar, 'a sugestão anda para o próximo tipo livre');
        self::assertStringNotContainsString('btnPonto.disabled', $marcar, 'o botão continua sendo só do envio e do dia completo');
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
            self::corpo($tela, 'async function enviarFila() {')
        );
        self::assertStringContainsString("const URL_DA_BATIDA = '{{ path('ponto_batida') }}';", $tela);
        self::assertSame(2, substr_count($tela, 'window.location.reload()'), 'a fila não recarrega a página');
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
        self::assertStringContainsString("return 'Batida de ' + FORMATO_HORA_DA_FILA.format(new Date(recusa.horaAparelho)) + ' guardada sem internet não foi registrada: ' + recusa.mensagem;", $tela);

        $resultado = self::corpo($tela, 'function mostrarResultadoDaFila(resultado, depoisDeUmToque) {');
        self::assertMatchesRegularExpression('/if \(resultado\.enviadas > 0 && resultado\.pendentes === 0\) \{\s+linhas\.push\(AVISO_FILA_ENVIADA\);/', $resultado, 'enviadas só quando esvaziou');
        self::assertStringContainsString('atualizarDepoisDaFila(resultado.restantes);', $resultado, 'a tela se atualiza sem recarregar');
        self::assertStringContainsString('linha.textContent = texto;', $resultado, 'mensagem do servidor só por textContent');
        self::assertStringNotContainsString('innerHTML', $resultado);
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
