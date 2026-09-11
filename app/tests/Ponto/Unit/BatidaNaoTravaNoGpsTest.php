<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A batida do ponto sumia sem erro nenhum: a pessoa apertava "Bater Ponto", via "Registrando…" e
 * nada era gravado. Não era recusa do servidor — o `audit_log` mostrou 2.478 batidas confirmadas,
 * 2.373 ainda presentes e 105 ausentes (87 delas com exclusão de admin registrada), e 168 dos 177
 * pedidos de Esquecimento de Registro sem NENHUMA batida salva em ±30 min do horário alegado. A
 * requisição não chegava a sair do navegador.
 *
 * A causa estava na ordem das operações do `click`: o botão travava em "Registrando…" e SÓ DEPOIS
 * o código esperava uma atualização de GPS com `await new Promise(... getCurrentPosition ...)`.
 * Essa promessa só se resolve pelos callbacks do navegador, e existe um estado em que ele não chama
 * nenhum dos dois — documento oculto (tela apagada, troca de aplicativo, celular no bolso), quando
 * o Android suspende a geolocalização. Nesse estado o `timeout` da própria API também não corre,
 * porque o relógio dele é do pedido de posição. O `await` travava para sempre e o `fetch` nunca
 * acontecia. Metade das batidas vem de celular.
 *
 * Spec: `docs/specs/ponto-batida-nao-se-perde-no-navegador.md` (Frente 1).
 *
 * Aqui se confere o ARRANJO do JS no fonte, que é o único lado deste defeito que a suíte alcança:
 * PHPUnit lê HTML, não executa JavaScript nem apaga a tela de um celular.
 *
 * 🔑 Os asserts são sobre o ARQUIVO INTEIRO, não sobre uma fatia dele. Uma versão anterior deste
 * teste recortava do `addEventListener` até o fim do arquivo e proibia `getCurrentPosition` ali
 * dentro — e ficava verde com o defeito de volta, bastando extrair a espera crua para uma função
 * declarada ACIMA do recorte. Regra que vale: nenhuma promessa deste template pode embrulhar
 * `getCurrentPosition`, exceto a do leitor com prazo. Onde ela é declarada não importa.
 */
final class BatidaNaoTravaNoGpsTest extends TestCase
{
    private const CAMINHO_TELA = __DIR__ . '/../../../templates/ponto/index.html.twig';

    /** Prazo máximo aceitável para a atualização de posição, em milissegundos. */
    private const TETO_PRAZO_GPS_MS = 10000;

    /** Prazo máximo aceitável para o envio da batida, em milissegundos. */
    private const TETO_PRAZO_ENVIO_MS = 30000;

    /** Espaçamento máximo aceitável entre checagens de virada do dia, em milissegundos. */
    private const TETO_CHECAGEM_DIA_MS = 300000;

    #[TestDox('a única promessa que embrulha getCurrentPosition é a do leitor com prazo')]
    public function testNenhumaOutraPromessaEsperaOGpsDireto(): void
    {
        $leitor  = $this->corpoDoLeitorDePosicao();
        $fora    = str_replace($leitor, '', $this->fonteSemComentariosNemTextos());
        $achadas = [];

        foreach ($this->corposDeNewPromise($fora) as $corpo) {
            if (str_contains($corpo, 'getCurrentPosition')) {
                $achadas[] = $corpo;
            }
        }

        self::assertSame(
            [],
            $achadas,
            'promessa embrulhando `getCurrentPosition` fora do leitor com prazo: ela pode nunca '
            . 'assentar com o documento oculto, e aí a batida nunca chega a ser enviada'
        );
    }

    #[TestDox('o fluxo da batida atualiza a posição pelo leitor com prazo, e com o prazo do projeto')]
    public function testFluxoDaBatidaUsaOLeitorComPrazo(): void
    {
        $fonte = $this->fonteDaTela();

        self::assertStringContainsString(
            'await lerPosicaoComPrazo(GPS_PRAZO_ATUALIZACAO_MS)',
            $fonte,
            'a batida deveria atualizar a posição pelo leitor com prazo, usando a constante do projeto'
        );

        self::assertMatchesRegularExpression(
            '/const GPS_PRAZO_ATUALIZACAO_MS = (\d+);/',
            $fonte,
            'a constante do prazo de GPS deveria existir'
        );
        preg_match('/const GPS_PRAZO_ATUALIZACAO_MS = (\d+);/', $fonte, $captura);

        self::assertLessThanOrEqual(
            self::TETO_PRAZO_GPS_MS,
            (int) $captura[1],
            'prazo grande demais prende o botão em "Registrando…" e devolve o sintoma original'
        );
    }

    #[TestDox('lerPosicaoComPrazo nunca rejeita e sempre tem um prazo próprio para responder')]
    public function testLeitorDePosicaoSempreResponde(): void
    {
        $leitor = $this->corpoDoLeitorDePosicao();

        self::assertStringContainsString(
            'setTimeout(',
            $leitor,
            'sem prazo próprio o leitor herda o relógio da geolocalização, que não corre com o documento oculto'
        );
        self::assertMatchesRegularExpression(
            '/new Promise\(\s*\(\s*resolve\s*\)\s*=>/',
            $leitor,
            'o executor não pode receber `reject`: uma rejeição aqui volta a derrubar o envio'
        );
        self::assertStringNotContainsString(
            'reject',
            $leitor,
            'o leitor precisa resolver sempre — quem não conseguiu posição devolve null e segue'
        );
    }

    #[TestDox('a batida não é enviada quando demorou tanto que a hora do servidor sairia errada')]
    public function testEnvioAtrasadoNaoViraBatidaComHoraErrada(): void
    {
        $fonte = $this->fonteDaTela();

        self::assertStringContainsString(
            'const momentoDoToque = performance.now();',
            $fonte,
            'sem marcar o instante do toque não dá para saber que o envio atrasou — e tem que ser '
            . 'relógio monotônico, senão acerto de hora do aparelho mascara o congelamento'
        );
        self::assertStringContainsString(
            'performance.now() - momentoDoToque > LIMITE_ATRASO_ENVIO_MS',
            $fonte,
            'o servidor carimba a hora de CHEGADA; envio atrasado gravaria batida com hora errada'
        );
    }

    #[TestDox('o envio tem prazo próprio: conexão pendurada não deixa o botão preso para sempre')]
    public function testEnvioTemPrazoProprio(): void
    {
        $fonte = $this->fonteSemComentariosNemTextos();

        self::assertStringContainsString(
            'new AbortController()',
            $fonte,
            '`fetch` só desiste quando o sistema operacional decide, o que pode não acontecer nunca'
        );
        self::assertStringContainsString(
            'signal: abortoDoEnvio.signal',
            $fonte,
            'o prazo do envio só vale se o sinal de aborto chegar ao `fetch`'
        );
        // Existir não basta: a constante pode virar código morto com o prazo real chumbado no
        // `setTimeout`. O assert amarra a constante AO aborto, e põe teto no valor.
        self::assertStringContainsString(
            'setTimeout(() => abortoDoEnvio.abort(), ENVIO_PRAZO_MS)',
            $fonte,
            'o prazo do envio tem que ser o da constante, não um número solto no setTimeout'
        );
        self::assertMatchesRegularExpression(
            '/const ENVIO_PRAZO_MS = (\d+);/',
            $fonte,
            'a constante do prazo de envio deveria existir'
        );
        preg_match('/const ENVIO_PRAZO_MS = (\d+);/', $fonte, $captura);

        self::assertLessThanOrEqual(
            self::TETO_PRAZO_ENVIO_MS,
            (int) $captura[1],
            'prazo grande demais devolve o botão preso em "Registrando…", que é o defeito original'
        );
    }

    #[TestDox('o botão sempre volta ao normal quando a batida não foi registrada')]
    public function testBotaoVoltaEmQualquerFalha(): void
    {
        $fonte = $this->fonteSemComentariosNemTextos();

        self::assertMatchesRegularExpression(
            '/\}\s*finally\s*\{.*?liberarBotaoBatida\(\)/s',
            $fonte,
            'sem `finally` um caminho de erro novo deixa o botão preso em "Registrando…" de novo'
        );
        self::assertStringContainsString(
            'if (!sucesso)',
            $fonte,
            'no caminho bom o botão fica travado de propósito até a página recarregar'
        );
        // A flag `sucesso` sozinha não fecha: trocar o tipo no select durante o 1,5 s até o
        // recarregamento chama `updateButtonState()`, que reabilitava o botão e deixava sair uma
        // SEGUNDA batida. Quem manda no botão durante o envio é o envio.
        self::assertStringContainsString(
            'if (envioEmAndamento)',
            $fonte,
            'durante o envio o botão não pode ser reabilitado por nenhum outro caminho'
        );
    }

    #[TestDox('página aberta da noite para o dia seguinte avisa que o que está na tela é de ontem')]
    public function testAvisaQuandoODiaVirouComAPaginaAberta(): void
    {
        $fonte = $this->fonteSemComentariosNemTextos();

        self::assertStringContainsString(
            'function conferirViradaDoDia()',
            $fonte,
            'o bloco de batidas de hoje é renderizado no servidor e congela: sem esta checagem ele '
            . 'mostraria a batida de ONTEM como se fosse de hoje'
        );
        self::assertStringContainsString(
            'setInterval(conferirViradaDoDia, INTERVALO_CHECAGEM_DIA_MS)',
            $fonte,
            'a virada precisa ser percebida com a página já aberta, não só na carga'
        );
        preg_match('/const INTERVALO_CHECAGEM_DIA_MS = (\d+);/', $fonte, $captura);

        self::assertNotEmpty($captura, 'o espaçamento da checagem deveria ser uma constante nomeada');
        self::assertLessThanOrEqual(
            self::TETO_CHECAGEM_DIA_MS,
            (int) $captura[1],
            'intervalo grande demais mata a checagem sem barulho: a pessoa passa a manhã inteira '
            . 'olhando para a lista de ontem'
        );

        // 🔴 A comparação é do aparelho contra ele mesmo. Comparar com a data do SERVIDOR dava ao
        // relógio do celular poder de veto: fuso errado às 21h travava o botão, e recarregar não
        // resolvia porque a causa era o aparelho. Ficava impossível bater a saída.
        self::assertStringContainsString(
            'new Date().toDateString() === DIA_DO_APARELHO_NA_CARGA',
            $fonte,
            'detectar a MUDANÇA de dia no próprio aparelho é imune a fuso errado; comparar com a '
            . 'data do servidor não é'
        );
    }

    #[TestDox('a virada do dia avisa, mas nunca impede a pessoa de bater o ponto')]
    public function testViradaDoDiaNaoBloqueiaABatida(): void
    {
        $fonte = $this->fonteSemComentariosNemTextos();
        $corpo = $this->corpoDaFuncao($fonte, 'function updateButtonState(');

        self::assertStringNotContainsString(
            'diaVirou',
            $corpo,
            'quem carimba a hora é o servidor, então bater com a página velha grava certo: impedir '
            . 'a batida por causa da tela seria trocar um engano por uma falta'
        );
    }

    #[TestDox('falha de batida aparece em aviso que fica na tela, nunca em alert descartável')]
    public function testFalhaNaoUsaAlertDoNavegador(): void
    {
        $fonte = $this->fonteSemComentariosNemTextos();

        // Arquivo INTEIRO, não uma fatia: `mostrarAvisoBatida` e as outras funções de aviso são
        // declaradas ACIMA do handler, e um `alert` colocado dentro delas devolve o defeito por
        // completo. Foi esse recorte frouxo que a revisão pegou aqui.
        self::assertStringNotContainsString(
            'alert(',
            $fonte,
            'o `alert` some quando a pessoa toca em OK e navegador nenhum o mostra em aba oculta: '
            . 'a falha tem que ficar escrita na tela'
        );
        self::assertStringContainsString(
            'mostrarAvisoBatida(',
            $fonte,
            'o aviso que fica na tela deveria substituir o alert'
        );
    }

    #[TestDox('o relógio do dia monta a data local a partir do servidor, não da data em UTC')]
    public function testRelogioNaoUsaDataUtc(): void
    {
        $fonte = $this->fonteSemComentariosNemTextos();

        self::assertStringNotContainsString(
            'toISOString',
            $fonte,
            '`toISOString().slice(0,10)` é a data em UTC: combinada com hora local, zerava o '
            . 'relógio de quem trabalhava entre 21h e a meia-noite'
        );
        self::assertStringContainsString(
            'new Date(anoHoje, mesHoje - 1, diaHoje,',
            $fonte,
            'a data do dia tem que vir do servidor e ser montada em hora local'
        );
    }

    /**
     * Corpo da função `lerPosicaoComPrazo`, delimitado por contagem de chaves — não por recorte
     * até o próximo marco, que arrastava 60 linhas de código alheio para dentro dos asserts.
     */
    private function corpoDoLeitorDePosicao(): string
    {
        return $this->corpoDaFuncao($this->fonteSemComentariosNemTextos(), 'function lerPosicaoComPrazo(');
    }

    /** Corpo de uma função do script, delimitado por contagem de chaves. */
    private function corpoDaFuncao(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, sprintf('não achei `%s` na tela', $assinatura));

        $abre = strpos($fonte, '{', $inicio);
        self::assertIsInt($abre, 'não achei a abertura do corpo da função');

        $profundidade = 0;
        for ($i = $abre; $i < strlen($fonte); $i++) {
            if ($fonte[$i] === '{') {
                $profundidade++;
            } elseif ($fonte[$i] === '}') {
                $profundidade--;
                if ($profundidade === 0) {
                    return substr($fonte, $inicio, $i - $inicio + 1);
                }
            }
        }

        self::fail('o corpo da função não fecha — chaves desbalanceadas na tela');
    }

    /**
     * Corpo de cada `new Promise(...)` do trecho, delimitado por contagem de parênteses.
     *
     * @return list<string>
     */
    private function corposDeNewPromise(string $trecho): array
    {
        $corpos = [];
        $cursor = 0;

        while (($inicio = strpos($trecho, 'new Promise(', $cursor)) !== false) {
            $abre         = $inicio + strlen('new Promise(') - 1;
            $profundidade = 0;
            $fim          = null;

            for ($i = $abre; $i < strlen($trecho); $i++) {
                if ($trecho[$i] === '(') {
                    $profundidade++;
                } elseif ($trecho[$i] === ')') {
                    $profundidade--;
                    if ($profundidade === 0) {
                        $fim = $i;
                        break;
                    }
                }
            }

            // Promessa que não fecha é código quebrado: vale como achado, não como silêncio.
            self::assertNotNull($fim, 'achei um `new Promise(` que não fecha na tela do ponto');

            $corpos[] = substr($trecho, $inicio, $fim - $inicio + 1);
            $cursor   = $fim;
        }

        return $corpos;
    }

    private function fonteDaTela(): string
    {
        $fonte = file_get_contents(self::CAMINHO_TELA);
        self::assertIsString($fonte, 'não consegui ler a tela do ponto');

        return $fonte;
    }

    /**
     * A tela com o miolo de comentários e de literais de texto apagado (trocado por espaço, para
     * os deslocamentos continuarem valendo). É sobre ISTO que os dois parsers abaixo trabalham.
     *
     * 🪤 Sem esta limpeza o teste erra dos dois lados, e as duas formas foram medidas:
     * - falso POSITIVO: um comentário do tipo "não voltar ao `new Promise(getCurrentPosition)`" —
     *   que é exatamente o que a próxima frente vai escrever aqui — seria acusado de ser o defeito;
     * - falso NEGATIVO, pior porque é mudo: um `)` solto dentro de uma string fecha a contagem de
     *   parênteses cedo, trunca o corpo da promessa e esconde o `getCurrentPosition` que vem depois.
     */
    private function fonteSemComentariosNemTextos(): string
    {
        $fonte  = $this->fonteDaTela();
        $limpa  = '';
        $estado = 'codigo';
        $tamanho = strlen($fonte);

        for ($i = 0; $i < $tamanho; $i++) {
            $c    = $fonte[$i];
            $prox = $i + 1 < $tamanho ? $fonte[$i + 1] : '';

            if ($estado === 'codigo') {
                if ($c === '/' && $prox === '/') {
                    $estado = 'linha';
                    $limpa .= '  ';
                    $i++;
                    continue;
                }
                if ($c === '/' && $prox === '*') {
                    $estado = 'bloco';
                    $limpa .= '  ';
                    $i++;
                    continue;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $estado = 'texto:' . $c;
                    $limpa .= ' ';
                    continue;
                }
                $limpa .= $c;
                continue;
            }

            if ($estado === 'linha') {
                if ($c === "\n") {
                    $estado = 'codigo';
                    $limpa .= "\n";
                    continue;
                }
                $limpa .= ' ';
                continue;
            }

            if ($estado === 'bloco') {
                if ($c === '*' && $prox === '/') {
                    $estado = 'codigo';
                    $limpa .= '  ';
                    $i++;
                    continue;
                }
                $limpa .= $c === "\n" ? "\n" : ' ';
                continue;
            }

            // texto:<aspa>
            $aspa = substr($estado, -1);
            if ($c === '\\') {
                $limpa .= '  ';
                $i++;
                continue;
            }
            if ($c === $aspa) {
                $estado = 'codigo';
            }
            $limpa .= $c === "\n" ? "\n" : ' ';
        }

        self::assertSame(
            strlen($fonte),
            strlen($limpa),
            'a limpeza deveria preservar o tamanho do arquivo, senão os deslocamentos não valem'
        );

        return $limpa;
    }
}
