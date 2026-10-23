<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E2 da frente de fidelidade (`docs/specs/fidelidade-designer-1310.md`): no pacote do Claude Designer
 * de 13/10 (`02 - EXPEDIENTES 1.2.3.dc.html`) 44 pílulas (999px) viraram cantos de 4px, o padrão PJe.
 * A troca é seletiva: o mesmo arquivo mantém 999px nos interruptores, nos preenchimentos das barras e
 * nos selos de status montados em JS. Cada seletor abaixo foi pareado com a linha do protótipo pela
 * assinatura do estilo (padding, cor, altura) e pelo texto que o elemento mostra.
 *
 * O teste trava as duas metades. Que o canto aparece quadrado na tela é a comparação visual da spec.
 */
final class PastaCantosPjeCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/';

    /** Todos os valores de `border-radius` das regras cuja lista de seletores contém `$seletor`. */
    private function raios(string $arquivo, string $seletor): array
    {
        $css  = (string) file_get_contents(self::CSS . $arquivo);
        $css  = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $alvo = preg_replace('/\s+/', ' ', $seletor);
        $raios = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $regras, PREG_SET_ORDER);
        foreach ($regras as [, $seletores, $corpo]) {
            $lista = array_map(static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s)), explode(',', $seletores));
            if (in_array($alvo, $lista, true) && preg_match('/border-radius:\s*([^;]+);/', $corpo, $r)) {
                $raios[] = trim($r[1]);
            }
        }
        self::assertNotEmpty($raios, sprintf('Nenhuma regra de %s declara border-radius em %s', $seletor, $arquivo));

        return array_values(array_unique($raios));
    }

    /** @return iterable<string, array{string, string}> arquivo, seletor ← linha do dc de 13/10 */
    public static function quatroPixels(): iterable
    {
        yield 'contagem do trilho (L1434/1454/1494)' => ['pasta-show.css', '.ps-trilho .ps-contagem'];
        yield 'contagem do cabeçalho do Push (L2513)' => ['pasta-show.css', '.ps-card-cab--faixa .ps-contagem'];
        yield 'pílula do dia (L1365/1689/1894/2422)' => ['pasta-show.css', '.ps-dia-pilula'];
        yield 'pílula do dia do Push (L2612)' => ['pasta-show.css', '.ps-push-dia-pilula'];
        yield 'NOVO do Push (L2616)' => ['pasta-show.css', '.ps-push-novo'];
        yield 'selo de prazo e de pagamento (L6446, finVals L3551)' => ['pasta-show.css', '.ps-selo'];
        yield 'Principal do cliente (L1482)' => ['pasta-show.css', '.ps-selo-principal'];
        yield 'Já vinculado / Completo (cadVals L3420)' => ['pasta-show.css', '.ps-vinc-selo'];
        yield 'processo principal (L1699)' => ['pasta-show.css', '.ps-processo-principal'];
        yield 'tag do cliente (cliVals L3156)' => ['pasta-show.css', '.ps-cli-tag'];
        yield 'filtros das metas (L6527)' => ['pasta-show.css', '.ps-metas-filtro'];
        yield 'situação da meta aberta (metaVals L3689)' => ['pasta-show.css', '.ps-mrel-situacao'];
        yield 'trilho do progresso das metas (L1628)' => ['pasta-show.css', '.ps-metas-barra'];
        yield 'trilho dos pagamentos (L1980)' => ['pasta-show.css', '.ps-pag-barra'];
        yield '✦ Análise IA do Push (L2536)' => ['pasta-ia.css', '.ps-push .ps-ia-selo'];
        yield 'pontos da análise do Push (pushIAVals L5319)' => ['pasta-ia.css', '.ps-push .ps-ia-chip'];
        yield 'contexto da janela de agentes (L925)' => ['pasta-ia-agentes.css', '.ia-chip'];
        yield '✦ selo do agente (L2785)' => ['pasta-ia-agentes.css', '.ia-selo'];
        yield 'pontos da análise do agente (= pushIAVals)' => ['pasta-ia-agentes.css', '.ia-chip-ponto'];
        yield 'fase dos documentos sugeridos (L2234)' => ['pasta-documentos-sugeridos.css', '.ds-sug-fase'];
        yield 'busca dos documentos (L2133)' => ['pasta-explorador.css', '.pex-busca'];
        yield 'ordenação dos documentos (L3268)' => ['pasta-explorador.css', '.pex-pilulas > button'];
        yield 'Mostrando somente (L2294)' => ['pasta-explorador.css', '.pex-filtro-chip'];
        yield 'selo do checklist (L6821)' => ['pasta-explorador.css', '.pex-ck-selo'];
        yield 'trilho do checklist (L2186)' => ['pasta-explorador.css', '.pex-ck-barra'];
        yield 'motivos de desligar o checklist (L4178)' => ['pasta-checklist.css', '#pexChecklist .pex-ck-motivo'];
    }

    #[DataProvider('quatroPixels')]
    #[TestDox('cantos de 4px como no dc de 13/10: $seletor ($arquivo)')]
    public function testCantoDeQuatroPixels(string $arquivo, string $seletor): void
    {
        self::assertSame(['4px'], $this->raios($arquivo, $seletor));
    }

    /** @return iterable<string, array{string, string}> */
    public static function continuaPilula(): iterable
    {
        yield 'interruptor do Financeiro (sw)' => ['pasta-show.css', '.ps-fin-switch-trilho'];
        yield 'preenchimento dos pagamentos (L3617)' => ['pasta-show.css', '.ps-pag-barra-cheia'];
        yield 'preenchimento das metas (L6890)' => ['pasta-show.css', '.ps-metas-barra-cheia'];
        yield 'status da meta na lista (L6565)' => ['pasta-show.css', '.ps-meta-status'];
        yield 'status das outras metas (L3709)' => ['pasta-show.css', '.ps-mrel-outra-status'];
        yield 'sim/não do processo (flagSt L2971)' => ['pasta-show.css', '.ps-processo-selo'];
        yield 'tipo da comunicação do Push (tipoSt L5629)' => ['pasta-show.css', '.ps-push-titulo'];
        yield 'confiança da sugestão (confSt L4156)' => ['pasta-documentos-sugeridos.css', '.ds-sug-conf'];
        yield 'contagens da sugestão (L4159)' => ['pasta-documentos-sugeridos.css', '.ds-sug-chip'];
        yield 'número de filtros (L2141)' => ['pasta-explorador.css', '.pex-selo-filtro'];
        yield 'preenchimento do checklist (L6823)' => ['pasta-explorador.css', '.pex-ck-barra-fill'];
        yield 'interruptor do checklist (sw L4166)' => ['pasta-checklist.css', '#pexChecklist .pex-ck-interruptor-trilho'];
    }

    #[DataProvider('continuaPilula')]
    #[TestDox('o desenho manteve a pílula: $seletor ($arquivo)')]
    public function testDesenhoManteveAPilula(string $arquivo, string $seletor): void
    {
        self::assertSame(['999px'], $this->raios($arquivo, $seletor));
    }
}
