<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O JavaScript do cartão "Registrar Ponto" do desenho novo (E1, decisões do dono de 09/11/2026).
 *
 * PHPUnit não executa JavaScript (e o container não tem Node): isto confere o arranjo do fonte, elo por elo, de modo
 * que a cadeia inteira fique provada. Que a tela se comporta assim no navegador é smoke do dono. Cada teste foi
 * conferido reintroduzindo o defeito no template e vendo-o falhar.
 */
final class CartaoDoPontoNoJsTest extends TestCase
{
    private const CAMINHO_TELA = __DIR__ . '/../../../templates/ponto/index.html.twig';

    #[TestDox('Virada do dia pela hora do SERVIDOR: o instante dele mais o tempo passado contra a meia-noite no fuso dele, nunca o dia do aparelho')]
    public function testViradaPelaHoraDoServidor(): void
    {
        $tela = $this->tela();

        // O servidor manda o instante e a próxima meia-noite; a tela guarda só o deslocamento até a chegada.
        self::assertStringContainsString('data-proxima-virada-ms="{{ proximaViradaMs }}"', $tela);
        self::assertStringContainsString('const PROXIMA_VIRADA_MS = horaOficialEl ? Number(horaOficialEl.dataset.proximaViradaMs) : NaN;', $tela);
        self::assertStringContainsString('deslocamentoDoServidor = agoraMs - chegadaMs;', $this->corpoDaFuncao('function sincronizarComOServidor('));
        self::assertStringContainsString(
            'return deslocamentoDoServidor === null ? null : Date.now() + deslocamentoDoServidor;',
            $this->corpoDaFuncao('function agoraNoServidorMs('),
            'do aparelho só entra o tempo que passou'
        );
        self::assertStringContainsString(
            'return agoraMs !== null && Number.isFinite(PROXIMA_VIRADA_MS) && agoraMs >= PROXIMA_VIRADA_MS;',
            $this->corpoDaFuncao('function diaVirouNoServidor(')
        );
        self::assertStringContainsString('if (diaVirou || !diaVirouNoServidor()) {', $this->corpoDaFuncao('function conferirViradaDoDia('));

        // O dia, a hora e o fuso do aparelho não decidem a virada.
        $virada = $this->corpoDaFuncao('function conferirViradaDoDia(') . $this->corpoDaFuncao('function diaVirouNoServidor(');
        foreach (['toDateString', 'new Date()', 'getDate(', 'getTimezoneOffset'] as $doAparelho) {
            self::assertStringNotContainsString($doAparelho, $virada, 'a virada voltou a olhar o aparelho: ' . $doAparelho);
        }
        self::assertStringNotContainsString('toDateString', $tela);
    }

    #[TestDox('Virada do dia como no desenho: os quatro tipos livres e sem horário, o botão volta a responder com "Bater Ponto: Entrada"')]
    public function testViradaComecaODiaNovo(): void
    {
        $virada = $this->corpoDaFuncao('function conferirViradaDoDia(');
        self::assertMatchesRegularExpression(
            '/zerarTiposNaVirada\(\);\s*aplicarPontoDoDia\(\{\}\);[\s\S]*tipoRegistro\.value = primeiroTipoLivre\(\);\s*'
            . 'atualizarRotuloBotao\(\);\s*marcarTipoEscolhido\(\);/',
            $virada,
            'a virada precisa zerar os tipos e o relógio antes de escolher o tipo e redesenhar o rótulo'
        );
        self::assertStringContainsString('mostrarAvisoBatida(AVISO_VIRADA_DO_DIA, \'warning\', true);', $virada, 'o aviso da virada continua');

        // Elo 1: os quatro tipos voltam a aceitar clique, sem horário e sem a duplicata de ontem.
        $zerar = $this->corpoDaFuncao('function zerarTiposNaVirada(');
        self::assertStringContainsString('botao.disabled = false;', $zerar, 'tipo batido ontem continua sem clique hoje');
        self::assertStringContainsString("botao.classList.remove('pe-tipo-btn--feito');", $zerar);
        self::assertStringContainsString("hora.textContent = '--:--:--';", $zerar, 'o horário de ontem ficou no botão');
        self::assertStringContainsString("botao.querySelectorAll('.pe-tipo-dup').forEach((duplicata) => duplicata.remove());", $zerar);
        self::assertStringContainsString('updateButtonState();', $zerar, 'o botão não é reavaliado depois de liberar');
        self::assertStringNotContainsString('disabled = true', $zerar, 'a virada só libera');
        self::assertStringNotContainsString('btnPonto', $zerar);
        self::assertStringNotContainsString('pe-tipos--ontem', $this->tela(), 'o desenho não apaga os tipos do dia novo');

        // Elo 2: com os tipos livres, o dia completo de ontem deixa de valer; e o botão só fica cinza no envio.
        self::assertStringContainsString(
            "return !!tipos && tipos.querySelector('button[role=\"radio\"]:not(:disabled)') === null;",
            $this->corpoDaFuncao('function jornadaConcluida(')
        );
        self::assertStringContainsString('btnPonto.disabled = envioEmAndamento || concluida;', $this->corpoDaFuncao('function updateButtonState('));

        // Elo 3: o tipo escolhido é o primeiro livre (com os quatro livres, a entrada).
        self::assertStringContainsString(
            "document.querySelector('#ponto-tipos > button[role=\"radio\"]:not(:disabled)')",
            $this->corpoDaFuncao('function primeiroTipoLivre(')
        );
    }

    #[TestDox('Os avisos apontam para os horários nos botões dos tipos, acima; "lista abaixo" não existe mais')]
    public function testAvisosApontamParaOsBotoes(): void
    {
        $tela = $this->tela();

        self::assertStringContainsString(
            "const AVISO_SEM_CONFIRMACAO = 'Não recebemos a confirmação do servidor e a batida PODE ter sido registrada. "
            . "Os horários nos botões acima são de antes da tentativa: atualize a página e confira ANTES de apertar de novo.';",
            $tela
        );
        self::assertStringContainsString(
            "const AVISO_VIRADA_DO_DIA = 'O dia virou com esta página aberta: os botões acima já são do dia novo, mas a "
            . "folha e o banco de horas ainda são de ontem. Atualize a página antes de conferir.';",
            $tela
        );
        self::assertStringNotContainsStringIgnoringCase('lista abaixo', $tela);
    }

    #[TestDox('Saída prevista antes do repouso batido: entrada mais a duração da escala, com o almoço da escala; sem meta, nenhuma')]
    public function testSaidaPrevistaPelaEscala(): void
    {
        $prevista = $this->corpoDaFuncao('function saidaPrevista(');

        self::assertMatchesRegularExpression(
            '/if \(!tRepouso\) \{\s*return duracaoMinutos === null \? null : new Date\(tEntrada\.getTime\(\) \+ duracaoMinutos \* 60000\);/',
            $prevista,
            'antes do repouso, a previsão voltou a sair de valor que não é a escala'
        );
        // Sem meta (feriado, dia fora da escala): sem previsão nem lembrete "Passou da saída prevista", também
        // antes do repouso batido. A guarda da meta vem ANTES do ramo da escala.
        $guarda = strpos($prevista, 'if (!tEntrada || metaDiaMinutos <= 0) return null;');
        self::assertIsInt($guarda, 'dia sem meta voltou a ter saída prevista');
        self::assertLessThan((int) strpos($prevista, 'if (!tRepouso)'), $guarda, 'o ramo antes do repouso escapa da guarda da meta');
        self::assertSame(1, substr_count($prevista, 'metaDiaMinutos <= 0'));

        // O lembrete de saída só sai de uma previsão: sem previsão, a caixa some.
        self::assertMatchesRegularExpression(
            "/const previsao = tSaida \? null : saidaPrevista\(\);\s*if \(!previsao\) \{\s*mostrarAvisoDoDia\('', '', ''\);/",
            $this->corpoDaFuncao('function atualizarAvisoDoDia(')
        );
    }

    #[TestDox('Teclado: os tipos andam pelas setas como um grupo de rádio, só entre os livres, com tabindex móvel')]
    public function testSetasAndamEntreOsTiposLivres(): void
    {
        $teclado = $this->corpoDaFuncao("grupoDoTipo.addEventListener('keydown'");

        self::assertStringContainsString("const PASSO_DA_SETA = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };", $this->tela());
        self::assertStringContainsString('#ponto-tipos > button[role="radio"]:not(:disabled)', $teclado, 'a seta não pode cair num tipo batido');
        self::assertStringContainsString('(posicao + passo + livres.length) % livres.length', $teclado, 'a seta dá a volta');
        self::assertStringContainsString('evento.preventDefault();', $teclado, 'a seta não pode rolar a página');
        self::assertMatchesRegularExpression('/escolherTipo\(proximo\);\s*proximo\.focus\(\);/', $teclado, 'a seta escolhe e leva o foco');

        $marcar = $this->corpoDaFuncao('function marcarTipoEscolhido(');
        self::assertStringContainsString("botao.setAttribute('tabindex', botao === daVez ? '0' : '-1')", $marcar);
        self::assertStringContainsString('livres.find((botao) => botao.dataset.tipo === tipoRegistro.value) || livres[0]', $marcar);
        self::assertMatchesRegularExpression(
            "/tipoRegistro\\.addEventListener\\('change', function\\(\\) \\{\\s*updateButtonState\\(\\);\\s*atualizarRotuloBotao\\(\\);\\s*marcarTipoEscolhido\\(\\);/",
            $this->tela()
        );
    }

    #[TestDox('Aviso do intervalo (P12): o mínimo só com o que o servidor manda, que é 0 com a validação do repouso desligada; aí sai só o tempo do intervalo')]
    public function testAvisoDoIntervaloRespeitaAChave(): void
    {
        $tela = $this->tela();

        self::assertStringContainsString(
            "const minimoRepousoMinutos = avisoDiaEl ? (Number(avisoDiaEl.dataset.intervaloMinimoMinutos) || 0) : 0;",
            $tela
        );
        self::assertStringContainsString(
            'data-intervalo-minimo-minutos="{{ validacaoRepousoHabilitada ? minimoMinutosRepouso : 0 }}"',
            $tela,
            'o mínimo do aviso deixou de passar pela chave do escritório'
        );

        // O ramo do repouso vale com a chave ligada ou desligada; com ela desligada (mínimo 0), sai antes de qualquer
        // texto do mínimo.
        $aviso = $this->corpoDaFuncao('function atualizarAvisoDoDia(');
        self::assertStringContainsString('if (tRepouso && !tRetorno && !tSaida) {', $aviso, 'o tempo do intervalo voltou a depender da chave');
        $semValidacao = strpos($aviso, 'if (minimoRepousoMinutos <= 0) {');
        self::assertIsInt($semValidacao, 'sem a guarda da chave desligada');
        self::assertLessThan((int) strpos($aviso, 'formatarMinimo(minimoRepousoMinutos)'), $semValidacao, 'com a chave desligada o mínimo voltou a aparecer');
        self::assertMatchesRegularExpression(
            "/if \\(minimoRepousoMinutos <= 0\\) \\{\\s*mostrarAvisoDoDia\\('info', 'bi-hourglass-split', 'Intervalo em andamento há ' \\+ emIntervaloHa \\+ '\\.' \\+ saida\\);\\s*return;/",
            $aviso,
            'com a chave desligada, só o tempo do intervalo (e a saída prevista)'
        );
    }

    #[TestDox('Intervalo ao vivo: o tempo desde a batida de repouso em HH:MM:SS nos três textos (com validação antes e depois do mínimo, e sem validação), sem busca nova')]
    public function testIntervaloAoVivoNosTresTextos(): void
    {
        $aviso = $this->corpoDaFuncao('function atualizarAvisoDoDia(');

        // O início do intervalo é a hora da batida de repouso que o cartão já tem (`data-ponto-do-dia`); o aparelho
        // atrasado não mostra tempo negativo.
        self::assertStringContainsString('const decorrido = Math.max(0, Math.floor((agora - tRepouso) / 1000));', $aviso);
        self::assertStringContainsString('const emIntervaloHa = formatarTempo(decorrido);', $aviso, 'o tempo do intervalo em HH:MM:SS');
        self::assertStringContainsString(
            "return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');",
            $this->corpoDaFuncao('function formatarTempo('),
            'HH:MM:SS'
        );
        self::assertStringContainsString('const restante = minimoRepousoMinutos * 60 - decorrido;', $aviso, 'o mínimo conta do mesmo início');

        // Os três textos (o do desenho fica, com o tempo acrescentado). O "1h" é o mínimo da Jornada do escritório.
        self::assertStringContainsString(
            "'Intervalo em andamento há ' + emIntervaloHa + '. O mínimo de ' + minimo + ' se completa em ' + formatarHorasMinutos(faltaEmMinutos * 60) + '.' + saida",
            $aviso,
            'com validação, antes do mínimo'
        );
        self::assertStringContainsString(
            "'Intervalo mínimo de ' + minimo + ' cumprido. Em intervalo há ' + emIntervaloHa + '.' + saida",
            $aviso,
            'com validação, depois do mínimo: o tempo continua andando'
        );
        self::assertStringContainsString("'Intervalo em andamento há ' + emIntervaloHa + '.' + saida", $aviso, 'sem validação');
        self::assertStringContainsString('const minimo = formatarMinimo(minimoRepousoMinutos);', $aviso, 'o mínimo não é fixo');
        self::assertStringNotContainsString("'1h'", $aviso, 'o mínimo voltou a ser fixo');

        // Nada de busca nova: o aviso sai do estado do cartão.
        foreach (['fetch(', 'buscarTelaDoPonto', 'XMLHttpRequest'] as $busca) {
            self::assertStringNotContainsString($busca, $aviso, 'o aviso do intervalo não busca nada: ' . $busca);
        }
        self::assertStringContainsString('tRepouso = parseHMS(pontoHoje.repouso);', $this->corpoDaFuncao('function definirPontoDoDia('));
    }

    #[TestDox('Intervalo ao vivo: um tique só, o do relógio a cada 1 s; a troca depois da batida não cria outro')]
    public function testUmTiqueSoSemEmpilhar(): void
    {
        $tela = $this->tela();

        // Os quatro relógios da tela, cada um uma vez: a hora oficial, o tempo do dia (que redesenha o aviso), a virada e
        // o reenvio da fila sem internet (E14).
        self::assertSame(4, substr_count($tela, 'setInterval('), 'um setInterval novo apareceu na tela do ponto');
        self::assertSame(1, substr_count($tela, 'setInterval(() => { if (filaTemPendentes) { enviarFila(); } }, FILA_INTERVALO_MS);'), 'o reenvio da fila tem um tique só');
        self::assertSame(1, substr_count($tela, 'setInterval(updateClock, 1000);'), 'o relógio do dia tem um tique só');
        self::assertStringContainsString('atualizarAvisoDoDia(agora);', $this->corpoDaFuncao('function updateClock('), 'o aviso anda com o relógio');

        // Depois da batida: troca o estado e redesenha uma vez, sem novo tique. O aviso e o script ficam fora da troca.
        $aplicar = $this->corpoDaFuncao('aplicarPontoDoDia = function (pontoHoje)');
        self::assertMatchesRegularExpression('/definirPontoDoDia\(pontoHoje\);\s*updateClock\(\);/', $aplicar);
        foreach ([$aplicar, $this->corpoDaFuncao('async function atualizarTelaDoPonto('), $this->corpoDaFuncao('function atualizarAvisoDoDia(')] as $trecho) {
            self::assertStringNotContainsString('setInterval(', $trecho, 'tique empilhado a cada troca da tela');
            self::assertStringNotContainsString('setTimeout(', $trecho, 'tique empilhado a cada troca da tela');
        }
        // A lista cresce com as etapas (a E17 pôs o painel do banco); o que importa é o aviso e o script ficarem fora.
        self::assertSame(1, preg_match('/const REGIOES_TROCADAS = \[([^\]]*)\];/', $tela, $regioes), 'a lista das regiões trocadas sumiu');
        self::assertStringContainsString("'ponto-regiao-pendencias'", $regioes[1]);
        self::assertStringNotContainsString('ponto-aviso-dia', $regioes[1], 'o aviso do dia e o relógio não entram na troca: o script não roda de novo');
    }

    #[TestDox('Saída prevista também durante o intervalo, nos três textos, só quando há previsão')]
    public function testSaidaPrevistaNoIntervalo(): void
    {
        $aviso = $this->corpoDaFuncao('function atualizarAvisoDoDia(');
        $repouso = substr($aviso, 0, (int) strpos($aviso, 'const previsao = tSaida ? null : saidaPrevista();'));
        self::assertStringContainsString('if (tRepouso && !tRetorno && !tSaida) {', $repouso, 'o ramo do repouso vem antes da previsão de fora dele');

        self::assertStringContainsString('const previstaNoRepouso = saidaPrevista(agora);', $repouso, 'a conta é a de `saidaPrevista`, com o agora do tique');
        self::assertStringContainsString(
            "const saida = previstaNoRepouso ? ' Saída prevista às ' + formatarHoraDoDia(previstaNoRepouso) + '.' : '';",
            $repouso,
            'sem previsão (sem meta, sem escala), nada é acrescentado'
        );
        self::assertSame(3, substr_count($repouso, "+ '.' + saida);"), 'os três textos do intervalo levam a saída prevista');
        self::assertStringContainsString(
            "return String(data.getHours()).padStart(2, '0') + ':' + String(data.getMinutes()).padStart(2, '0');",
            $this->corpoDaFuncao('function formatarHoraDoDia('),
            'HH:MM'
        );
    }

    #[TestDox('Saída prevista no repouso coerente com a de antes: entrada + meta + o maior entre o intervalo já feito e o piso (mínimo ou almoço da escala), a cada tique; sem escala, nenhuma')]
    public function testSaidaPrevistaNoRepousoContaOIntervaloFeito(): void
    {
        $prevista = $this->corpoDaFuncao('function saidaPrevista(agora)');

        // Com o retorno batido vale o intervalo batido, como antes.
        self::assertMatchesRegularExpression(
            '/if \(tRetorno && tRetorno > tRepouso\) \{\s*return new Date\(tEntrada\.getTime\(\) \+ metaDiaMinutos \* 60000 \+ \(tRetorno - tRepouso\)\);\s*\}/',
            $prevista
        );
        // Sem escala, sem previsão no repouso, como antes dele: o nulo não é mais "tem almoço".
        $semEscala = strpos($prevista, 'if (duracaoMinutos === null) return null;');
        self::assertIsInt($semEscala, 'sem escala, a saída prevista voltou a aparecer no repouso');
        self::assertLessThan((int) strpos($prevista, 'const almocoDaEscalaMs'), $semEscala);
        self::assertStringNotContainsString('duracaoMinutos === null ||', $prevista, 'o nulo voltou a valer como escala com almoço');

        // O piso: o maior entre o mínimo que o servidor aplica (0 com a validação desligada) e o almoço da escala, com
        // ou sem almoço. Sem almoço e com o mínimo ligado, a saída não pode ficar antes do retorno mais cedo possível.
        self::assertStringContainsString('const almocoDaEscalaMs = Math.max(0, duracaoMinutos - metaDiaMinutos) * 60000;', $prevista);
        self::assertStringContainsString(
            'const pisoMs = Math.max(minimoRepousoMinutos * 60000, almocoDaEscalaMs);',
            $prevista,
            'o piso deixou de ser o maior entre o mínimo aplicado e o almoço da escala: a saída pula ao bater o repouso'
        );
        self::assertStringNotContainsString('intervaloPrevistoMinutos', $this->tela(), 'o piso voltou a usar o mínimo cru, sem a chave da validação');
        // Em repouso, o intervalo já feito até agora; a saída é entrada + meta + o maior dos dois.
        self::assertStringContainsString('const feitoMs = agora && !tRetorno && !tSaida ? agora - tRepouso : 0;', $prevista);
        self::assertStringContainsString(
            'return new Date(tEntrada.getTime() + metaDiaMinutos * 60000 + Math.max(feitoMs, pisoMs));',
            $prevista,
            'no repouso, a saída prevista voltou a ignorar o intervalo que já passou do piso'
        );

        // Recalculada no tique: `updateClock` (a cada 1 s) chama `atualizarAvisoDoDia(agora)`, que passa o mesmo agora.
        self::assertStringContainsString('atualizarAvisoDoDia(agora);', $this->corpoDaFuncao('function updateClock('));
        self::assertStringContainsString('saidaPrevista(agora)', $this->corpoDaFuncao('function atualizarAvisoDoDia('));
    }

    #[TestDox('"se completa em" arredonda para cima, no minuto: 17min50s dá 00:18; os últimos 59 s dão 00:01, nunca 00:00')]
    public function testFaltaDoMinimoArredondaParaCima(): void
    {
        $aviso = $this->corpoDaFuncao('function atualizarAvisoDoDia(');

        self::assertMatchesRegularExpression(
            '/if \(restante > 0\) \{[^}]*const faltaEmMinutos = Math\.ceil\(restante \/ 60\);/',
            $aviso,
            'o que falta do mínimo voltou a arredondar para baixo'
        );
        self::assertStringContainsString("' se completa em ' + formatarHorasMinutos(faltaEmMinutos * 60) + '.'", $aviso);
        self::assertStringNotContainsString('formatarHorasMinutos(restante)', $aviso, 'arredondado para baixo, os últimos 59 s mostravam 00:00');
    }

    private function tela(): string
    {
        $conteudo = file_get_contents(self::CAMINHO_TELA);
        self::assertNotFalse($conteudo, 'não foi possível ler a tela do ponto');

        return $conteudo;
    }

    /** Recorta o corpo de uma função pelo balanceamento de chaves. */
    private function corpoDaFuncao(string $assinatura): string
    {
        $fonte = $this->tela();
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, sprintf('não achei `%s` na tela do ponto', $assinatura));
        $abre = strpos($fonte, '{', $inicio + strlen($assinatura));
        self::assertIsInt($abre, 'não achei a abertura do corpo');

        $profundidade = 0;
        for ($i = $abre, $total = strlen($fonte); $i < $total; $i++) {
            if ($fonte[$i] === '{') {
                $profundidade++;
            }
            if ($fonte[$i] === '}') {
                $profundidade--;
            }
            if ($profundidade === 0) {
                return substr($fonte, $inicio, $i - $inicio + 1);
            }
        }

        self::fail('o corpo da função não fecha: chaves desbalanceadas na tela');
    }
}
