<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Enum\TipoEventoHistorico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A FAMÍLIA do evento (redesenho 1a) dá o chip, a cor do ponto e o recorte dos filtros da timeline do
 * objeto. É classificação de apresentação, mas mora no enum pelo mesmo motivo do corte da Central: um
 * `match` sem `default` quebra quando alguém cria um tipo novo, em vez de deixá-lo cair num balde e
 * sumir de todos os filtros em silêncio.
 *
 * ⚠️ Este corte é DIFERENTE de `ehTrabalhoDeCobranca()`, e o teste da exaustividade abaixo é o que
 * impede alguém de "simplificar" um no outro: `anotacao`, `pagamento_registrado` e `judicializacao` são
 * todos trabalho de cobrança e são TRÊS famílias distintas na tela.
 */
#[CoversClass(TipoEventoHistorico::class)]
final class TipoEventoHistoricoFamiliaTest extends TestCase
{
    private const FAMILIAS_VALIDAS = ['contatos', 'dinheiro', 'obrigacoes', 'anotacoes', 'cadastro'];

    #[Test]
    #[TestDox('Todo tipo do enum tem família — nenhum fica de fora por omissão')]
    public function todoTipoTemFamiliaValida(): void
    {
        foreach (TipoEventoHistorico::cases() as $tipo) {
            self::assertContains(
                $tipo->familia(),
                self::FAMILIAS_VALIDAS,
                "o tipo \"{$tipo->value}\" caiu numa família que a tela não conhece",
            );
            self::assertNotSame('', $tipo->familiaLabel(), "o tipo \"{$tipo->value}\" ficou sem rótulo de chip");
        }
    }

    #[Test]
    #[TestDox('O que move dinheiro cai em `dinheiro` — é o filtro que a gerência usa para varrer o caixa')]
    public function dinheiroCobreRecebimentoLiquidacaoEAcordo(): void
    {
        $esperado = [
            'acordo_cancelado', 'acordo_criado', 'acordo_cumprido', 'acordo_editado', 'acordo_rompido',
            'liquidacao_registrada', 'pagamento_corrigido', 'pagamento_excluido', 'pagamento_registrado',
        ];

        self::assertSame($esperado, $this->tiposDaFamilia('dinheiro'));
    }

    #[Test]
    #[TestDox('Falar com o devedor cai em `contatos`, inclusive o carimbo de qualificação')]
    public function contatosCobreOQueSaiDeUmaLigacao(): void
    {
        $esperado = ['boleto_enviado', 'contato_realizado', 'negociacao', 'novo_prazo', 'qualificacao_contato'];

        self::assertSame($esperado, $this->tiposDaFamilia('contatos'));
    }

    #[Test]
    #[TestDox('Mexer na dívida cai em `obrigacoes`; a anotação tem família só dela')]
    public function obrigacoesEAnotacoes(): void
    {
        self::assertSame(
            ['obrigacao_criada', 'obrigacao_editada', 'obrigacao_excluida', 'valor_atualizado_reconhecido'],
            $this->tiposDaFamilia('obrigacoes'),
        );
        self::assertSame(['anotacao'], $this->tiposDaFamilia('anotacoes'));
    }

    #[Test]
    #[TestDox('`cadastro` é o resto, e de propósito NÃO tem botão de filtro — só aparece em Tudo')]
    public function cadastroNaoTemFiltroProprio(): void
    {
        self::assertNotEmpty($this->tiposDaFamilia('cadastro'), 'a família de resto tem de ter membros');

        $chaves = array_column(TipoEventoHistorico::filtrosDaTimeline(), 'chave');

        self::assertSame(['tudo', 'contatos', 'dinheiro', 'obrigacoes', 'anotacoes'], $chaves, 'a ordem é a do desenho');
        self::assertNotContains('cadastro', $chaves);
    }

    #[Test]
    #[TestDox('Toda chave de filtro (menos `tudo`) corresponde a uma família real do enum')]
    public function todoFiltroTemLastroNoEnum(): void
    {
        foreach (TipoEventoHistorico::filtrosDaTimeline() as $filtro) {
            if ($filtro['chave'] === 'tudo') {
                continue;
            }

            // Filtro sem nenhum tipo por trás seria um chip que nunca acende — pior que não existir,
            // porque o operador conclui que não há eventos daquele tipo neste caso.
            self::assertNotEmpty(
                $this->tiposDaFamilia($filtro['chave']),
                "o filtro \"{$filtro['label']}\" não tem nenhum tipo de evento por trás",
            );
        }
    }

    /**
     * ORDENADO alfabeticamente de propósito: a ordem natural aqui seria a de DECLARAÇÃO no enum, que é
     * histórica e não significa nada para a tela — travá-la faria este teste cair só por alguém mover
     * um `case` de lugar, sem que classificação nenhuma tivesse mudado.
     *
     * @return list<string>
     */
    private function tiposDaFamilia(string $familia): array
    {
        $tipos = array_map(
            static fn (TipoEventoHistorico $t): string => $t->value,
            array_filter(TipoEventoHistorico::cases(), static fn (TipoEventoHistorico $t): bool => $t->familia() === $familia),
        );
        sort($tipos);

        return array_values($tipos);
    }
}
