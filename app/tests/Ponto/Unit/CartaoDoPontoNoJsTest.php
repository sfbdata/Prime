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

    #[TestDox('Virada do dia: os tipos de ontem voltam a aceitar clique e o dia completo de ontem não trava o botão')]
    public function testViradaLiberaTiposEBotao(): void
    {
        $virada = $this->corpoDaFuncao('function conferirViradaDoDia(');
        $liberar = strpos($virada, 'liberarTiposDoDiaAnterior();');
        $limpar = strpos($virada, "tipoRegistro.value = '';");
        self::assertIsInt($liberar, 'a virada deixou de liberar os tipos');
        self::assertIsInt($limpar);
        self::assertLessThan($limpar, $liberar, 'liberar antes de redesenhar o rótulo, senão fica "Jornada de hoje concluída"');

        // Elo 1: os quatro tipos voltam a aceitar clique e a lista fica marcada como de ontem.
        $corpo = $this->corpoDaFuncao('function liberarTiposDoDiaAnterior(');
        self::assertStringContainsString("tipos.classList.add('pe-tipos--ontem');", $corpo);
        self::assertStringContainsString('botao.disabled = false;', $corpo, 'tipo batido ontem continua sem clique hoje');
        self::assertStringContainsString("botao.classList.remove('pe-tipo-btn--feito');", $corpo);
        self::assertStringContainsString('updateButtonState();', $corpo, 'o botão não é reavaliado depois de liberar');
        // Só libera: nada aqui trava o botão nem um tipo.
        self::assertStringNotContainsString('disabled = true', $corpo);
        self::assertStringNotContainsString('btnPonto', $corpo);

        // Elo 2: com a lista de ontem, o dia completo deixa de valer.
        self::assertMatchesRegularExpression(
            "/if \\(!tipos \\|\\| tipos\\.classList\\.contains\\('pe-tipos--ontem'\\)\\) \\{\\s*return false;/",
            $this->corpoDaFuncao('function jornadaConcluida('),
            'depois da virada o "dia completo" de ontem continua travando o botão'
        );

        // Elo 3: sem o dia completo, o botão só fica cinza durante o envio.
        self::assertStringContainsString('btnPonto.disabled = envioEmAndamento || concluida;', $this->corpoDaFuncao('function updateButtonState('));
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
        self::assertStringContainsString("botao.setAttribute('tabindex', posicao === 0 ? '0' : '-1');", $this->corpoDaFuncao('function liberarTiposDoDiaAnterior('));
        self::assertMatchesRegularExpression(
            "/tipoRegistro\\.addEventListener\\('change', function\\(\\) \\{\\s*updateButtonState\\(\\);\\s*atualizarRotuloBotao\\(\\);\\s*marcarTipoEscolhido\\(\\);/",
            $this->tela()
        );
    }

    #[TestDox('Aviso do intervalo (P12): só com o mínimo que o servidor manda, que é 0 com a validação do repouso desligada')]
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
        self::assertStringContainsString(
            'if (tRepouso && !tRetorno && !tSaida && minimoRepousoMinutos > 0) {',
            $this->corpoDaFuncao('function atualizarAvisoDoDia('),
            'com a chave desligada o aviso do intervalo voltou a aparecer'
        );
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
