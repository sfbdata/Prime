<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Espelho\VereditoSobCobertura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 🔴 O segundo braço da trava do achado 1 — **o defeito que apareceu TRÊS vezes nesta frente**.
 *
 * O primeiro braço é de construção: {@see VereditoSobCobertura::sucesso()} recusa imprimir verde sob
 * cobertura parcial. Mas nada em PHP impede um comando de ignorar o veredito e chamar
 * `$io->success()` na mão — que é exatamente como o defeito voltou da segunda vez para a terceira.
 *
 * Este teste **lê o código-fonte** dos três instrumentos e proíbe a chamada direta. É feio de
 * propósito: o defeito é de disciplina, e disciplina não se testa por comportamento.
 *
 * ⚠️ Se um instrumento novo do espelho nascer, ele entra na lista abaixo. Um comando que imprima
 * dinheiro sem passar pela cobertura é o defeito, não a exceção.
 */
#[CoversClass(VereditoSobCobertura::class)]
final class VereditoNaoEscapaDaCoberturaTest extends TestCase
{
    /**
     * Os instrumentos que reportam número de dinheiro medido contra a contabilidade — **descobertos
     * por varredura**, não escritos à mão.
     *
     * 🔴 A versão anterior era uma lista de três caminhos. É o mesmo defeito que a régua de PII teve
     * de abandonar duas vezes: *o que precisa ser lembrado não é lembrado*. Um instrumento novo do
     * espelho que imprimisse caixa verde direta passava batido.
     *
     * O critério é objetivo: comando do espelho (`app:cobranca:espelho:*`) que **não** é o carregador
     * — carregar não reporta veredito de dinheiro, os outros três sim.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function instrumentosDoEspelho(): iterable
    {
        $arquivos = glob(\dirname(__DIR__, 3) . '/src/Cobranca/Command/*Command.php') ?: [];
        sort($arquivos);

        foreach ($arquivos as $arquivo) {
            $fonte = (string) file_get_contents($arquivo);

            if (!str_contains($fonte, "name: 'app:cobranca:espelho:")
                || str_contains($fonte, "name: 'app:cobranca:espelho:carregar'")
            ) {
                continue;
            }

            yield basename($arquivo) => [$arquivo];
        }
    }

    #[TestDox('A varredura acha os TRÊS instrumentos — lista vazia não prova nada')]
    public function testAVarreduraAchaOsInstrumentos(): void
    {
        self::assertSame(
            3,
            iterator_count(self::instrumentosDoEspelho()),
            'o conjunto de instrumentos do espelho mudou — decida CONSCIENTEMENTE se o novo reporta '
            . 'número de dinheiro e, portanto, se precisa passar pelo veredito',
        );
    }

    /**
     * **Reintrodução provada:** trocando qualquer `$veredito->sucesso($io, ...)` de volta por
     * `$io->success(...)`, este teste fica vermelho no arquivo correspondente.
     */
    #[DataProvider('instrumentosDoEspelho')]
    #[TestDox('Instrumento do espelho não chama $io->success() direto')]
    public function testInstrumentoNaoImprimeVerdeSemPassarPelaCobertura(string $arquivo): void
    {
        // ⚠️ Sem comentários: a versão anterior lia o fonte cru e `cobertura->declarar(` passava com
        // a chamada COMENTADA — o mesmo furo que foi fechado na régua de PII.
        $fonte = $this->codigoSemComentarios($arquivo);
        $caminhoRelativo = basename($arquivo);

        self::assertStringNotContainsString(
            '$io->success(',
            $fonte,
            sprintf(
                "%s imprime caixa verde sem passar pela cobertura.\n"
                . 'Use $veredito->sucesso($io, ...): sob cobertura parcial ele rebaixa para aviso. '
                . 'Este módulo já estampou verde sobre 0,4%% de cobertura uma vez, e sobre 1 de 3 '
                . 'relatórios outra.',
                $caminhoRelativo,
            ),
        );

        // A contrapartida: o instrumento tem de de fato consultar a cobertura. Sem esta asserção,
        // apagar a declaração inteira faria o teste acima passar — proibir a chamada errada não
        // obriga ninguém a fazer a certa.
        self::assertStringContainsString(
            'cobertura->declarar(',
            $fonte,
            sprintf('%s não declara a cobertura antes de reportar número.', $caminhoRelativo),
        );

    }

    /**
     * 🎯 O aviso é BARRA DE PROGRESSO, não relato de falha (decisão do dono, 13/08).
     *
     * Ele precisa dizer, item a item, **o que falta cobrir** — e dizer em voz alta que não é defeito.
     * Um aviso que só informa "parcial", sem dizer parcial em quê, é lido como problema, e alguém vai
     * "consertar" o que está certo.
     */
    #[TestDox('O aviso diz O QUE FALTA cobrir, e que não é falha')]
    public function testAvisoListaAsPendenciasENegaQueSejaFalha(): void
    {
        $veredito = new VereditoSobCobertura(1, 3, [
            'acordos — nenhum lote no espelho; rode app:cobranca:espelho:carregar',
            'receitas — está no espelho, mas este comando ainda não o confere (fatia 0c)',
        ]);

        $saida = preg_replace('/\s+/', ' ', $this->imprimir($veredito, 'Alinhado.')) ?? '';

        self::assertStringContainsString('Falta cobrir:', $saida);
        self::assertStringContainsString('acordos — nenhum lote no espelho', $saida);
        self::assertStringContainsString('receitas — está no espelho', $saida, 'as duas faltas são DIFERENTES');
        self::assertStringContainsString('ISTO NÃO É FALHA', $saida);
        self::assertStringContainsString('quando esta caixa voltar a sair verde', mb_strtolower($saida));
    }

    #[TestDox('Cobertura completa imprime VERDE')]
    public function testCoberturaCompletaImprimeVerde(): void
    {
        $saida = $this->imprimir(new VereditoSobCobertura(3, 3), 'Alinhado.');

        self::assertStringContainsString('[OK]', $saida);
        self::assertStringNotContainsString('não está sendo afirmado', $saida);
    }

    /**
     * O caso que sai em 100% das execuções reais de hoje: os três instrumentos leem só a
     * inadimplência, de três relatórios com dinheiro.
     */
    #[TestDox('Cobertura parcial rebaixa o sucesso para AVISO, com o recorte colado')]
    public function testCoberturaParcialNaoImprimeVerde(): void
    {
        $saida = $this->imprimir(new VereditoSobCobertura(1, 3), 'Alinhado.');

        self::assertStringNotContainsString('[OK]', $saida, 'cobertura parcial não pode sair verde');
        self::assertStringContainsString('[WARNING]', $saida);
        self::assertStringContainsString('Alinhado.', $saida, 'a frase não é apagada…');
        self::assertStringContainsString('1 de 3', $saida, '…mas sai com o recorte colado nela');
    }

    #[TestDox('completa() é >=, não == — cobrir a mais não vira parcial')]
    public function testCoberturaMaiorQueODenominadorContinuaCompleta(): void
    {
        self::assertTrue((new VereditoSobCobertura(4, 3))->completa());
    }

    /** O fonte sem comentários nem docblocks — comentário não conta como código. */
    private function codigoSemComentarios(string $arquivo): string
    {
        $fonte = file_get_contents($arquivo);
        self::assertIsString($fonte, sprintf('não consegui ler %s', $arquivo));

        $limpo = '';

        foreach (token_get_all($fonte) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $limpo .= $token[1];

                continue;
            }

            $limpo .= $token;
        }

        return $limpo;
    }

    private function imprimir(VereditoSobCobertura $veredito, string $frase): string
    {
        $saida = new BufferedOutput();
        $veredito->sucesso(new SymfonyStyle(new ArrayInput([]), $saida), $frase);

        return $saida->fetch();
    }
}
