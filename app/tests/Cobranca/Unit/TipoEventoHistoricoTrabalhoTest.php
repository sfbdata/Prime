<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Enum\TipoEventoHistorico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Separação trabalho de cobrança × lançamento de cadastro/importação (spec §5.1). É o corte que impede
 * a Central de repetir, pela fonte escolhida, a distorção que o §12 usa para rejeitar o `audit_log`:
 * uma importação de 537 linhas afogando 3 contatos reais.
 *
 * O teste que mais importa aqui é o da EXAUSTIVIDADE: um tipo novo no enum não pode ficar sem
 * classificação e entrar na "última ação" por omissão.
 */
#[CoversClass(TipoEventoHistorico::class)]
final class TipoEventoHistoricoTrabalhoTest extends TestCase
{
    /** Lista literal da spec §5.1 — copiada de lá de propósito, para o teste discordar do código. */
    private const TRABALHO_SEGUNDO_A_SPEC = [
        'contato_realizado', 'boleto_enviado', 'novo_prazo', 'negociacao',
        'acordo_criado', 'acordo_editado', 'acordo_rompido', 'acordo_cancelado', 'acordo_cumprido',
        'pagamento_registrado', 'pagamento_corrigido', 'liquidacao_registrada',
        'pessoa_cobrada_alterada', 'judicializacao', 'vinculo_pasta', 'encerramento', 'anotacao',
    ];

    /** Lista literal da spec §5.1. */
    private const CADASTRO_SEGUNDO_A_SPEC = [
        'caso_aberto', 'obrigacao_criada', 'obrigacao_editada', 'obrigacao_excluida',
        'valor_atualizado_reconhecido', 'revisao_vinculo',
    ];

    #[TestDox('A lista de trabalho de cobrança bate exatamente com a spec §5.1')]
    public function testListaDeTrabalhoBateComASpec(): void
    {
        $valores = array_map(
            static fn (TipoEventoHistorico $t): string => $t->value,
            TipoEventoHistorico::trabalhoDeCobranca(),
        );

        sort($valores);
        $esperado = self::TRABALHO_SEGUNDO_A_SPEC;
        sort($esperado);

        self::assertSame($esperado, $valores);
    }

    #[TestDox('A lista de cadastro/importação bate exatamente com a spec §5.1')]
    public function testListaDeCadastroBateComASpec(): void
    {
        $valores = array_map(
            static fn (TipoEventoHistorico $t): string => $t->value,
            TipoEventoHistorico::lancamentoDeCadastro(),
        );

        sort($valores);
        $esperado = self::CADASTRO_SEGUNDO_A_SPEC;
        sort($esperado);

        self::assertSame($esperado, $valores);
    }

    #[TestDox('Todo tipo do enum cai em EXATAMENTE uma das duas listas — nenhum fica órfão')]
    #[DataProvider('todosOsTipos')]
    public function testClassificacaoExaustiva(TipoEventoHistorico $tipo): void
    {
        $ehTrabalho = \in_array($tipo, TipoEventoHistorico::trabalhoDeCobranca(), true);
        $ehCadastro = \in_array($tipo, TipoEventoHistorico::lancamentoDeCadastro(), true);

        self::assertTrue(
            $ehTrabalho !== $ehCadastro,
            sprintf(
                'O tipo "%s" está em %s. Um tipo novo precisa ser classificado na spec §5.1 antes de '
                . 'entrar na Central: sem isso ele some da "última ação" ou infla a lista do detalhe.',
                $tipo->value,
                $ehTrabalho ? 'AMBAS as listas' : 'NENHUMA das listas',
            ),
        );

        self::assertSame($ehTrabalho, $tipo->ehTrabalhoDeCobranca());
    }

    /**
     * @return iterable<string, array{0: TipoEventoHistorico}>
     */
    public static function todosOsTipos(): iterable
    {
        foreach (TipoEventoHistorico::cases() as $tipo) {
            yield $tipo->value => [$tipo];
        }
    }

    #[TestDox('Importar planilha não é cobrar: obrigacao_criada e caso_aberto não são trabalho')]
    public function testEventosDeImportacaoNaoSaoTrabalho(): void
    {
        self::assertFalse(TipoEventoHistorico::ObrigacaoCriada->ehTrabalhoDeCobranca());
        self::assertFalse(TipoEventoHistorico::CasoAberto->ehTrabalhoDeCobranca());
        self::assertTrue(TipoEventoHistorico::ContatoRealizado->ehTrabalhoDeCobranca());
        self::assertTrue(TipoEventoHistorico::Anotacao->ehTrabalhoDeCobranca());
    }
}
