<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O atalho de ponto do cabeçalho (spec `presenca-e-ponto-no-cabecalho.md` §3, F1 e F2), lido no texto do script: a
 * ordem do clique, os prazos e a mesma lógica da tela do ponto (public/js/ponto-batida.js). A regra de arquivo inteiro
 * (nenhuma promessa crua com getCurrentPosition) é do BatidaNaoTravaNoGpsTest, que também lê este arquivo.
 */
final class AtalhoDoPontoNoCabecalhoJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/presenca-ponto.js');
    }

    #[TestDox('Ordem do clique: trava, confirmação da saída, momento do toque, posição, cerca, releitura, envio, destrava')]
    public function testOrdemDoClique(): void
    {
        $js = self::js();
        $marcos = [
            'envioEmAndamento = true;   // (1)',
            "if (atual.acao === 'saida' && !confirmarSaida(base)) { return; }   // (2)",
            'var momentoDoToque = performance.now();                        // (3)',
            'await PB.lerPosicaoComPrazo(POSICAO_PRAZO_MS',
            'PB.avaliarPosicao(',
            'var conferido = await lerEstado(RELEITURA_PRAZO_MS);',
            'await PB.enviarBatida(urlBatida, payload, momentoDoToque);',
            "try { if (sucesso) { await atualizar(); } } catch (e) { /* a próxima releitura corrige */ }\n            envioEmAndamento = false;",   // (9), não a declaração; sai mesmo com falha
        ];
        $ultima = -1;
        foreach ($marcos as $marco) {
            $pos = strpos($js, $marco);
            self::assertNotFalse($pos, 'faltou: ' . $marco);
            self::assertGreaterThan($ultima, $pos, 'fora de ordem: ' . $marco);
            $ultima = $pos;
        }
        self::assertStringContainsString('} finally {', $js);
        self::assertStringContainsString('var base = estado;', $js);
        self::assertStringContainsString('var atual = linhaDe(base);', $js);
        self::assertStringContainsString('if (!base.homeOffice) {', $js);
        self::assertStringNotContainsString('estado.estado ||', $js, 'nada compara com o estado mutável');
    }

    #[TestDox('Prazos: a releitura do estado tem AbortController próprio (5 s); a posição tem 10 s; sem posição nada é enviado')]
    public function testPrazos(): void
    {
        $js = self::js();
        self::assertStringContainsString('var RELEITURA_PRAZO_MS = 5000;', $js);
        self::assertStringContainsString('var POSICAO_PRAZO_MS = 10000;', $js);
        self::assertStringContainsString('var prazo = setTimeout(function () { aborto.abort(); }, prazoMs);', $js);
        self::assertStringContainsString('signal: aborto.signal', $js);
        self::assertStringContainsString("if (!position) { avisar(AVISO_SEM_POSICAO); return; }", $js);
        self::assertStringContainsString("if (!conferido) { avisar(AVISO_SEM_CONFERIR); return; }", $js, 'releitura que falha não envia');
        self::assertStringContainsString('if (conferido.estado !== base.estado || conferido.dia !== base.dia) {', $js, 'compara com o retrato do clique (estado derivado e dia), não com o estado que o fundo reescreve');
    }

    #[TestDox('Nada de regra própria: cerca, envio e classificação são os da tela; o intervalo não tem ação; avisos nunca afirmam que não entrou sem saber')]
    public function testSemRegraPropria(): void
    {
        $js = self::js();
        self::assertStringNotContainsString('fetch(urlBatida', $js, 'o POST é o do módulo');
        self::assertStringNotContainsString('function distanciaEmMetros', $js);
        self::assertStringContainsString("if (onde.situacao === 'fora') { avisar(mensagemDaCerca(onde)); return; }", $js, 'só recusa quando certamente fora');
        self::assertStringContainsString("return { texto: 'Em intervalo desde ' + h.repouso, cor: '#5b7383', icone: 'bi-pause-circle', acao: '', dica: '' };", $js);
        self::assertStringContainsString("if (base.interjornadaMinimaMin) { texto += ' A próxima entrada só será aceita depois de ' + descanso(base.interjornadaMinimaMin) + ' de descanso.'; }", $js, 'decisão do dono 19/10: o valor do escritório, só com a validação ligada');
        self::assertStringContainsString("'?competencia=' + dia.slice(0, 7) + '&esquecimento=' + dia + ':saida'", $js);
        foreach (['nao_entrou: AVISO_NAO_ENTROU', 'sem_confirmacao: AVISO_SEM_CONFIRMACAO', 'sem_rede: AVISO_SEM_REDE', 'atrasado: AVISO_ATRASADO'] as $caso) {
            self::assertStringContainsString($caso, $js);
        }
        self::assertStringContainsString('PODE ter sido registrada', $js);
        self::assertStringContainsString("resultado.data.data.hora", $js, 'a hora do aviso é a do servidor');
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $js);
    }

    #[TestDox('R3: o "Regularizar ponto de ontem" é aviso ao lado da linha; a linha nunca o troca pela batida, e o clique nele só leva ao pedido de esquecimento')]
    public function testRegularizarOntemEAviso(): void
    {
        $js = self::js();
        self::assertStringNotContainsString("case 'regularizar_ontem':", $js, 'não é mais estado da linha');
        self::assertStringNotContainsString("acao: 'regularizar'", $js, 'a linha não perde a batida');
        self::assertStringContainsString("if (!estado || estado.aviso !== 'regularizar_ontem' || !estado.ontemSemSaida) { return null; }", $js);
        self::assertStringContainsString("regularizar: function () { if (aviso()) { regularizarOntem(estado.ontemSemSaida); } },", $js);

        $cabecalho = (string) file_get_contents(__DIR__ . '/../../../public/js/presenca-cabecalho.js');
        self::assertStringContainsString('var pendencia = avisoDoPonto(x);', $cabecalho, 'o aviso entra abaixo do atalho, que continua');
        self::assertStringContainsString('ponto().regularizar();', $cabecalho);
    }
}
