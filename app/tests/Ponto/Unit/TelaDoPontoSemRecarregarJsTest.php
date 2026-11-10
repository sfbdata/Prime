<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A tela do ponto depois da batida, lida no texto do script (o container não tem node). Spec
 * `docs/specs/ponto-atualiza-sem-recarregar.md`: recarregar zerava a liberação de áudio do navegador e o toque da
 * volta do intervalo ficava mudo com a pessoa longe do computador. O contrato do HTML que a busca recebe é do
 * `App\Tests\Ponto\Functional\TelaDoPontoAtualizaSemRecarregarTest`.
 */
final class TelaDoPontoSemRecarregarJsTest extends TestCase
{
    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    private static function modulo(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-batida.js');
    }

    private static function trecho(string $fonte, string $inicio, string $fim): string
    {
        $abre = strpos($fonte, $inicio);
        self::assertIsInt($abre, 'faltou: ' . $inicio);
        $fecha = strpos($fonte, $fim, $abre + strlen($inicio));
        self::assertIsInt($fecha, 'faltou o fim: ' . $fim);

        return substr($fonte, $abre, $fecha - $abre);
    }

    #[TestDox('o caminho bom da batida não recarrega: a recarga é só o plano B da atualização que falhou')]
    public function testSucessoNaoRecarrega(): void
    {
        $tela    = self::tela();
        $sucesso = self::trecho($tela, "} else if (resultado.tipo === 'sucesso') {", '} else if (');
        self::assertStringNotContainsString('reload', $sucesso);
        self::assertStringContainsString('atualizarDepoisDaBatida(avisos.length === 0);', $sucesso);

        $depois = self::trecho($tela, 'async function atualizarDepoisDaBatida(recarregarSeFalhar) {', "btnPonto.addEventListener('click'");
        self::assertMatchesRegularExpression(
            '/\} catch \(erro\) \{.*if \(recarregarSeFalhar\) \{\s+setTimeout\(\(\) => window\.location\.reload\(\), 1500\);\s+\}\s+return;\s+\}\s+liberarBotaoBatida\(\);/s',
            $depois,
            'recarrega só quando a atualização falhou; o botão só volta com a tela já atualizada'
        );
        // Só três recargas na tela: o plano B acima, o mesmo plano B da busca depois de excluir uma justificativa (E3) e o
        // botão "Atualizar" da pessoa.
        self::assertSame(3, substr_count($tela, 'window.location.reload()'));
        $exclusao = self::trecho($tela, "document.addEventListener('ponto:justificativa-excluida', () => {", "\n    });");
        self::assertStringContainsString('setTimeout(() => window.location.reload(), 1500);', $exclusao);
        self::assertStringContainsString("btnAtualizarPagina.addEventListener('click', () => window.location.reload());", $tela);
    }

    #[TestDox('a troca é tudo ou nada: confere o dia e todas as regiões antes de trocar qualquer uma')]
    public function testTrocaTudoOuNada(): void
    {
        $atualizar = self::trecho(self::tela(), 'async function atualizarTelaDoPonto() {', 'async function atualizarDepoisDaBatida');

        // Desenho novo (E1, 09/11/2026): `ponto-tipos` (os quatro tipos com a hora de cada um) substitui `batidas-de-hoje`.
        // E3: a aba das pendências (o número) troca junto com o painel dela.
        self::assertStringContainsString("const REGIOES_TROCADAS = ['ponto-tipos', 'ponto-banco-horas', 'ponto-aba-pendencias', 'ponto-regiao-pendencias'];", self::tela());
        self::assertStringContainsString('relogioNovo.dataset.dia !== relogio.dataset.dia', $atualizar, 'virou o dia: recarrega');
        self::assertStringContainsString("document.querySelector('#ponto-regiao-folha > section.pe-folha')", $atualizar, 'da folha, só o cartão do desenho (E2)');

        $confere = strpos($atualizar, 'trocas.some(([atual, nova]) => !atual || !nova)');
        $troca   = strpos($atualizar, '.replaceWith(');
        self::assertIsInt($confere);
        self::assertIsInt($troca);
        self::assertLessThan($troca, $confere, 'confere tudo ANTES da primeira troca');

        self::assertStringContainsString('aplicarPontoDoDia(pontoDoDia);', $atualizar, 'relógio, previsão e excedente');
        $parse = strpos($atualizar, 'JSON.parse(pontoDoDiaNovo)');
        self::assertIsInt($parse);
        self::assertLessThan($troca, $parse, 'o estado do relógio é lido antes da primeira troca');
        self::assertStringContainsString('tipoRegistro.value = tipoNovo.value;', $atualizar, 'a próxima sugestão');
    }

    #[TestDox('a busca da tela tem prazo próprio e recusa redirecionamento')]
    public function testBuscaTemPrazo(): void
    {
        $busca = self::trecho(self::modulo(), 'async function buscarTelaDoPonto(url) {', 'function pedirPermissaoDeNotificacao');

        self::assertStringContainsString('setTimeout(() => aborto.abort(), TELA_PRAZO_MS)', $busca);
        self::assertStringContainsString('signal: aborto.signal', $busca);
        self::assertStringContainsString('!resposta.ok || resposta.redirected', $busca, 'sessão caída devolve o login, não a tela');
        self::assertStringContainsString("cache: 'no-store'", $busca);
        self::assertStringContainsString('clearTimeout(prazo);', $busca);
        self::assertMatchesRegularExpression('/const TELA_PRAZO_MS = 15000;/', self::modulo());
    }

    #[TestDox('a permissão da notificação é pedida no clique, antes de qualquer await, e só quando ainda não respondida')]
    public function testPermissaoDeNotificacaoNoClique(): void
    {
        $pedido = self::trecho(self::modulo(), 'function pedirPermissaoDeNotificacao() {', 'window.PontoBatida = Object.freeze');
        self::assertStringContainsString("Notification.permission !== 'default'", $pedido);

        $clique = self::trecho(self::tela(), "btnPonto.addEventListener('click', async function() {", 'await ');
        self::assertStringContainsString('window.PontoBatida.pedirPermissaoDeNotificacao();', $clique, 'tela do ponto: no gesto');

        $atalho = (string) file_get_contents(__DIR__ . '/../../../public/js/presenca-ponto.js');
        $bater  = self::trecho($atalho, 'async function bater() {', 'await ');
        self::assertStringContainsString('PB.pedirPermissaoDeNotificacao();', $bater, 'atalho do cabeçalho: no gesto');
    }

    #[TestDox('a notificação do alerta só mostra a linha do horário quando há horário')]
    public function testNotificacaoSemHorarioVazio(): void
    {
        $modal = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/_modal_alerta_ponto.html.twig');
        self::assertStringContainsString("dados.mensagem + (dados.horario ? '\\nHorário: ' + dados.horario : '')", $modal);
        self::assertStringContainsString("tag:     'alerta-ponto-' + dados.tipo + '-' + (dados.horario || ''),", $modal, 'cada tipo com sua notificação');
    }
}
