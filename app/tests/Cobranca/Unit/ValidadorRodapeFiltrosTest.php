<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Importacao\RecorteEsperado;
use App\Cobranca\Service\Importacao\ValidadorRodapeFiltros;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spec: `docs/specs/cobranca-validador-rodape-filtros.md`.
 *
 * ⚠️ Todo texto de rodapé usado aqui foi COPIADO de arquivo real (§2 da spec, 13 arquivos medidos em
 * 06/08/2026) — não é fixture inventada. É essa procedência que dá valor aos casos: as armadilhas
 * (dois espaços, `até:` sem espaço, `Todos` órfão, plural `Baixadas`, campo extra) existem no dado e
 * quebrariam um parser escrito "de cabeça".
 *
 * Prova por reintrodução (spec §5), com DUAS injeções — cada uma tem de avermelhar um caso DIFERENTE:
 *  1. trocar a comparação exata por `str_contains` → `testRecusaSingularQuandoEsperaPlural`;
 *  2. fazer `todosOuOrfao` aceitar qualquer valor → `testRecusaReceitasComJanelaDeRecebimento`.
 */
#[CoversClass(ValidadorRodapeFiltros::class)]
final class ValidadorRodapeFiltrosTest extends TestCase
{
    /** Receitas emitida SEM janela de recebimento — o recorte correto. Note o `Todos;` órfão. */
    private const RECEITAS_COMPLETA = 'Filtros: Situação das contas: Baixadas; Competência: Todas; Período de vencimento: Todos; Todos; Unidade: Todos; Classe de conta: Todas; Sacado: Todos;';

    /** Receitas do lote de 04/08 pela API: janela de RECEBIMENTO — recorte errado (5 anos viram 7 meses). */
    private const RECEITAS_JANELA = 'Filtros: Situação das contas: Baixadas; Competência: Todas; Período de vencimento: Todos; Período de recebimento: 01/01/2026 a 04/08/2026; Unidade: Todos; Classe de conta: Todas; Sacado: Todos; Conta (bancária, caixinha...): Todas as contas;';

    /** O arquivo que a secretária baixou à mão em 03/08 — DOIS campos errados (spec §2.3). */
    private const RECEITAS_MANUAL_03_08 = 'Filtros: Situação das contas: Aberta e baixada; Competência: Todas; Período de vencimento: 01/01/2026 a 01/01/2027; Todos; Unidade: Todos; Classe de conta: Todas; Sacado: Todos; Conta (bancária, caixinha...): Todas as contas;';

    /** Inadimplência: DOIS espaços após `Filtros:` e `até:` colado no valor. */
    private const INADIMPLENCIA = 'Filtros:  Inadimplência até:04/08/2026; Competência: Todas; Período de vencimento: Todos; Unidade: Todas; Sacado: Todos';

    private const ACORDOS_EM_ANDAMENTO = 'Filtros: Situação do acordo: Em andamento; Período de criação do acordo: Todos; Unidade/Cliente: Todos; Sacado: Todos';
    private const ACORDOS_LIQUIDADO = 'Filtros: Situação do acordo: Liquidado; Período de criação do acordo: Todos; Unidade/Cliente: Todos; Sacado: Todos';
    private const ACORDOS_CANCELADO = 'Filtros: Situação do acordo: Cancelado; Período de criação do acordo: Todos; Unidade/Cliente: Todos; Sacado: Todos';

    private const CADASTRO = 'Filtros: Unidades: Todas';

    private ValidadorRodapeFiltros $validador;

    protected function setUp(): void
    {
        $this->validador = new ValidadorRodapeFiltros();
    }

    #[Test]
    public function testAceitaReceitasSemJanelaDeRecebimento(): void
    {
        $r = $this->validador->validarTexto(self::RECEITAS_COMPLETA, RecorteEsperado::receitas());

        self::assertTrue($r->aceito, 'Receitas completa (recorte correto) deveria passar. Motivos: ' . implode(' | ', $r->motivos));
        self::assertSame([], $r->motivos);
    }

    /**
     * O caso que a 2ª injeção tem de avermelhar: se `todosOuOrfao` aceitar qualquer valor, a janela de
     * recebimento passa — e é exatamente ela que cortou 5 anos de histórico para 7 meses.
     */
    #[Test]
    public function testRecusaReceitasComJanelaDeRecebimento(): void
    {
        $r = $this->validador->validarTexto(self::RECEITAS_JANELA, RecorteEsperado::receitas());

        self::assertFalse($r->aceito);
        self::assertStringContainsString('Período de recebimento', implode(' | ', $r->motivos));
    }

    #[Test]
    public function testRecusaOArquivoManualDe0308ApontandoOsDoisCamposErrados(): void
    {
        $r = $this->validador->validarTexto(self::RECEITAS_MANUAL_03_08, RecorteEsperado::receitas());

        self::assertFalse($r->aceito);
        $motivos = implode(' | ', $r->motivos);
        self::assertStringContainsString('Situação das contas', $motivos);
        self::assertStringContainsString('Período de vencimento', $motivos);
    }

    /**
     * A trava contra o `contains` da §2.2.4 da spec: `str_contains('Baixadas', 'Baixada')` é TRUE, e
     * foi por sorte que a primeira conferência manual passou. Este caso é o que fica vermelho quando
     * alguém troca a comparação exata por `str_contains` — a 1ª injeção da prova por reintrodução.
     */
    #[Test]
    public function testRecusaSingularQuandoEsperaPlural(): void
    {
        $texto = str_replace('Situação das contas: Baixadas', 'Situação das contas: Baixada', self::RECEITAS_COMPLETA);

        $r = $this->validador->validarTexto($texto, RecorteEsperado::receitas());

        self::assertFalse($r->aceito, '"Baixada" (singular) não é "Baixadas" — comparação tem de ser exata.');
        self::assertStringContainsString('Situação das contas', implode(' | ', $r->motivos));
    }

    #[Test]
    public function testAceitaInadimplenciaComDoisEspacosEAteColado(): void
    {
        $r = $this->validador->validarTexto(self::INADIMPLENCIA, RecorteEsperado::inadimplencia());

        self::assertTrue($r->aceito, 'Motivos: ' . implode(' | ', $r->motivos));
    }

    /**
     * A data de corte muda a cada emissão e NÃO é validada como valor (spec §3.6). Um validador que a
     * fixasse recusaria toda emissão a partir do dia seguinte.
     */
    #[Test]
    public function testInadimplenciaPassaComQualquerDataDeCorte(): void
    {
        $texto = str_replace('até:04/08/2026', 'até:31/12/2027', self::INADIMPLENCIA);

        $r = $this->validador->validarTexto($texto, RecorteEsperado::inadimplencia());

        self::assertTrue($r->aceito, 'Motivos: ' . implode(' | ', $r->motivos));
    }

    #[Test]
    public function testAceitaAcordosEmAndamento(): void
    {
        $r = $this->validador->validarTexto(self::ACORDOS_EM_ANDAMENTO, RecorteEsperado::acordos());

        self::assertTrue($r->aceito, 'Motivos: ' . implode(' | ', $r->motivos));
    }

    #[Test]
    public function testAceitaAcordosLiquidado(): void
    {
        $r = $this->validador->validarTexto(self::ACORDOS_LIQUIDADO, RecorteEsperado::acordos());

        self::assertTrue($r->aceito, 'Motivos: ' . implode(' | ', $r->motivos));
    }

    /**
     * O dono decidiu que cancelados ficam FORA (handoff §5) e a instrução "não importar o
     * `*_CANCELADO.xlsx`" era só uma frase num documento. Aqui ela vira trava técnica.
     */
    #[Test]
    public function testRecusaAcordosCancelado(): void
    {
        $r = $this->validador->validarTexto(self::ACORDOS_CANCELADO, RecorteEsperado::acordos());

        self::assertFalse($r->aceito);
        self::assertStringContainsString('Situação do acordo', implode(' | ', $r->motivos));
    }

    #[Test]
    public function testAceitaCadastro(): void
    {
        $r = $this->validador->validarTexto(self::CADASTRO, RecorteEsperado::cadastro());

        self::assertTrue($r->aceito, 'Motivos: ' . implode(' | ', $r->motivos));
    }

    /**
     * A lista de campos não é fixa (spec §2.2.3): `Conta (bancária, caixinha...)` aparece em uns
     * arquivos e não em outros. Campo não declarado na expectativa é ignorado — senão o validador
     * quebraria sozinho a cada variação de emissão.
     */
    #[Test]
    public function testCampoExtraNaoDeclaradoNaoRecusa(): void
    {
        $texto = self::RECEITAS_COMPLETA . ' Campo Novo Que Eles Inventaram: Qualquer Coisa;';

        $r = $this->validador->validarTexto($texto, RecorteEsperado::receitas());

        self::assertTrue($r->aceito, 'Motivos: ' . implode(' | ', $r->motivos));
    }

    /**
     * Sem a linha `Filtros:` não há recorte para conferir. Recusar é obrigatório: "não achei" NÃO pode
     * virar "está tudo bem" — seria uma porta aberta silenciosa, que é o defeito que este item existe
     * para fechar.
     */
    #[Test]
    public function testRecusaQuandoNaoExisteLinhaDeFiltros(): void
    {
        $r = $this->validador->validarTexto(null, RecorteEsperado::receitas());

        self::assertFalse($r->aceito);
        self::assertStringContainsString('Filtros:', implode(' | ', $r->motivos));
    }

    /**
     * Campo que a expectativa exige e o arquivo não traz é recusa — o contrário do campo extra. Sem
     * isto, um rodapé truncado passaria por omissão.
     */
    #[Test]
    public function testRecusaQuandoCampoEsperadoEstaAusente(): void
    {
        $texto = 'Filtros: Situação das contas: Baixadas; Competência: Todas;';

        $r = $this->validador->validarTexto($texto, RecorteEsperado::receitas());

        self::assertFalse($r->aceito);
        self::assertStringContainsString('Período de vencimento', implode(' | ', $r->motivos));
    }

    /**
     * ⚠️ Achado da 1ª revisão: o ramo "chave ausente **e sem** órfão" não tinha teste que morresse
     * sozinho — trocar aquele `return sprintf(...)` por `return null` mantinha tudo verde. É a direção
     * FROUXA: um rodapé que perdesse o `Período de recebimento` sem deixar `Todos` no lugar passaria,
     * e o recorte seria desconhecido em vez de "todos".
     *
     * Este é o único teste em que a chave está ausente E não há nenhum órfão na linha.
     */
    #[Test]
    public function testRecebimentoAusenteSemOrfaoEhRecusado(): void
    {
        $texto = 'Filtros: Situação das contas: Baixadas; Competência: Todas; Período de vencimento: Todos; Unidade: Todos; Classe de conta: Todas; Sacado: Todos;';

        $r = $this->validador->validarTexto($texto, RecorteEsperado::receitas());

        self::assertFalse($r->aceito, 'sem a chave e sem órfão, o recorte é desconhecido — não "todos"');
        self::assertStringContainsString('Período de recebimento', implode(' | ', $r->motivos));
        self::assertStringContainsString('recorte desconhecido', implode(' | ', $r->motivos));
    }

    /**
     * "Primeira ocorrência vence" (achado da 1ª revisão: a regra existia sem teste, e a mutação
     * "última vence" sobrevivia). Importa porque uma segunda ocorrência da mesma chave poderia
     * sobrescrever, DEPOIS da conferência, o valor que foi conferido.
     */
    #[Test]
    public function testChaveRepetidaVenceAPrimeiraOcorrencia(): void
    {
        $texto = str_replace(
            'Sacado: Todos;',
            'Sacado: Todos; Situação das contas: Aberta e baixada;',
            self::RECEITAS_COMPLETA,
        );

        $r = $this->validador->validarTexto($texto, RecorteEsperado::receitas());

        self::assertTrue($r->aceito, 'a 1ª ocorrência ("Baixadas") é a que vale; a 2ª não pode derrubá-la');
    }

    /**
     * O vencimento mantém o rótulo mesmo valendo `Todos`, e o recebimento o perde — são regras
     * diferentes para campos vizinhos (spec §2.2.2). Se o vencimento sumisse, seria recorte
     * desconhecido, não "todos": recusa.
     */
    #[Test]
    public function testVencimentoAusenteNaoEhTratadoComoTodos(): void
    {
        $texto = str_replace('Período de vencimento: Todos; ', '', self::RECEITAS_COMPLETA);

        $r = $this->validador->validarTexto($texto, RecorteEsperado::receitas());

        self::assertFalse($r->aceito);
        self::assertStringContainsString('Período de vencimento', implode(' | ', $r->motivos));
    }
}
