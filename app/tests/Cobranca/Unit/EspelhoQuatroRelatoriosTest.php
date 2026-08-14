<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Enum\TabelaDoAcordo;
use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Cobranca\Service\Espelho\ClassificadorDeRelatorio;
use App\Cobranca\Service\Espelho\LeitorEspelhoAcordos;
use App\Cobranca\Service\Espelho\LeitorEspelhoCadastro;
use App\Cobranca\Service\Espelho\LeitorEspelhoReceitas;
use App\Cobranca\Service\Espelho\ReconciliacaoInternaFalhouException;
use App\Tests\Cobranca\Support\MontaPlanilhasDosQuatroRelatorios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os invariantes da SPEC `cobranca-espelho-quatro-relatorios.md`.
 *
 * Cada teste aqui foi provado REINTRODUZINDO o defeito que ele guarda — a suíte deste módulo já foi
 * cega para um defeito de dinheiro por causa de um helper que zerava encargos, e teste verde só vale
 * depois de ter ficado vermelho pelo motivo certo. O que cada um pega está no seu docblock.
 */
#[CoversClass(ClassificadorDeRelatorio::class)]
#[CoversClass(LeitorEspelhoAcordos::class)]
#[CoversClass(LeitorEspelhoReceitas::class)]
#[CoversClass(LeitorEspelhoCadastro::class)]
final class EspelhoQuatroRelatoriosTest extends TestCase
{
    use MontaPlanilhasDosQuatroRelatorios;

    protected function tearDown(): void
    {
        $this->limparPlanilhasDosQuatro();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- INV-Q6

    /**
     * Os nomes REAIS que a emissão produz, mais os que o download pelo navegador produz (com acento
     * e sufixo ` (1)`). Um leitor que só reconheça a forma do script quebra no dia em que alguém
     * baixar à mão — que é como as planilhas de `docs/gestao-cobrancas/` chegaram.
     *
     * @return iterable<string, array{0: string, 1: TipoRelatorioContabil}>
     */
    public static function nomesReais(): iterable
    {
        yield 'inadimplência do script' => ['top_life_1_Inadimplencias_detalhadas.xlsx', TipoRelatorioContabil::Inadimplencia];
        yield 'inadimplência com acento' => ['TOPLIFE I_Inadimplências_detalhadas_2026_07_08 (1) (1).xlsx', TipoRelatorioContabil::Inadimplencia];
        yield 'acordos em andamento' => ['top_life_1_Acordos_detalhados_EM_ANDAMENTO.xlsx', TipoRelatorioContabil::Acordos];
        yield 'acordos liquidado' => ['amli_br_060_Acordos_detalhados_LIQUIDADO.xlsx', TipoRelatorioContabil::Acordos];
        yield 'receitas' => ['top_life_2_Receitas_detalhadas.xlsx', TipoRelatorioContabil::Receitas];
        yield 'cadastro' => ['amli_br_060_Dados_cadastrais.xlsx', TipoRelatorioContabil::Cadastro];
    }

    #[DataProvider('nomesReais')]
    #[TestDox('Classifica os nomes que a contabilidade realmente emite')]
    public function testClassificaNomesReais(string $nome, TipoRelatorioContabil $esperado): void
    {
        self::assertSame($esperado, (new ClassificadorDeRelatorio())->classificar($nome));
    }

    /**
     * 🔴 O teste que guarda a causa-raiz desta fatia.
     *
     * O filtro antigo (`stripos($nome, 'nadimpl') !== false`) descartava CALADO tudo que não casasse,
     * e foi assim que três relatórios ficaram semanas fora do espelho. `classificar()` devolvendo
     * `null` é o que obriga quem chama a recusar em vez de pular.
     *
     * **Reintrodução provada:** trocando o `null` por
     * `TipoRelatorioContabil::Inadimplencia` como padrão, este teste fica vermelho.
     */
    #[TestDox('Arquivo que não é nenhum dos quatro NÃO ganha tipo por padrão')]
    public function testArquivoDesconhecidoNaoGanhaTipoPadrao(): void
    {
        $classificador = new ClassificadorDeRelatorio();

        self::assertNull($classificador->classificar('planilha_qualquer.xlsx'));
        self::assertStringContainsString('não identifica', $classificador->motivoDaRecusa('planilha_qualquer.xlsx'));
    }

    /**
     * `detalhadas` aparece em TRÊS dos quatro nomes. Um classificador que a usasse como fragmento
     * mandaria receitas para o leitor de inadimplência.
     *
     * **Reintrodução provada:** acrescentando `'detalhadas' => Inadimplencia` aos padrões, o nome de
     * receitas passa a casar com dois e este teste fica vermelho.
     */
    #[TestDox('Nome ambíguo é recusado, não resolvido pelo primeiro que casar')]
    public function testNomeAmbiguoEhRecusado(): void
    {
        $classificador = new ClassificadorDeRelatorio();

        self::assertNull($classificador->classificar('Acordos_e_Receitas_detalhados.xlsx'));
        self::assertStringContainsString('mais de um', $classificador->motivoDaRecusa('Acordos_e_Receitas_detalhados.xlsx'));
    }

    // ---------------------------------------------------------------- INV-Q9

    /**
     * 🔴 O espaço não-quebrável.
     *
     * A linha de desconto vem como TEXTO `- 15,00` com U+00A0 entre o sinal e o algarismo. `trim()`
     * não o remove e `\s` sem `/u` também não — a conversão ingênua devolve `null`, o desconto some
     * da soma e **nada falha**. Na primeira medição desta frente isso fez o portão parecer recusar
     * 47 de 325 abas que na verdade fechavam.
     *
     * Aqui o acordo é 100,00 − 15,00 + 45,00 = 130,00, que é o `Valor final acordado` declarado. Se o
     * desconto sumir, a soma dá 145,00 e o portão recusa.
     *
     * **Reintrodução provada:** trocando o `str_replace` das ESPACOS_INVISIVEIS por `trim()` em
     * `LeCelulasDoEspelho::centavos()`, este teste fica vermelho com
     * "parcelas somam 14500, o acordo declara 13000".
     */
    #[TestDox('Desconto com espaço não-quebrável ENTRA na soma (INV-Q9)')]
    public function testDescontoComEspacoNaoQuebravelEntraNaSoma(): void
    {
        $arquivo = $this->montarPlanilhaDeAcordos([[
            'numero' => 25,
            'valorFinal' => '130,00',
            'parcelas' => [
                ['1.1 - Taxa de condomínio', '100,00'],
                ['1.6 - Descontos', $this->negativoComoAContabilidadeEscreve('15,00')],
                ['1.14 - Energia', '45,00'],
            ],
        ]]);

        $leitor = new LeitorEspelhoAcordos();
        $espelhado = $leitor->ler($arquivo);

        $soma = 0;

        foreach ($espelhado->linhasDeDados() as $linha) {
            if ($linha->tabela === TabelaDoAcordo::Parcelas->value) {
                $soma += $linha->valor ?? 0;
            }
        }

        self::assertSame(13000, $soma, 'o desconto de -15,00 tem de entrar na soma');
        $leitor->exigirReconciliacaoInterna($espelhado);
    }

    // ------------------------------------------------------- acordos, layout

    /**
     * As linhas da tabela de contas originais vêm espaçadas de duas em duas. Um leitor que pare na
     * primeira linha em branco lê UMA conta e reporta sucesso — sem erro, sem aviso.
     *
     * **Reintrodução provada:** fazendo `classificarAba()` encerrar a tabela corrente ao encontrar
     * `BlocoRelatorio::Branca`, este teste cai de 3 para 1.
     */
    #[TestDox('Linhas espaçadas não interrompem a leitura das contas originais')]
    public function testLinhasEspacadasNaoInterrompemALeitura(): void
    {
        $arquivo = $this->montarPlanilhaDeAcordos([[
            'numero' => 377,
            'valorFinal' => '150,00',
            'originais' => ['50,00', '50,00', '50,00'],
            'parcelas' => [['1.1 - Taxa de condomínio', '150,00']],
        ]]);

        $espelhado = (new LeitorEspelhoAcordos())->ler($arquivo);

        $originais = array_filter(
            $espelhado->linhasDeDados(),
            static fn ($l): bool => $l->tabela === TabelaDoAcordo::ContasOriginais->value,
        );

        self::assertCount(3, $originais, 'as três contas originais, apesar das linhas em branco entre elas');
    }

    /**
     * INV-Q5/INV-A1: toda linha do arquivo cai em exatamente um balde, somando o total — inclusive
     * com várias abas. É a prova de que o leitor não perdeu nada.
     */
    #[TestDox('Toda linha de toda aba cai em um balde (INV-A1)')]
    public function testNadaSePerdeEntreAsAbas(): void
    {
        $arquivo = $this->montarPlanilhaDeAcordos([
            ['numero' => 1, 'valorFinal' => '100,00', 'parcelas' => [['1.1 - Taxa de condomínio', '100,00']]],
            ['numero' => 2, 'valorFinal' => '200,00', 'parcelas' => [['1.1 - Taxa de condomínio', '200,00']]],
        ]);

        $espelhado = (new LeitorEspelhoAcordos())->ler($arquivo);

        self::assertSame($espelhado->linhasTotal, array_sum($espelhado->contarPorBloco()));
        self::assertCount(2, $espelhado->totalizadores, 'um "Valor final acordado" por aba');
    }

    /**
     * INV-Q8 — a tolerância é de arredondamento de RATEIO: só para menos, e no máximo um centavo por
     * linha de parcela. Uma aba que sobre-some, ou que falte mais do que isso, é recusada.
     *
     * A régua é por LINHA e não por parcela porque o `Acordo n105` real tem 1 parcela, 38 linhas e
     * diferença de −2 centavos.
     */
    #[TestDox('Aba que falta mais que o rateio é RECUSADA')]
    public function testAbaForaDaToleranciaEhRecusada(): void
    {
        // Duas linhas de parcela somam 99,00 contra 100,00 declarados: faltam 100 centavos para 2
        // linhas, muito além do centavo por linha.
        $arquivo = $this->montarPlanilhaDeAcordos([[
            'numero' => 9,
            'valorFinal' => '100,00',
            'parcelas' => [
                ['1.1 - Taxa de condomínio', '50,00'],
                ['1.14 - Energia', '49,00'],
            ],
        ]]);

        $leitor = new LeitorEspelhoAcordos();

        $this->expectException(ReconciliacaoInternaFalhouException::class);
        $leitor->exigirReconciliacaoInterna($leitor->ler($arquivo));
    }

    #[TestDox('Aba que falta só o arredondamento do rateio PASSA')]
    public function testAbaDentroDaToleranciaPassa(): void
    {
        // Três linhas somam 99,98 contra 100,00: faltam 2 centavos para 3 linhas — é rateio.
        $arquivo = $this->montarPlanilhaDeAcordos([[
            'numero' => 61,
            'valorFinal' => '100,00',
            'parcelas' => [
                ['1.1 - Taxa de condomínio', '33,33'],
                ['1.1 - Taxa de condomínio', '33,33'],
                ['1.1 - Taxa de condomínio', '33,32'],
            ],
        ]]);

        $leitor = new LeitorEspelhoAcordos();
        $espelhado = $leitor->ler($arquivo);

        $leitor->exigirReconciliacaoInterna($espelhado);

        // A tolerância é CONTADA e QUANTIFICADA — abas e centavos. Reportar só as abas esconderia se
        // um dia a folga passasse a ser consumida de verdade.
        self::assertSame(['abas' => 1, 'centavos' => 2], $leitor->toleranciaConsumida($espelhado));
    }

    /**
     * ⚠️ Tolerância silenciosa é o descarte silencioso do INV-Q6 com outro nome. Uma aba que fecha
     * exatamente não pode ser contada como tolerada, senão o número perde o sentido.
     */
    #[TestDox('Aba que fecha exato não conta como tolerância')]
    public function testAbaExataNaoContaComoTolerancia(): void
    {
        $arquivo = $this->montarPlanilhaDeAcordos([[
            'numero' => 7,
            'valorFinal' => '100,00',
            'parcelas' => [['1.1 - Taxa de condomínio', '100,00']],
        ]]);

        $leitor = new LeitorEspelhoAcordos();

        self::assertSame(['abas' => 0, 'centavos' => 0], $leitor->toleranciaConsumida($leitor->ler($arquivo)));
    }

    /** 🔑 INV-Q1: este relatório não publica encargo, e o leitor não pode inventar um. */
    #[TestDox('O relatório de acordos não produz encargo nenhum (INV-Q1)')]
    public function testAcordosNaoProduzemEncargo(): void
    {
        $arquivo = $this->montarPlanilhaDeAcordos([[
            'numero' => 3,
            'valorFinal' => '100,00',
            'parcelas' => [['1.15 - Honorário advocatício', '100,00']],
        ]]);

        foreach ((new LeitorEspelhoAcordos())->ler($arquivo)->linhasDeDados() as $linha) {
            self::assertNull($linha->juros);
            self::assertNull($linha->multa);
            self::assertNull($linha->correcao);
            self::assertNull($linha->honorarios);
        }
    }


    /**
     * 🔴 Achado 9 da revisão — o descarte silencioso renascido num lugar novo.
     *
     * O portão itera os TOTALIZADORES. Uma aba cujo `Valor final acordado` some ou não converta não
     * produz totalizador — e, sem esta guarda, **não entrava nem em conferência nem em recusa**: era
     * gravada sem que nada conferisse suas parcelas, calada. Medido: 0 abas nessa situação nos 6
     * arquivos reais, então é furo latente, não defeito vivo.
     *
     * **Reintrodução provada:** trocando o `if ($semDeclaracao !== [])` por `if (false)` em
     * `LeitorEspelhoAcordos::exigirReconciliacaoInterna()`, este teste fica vermelho.
     */
    #[TestDox('🔴 Aba sem "Valor final acordado" é RECUSADA, não ignorada')]
    public function testAbaSemValorDeclaradoEhRecusada(): void
    {
        $arquivo = $this->montarPlanilhaDeAcordos([
            ['numero' => 1, 'valorFinal' => '100,00', 'parcelas' => [['1.1 - Taxa de condomínio', '100,00']]],
            ['numero' => 2, 'valorFinal' => null, 'parcelas' => [['1.1 - Taxa de condomínio', '999,00']]],
        ]);

        $leitor = new LeitorEspelhoAcordos();
        $espelhado = $leitor->ler($arquivo);

        self::assertSame(['Acordo n2'], $leitor->abasSemValorDeclarado($espelhado));

        $this->expectException(ReconciliacaoInternaFalhouException::class);
        $this->expectExceptionMessageMatches('/não trazem "Valor final acordado"/');
        $leitor->exigirReconciliacaoInterna($espelhado);
    }

    /** A contrapartida: aba com declaração legível NÃO pode ser acusada (o `isset` de dois argumentos já errou isso). */
    #[TestDox('Aba com valor declarado não é acusada de faltar declaração')]
    public function testAbaComValorDeclaradoNaoEhAcusada(): void
    {
        $arquivo = $this->montarPlanilhaDeAcordos([
            ['numero' => 1, 'valorFinal' => '100,00', 'parcelas' => [['1.1 - Taxa de condomínio', '100,00']]],
            ['numero' => 2, 'valorFinal' => '200,00', 'parcelas' => [['1.1 - Taxa de condomínio', '200,00']]],
        ]);

        $leitor = new LeitorEspelhoAcordos();

        self::assertSame([], $leitor->abasSemValorDeclarado($leitor->ler($arquivo)));
    }


    // ------------------------------------------------- INV-Q9b (formato pt-BR)

    /**
     * 🔴 A guarda de formato — o único conserto das 15 correções que tinha entrado SEM teste, e a
     * revisão pegou.
     *
     * `centavos()` trata o ponto como separador de MILHAR (pt-BR). Sem a guarda, um valor en-US
     * escorregaria para cem vezes o valor certo, **calado**: `'1234.56'` → `123456` → R$ 123.456,00.
     * A distorção seria UNIFORME (linhas e totalizador escalando juntos), o portão fecharia, e o
     * espelho ficaria 100× errado com cara de verdade.
     *
     * Recusar devolve `null`, e `null` em coluna de dinheiro derruba o portão: falha ALTA.
     *
     * **Reintrodução provada:** removendo o `preg_match` de `LeCelulasDoEspelho::centavos()`,
     * `'1234.56'` volta a virar 12.345.600 centavos e este teste fica vermelho.
     *
     * @return iterable<string, array{0: string, 1: ?int}>
     */
    public static function celulasDeDinheiro(): iterable
    {
        // aceitos — as formas que a contabilidade realmente emite
        yield 'inteiro' => ['100', 10000];
        yield 'pt-BR com decimal' => ['1234,56', 123456];
        yield 'pt-BR com milhar' => ['1.234,56', 123456];
        yield 'pt-BR com dois milhares' => ['1.234.567,89', 123456789];
        // ⚠️ O ÚNICO ambíguo que a guarda deixa passar convertido: `1.234` é milhar pt-BR válido
        // (R$ 1.234,00) e é também `1.234` en-US. Se um arquivo vier em en-US, este é o formato em
        // que o erro escapa — e escaparia por **1000×**, não por 100×. Aceitar é decisão consciente:
        // recusá-lo recusaria milhar pt-BR legítimo sem decimais, que a contabilidade emite.
        // Medido: nos 15 arquivos reais o dinheiro chega como `double` nativo, então não morde hoje.
        yield 'milhar pt-BR sem decimais (ambíguo, aceito de propósito)' => ['1.234', 123400];
        yield 'negativo' => ['-15,00', -1500];
        yield 'negativo com U+00A0' => ["-\xC2\xA015,00", -1500];

        // recusados — devolvem null, e null derruba o portão
        yield 'en-US decimal (o 100x)' => ['1234.56', null];
        yield 'en-US com milhar' => ['1,234.56', null];
        yield 'notação científica' => ['1e3', null];
        yield 'milhar malformado' => ['1.5.9', null];
        yield 'classe de conta, não dinheiro' => ['1.5', null];
        yield 'texto' => ['n/a', null];
        yield 'traço de não-se-aplica' => ['-', null];
    }

    #[DataProvider('celulasDeDinheiro')]
    #[TestDox('Só o formato pt-BR vira dinheiro; o resto é recusado, não convertido errado')]
    public function testConversaoDeCentavosSoAceitaPtBr(string $celula, ?int $esperado): void
    {
        // O trait é privado por design; o leitor de receitas é o caminho público mais curto até ele.
        $conversor = new class {
            use \App\Cobranca\Service\Espelho\LeCelulasDoEspelho;

            public function converter(mixed $v): ?int
            {
                return $this->centavos($v);
            }
        };

        self::assertSame($esperado, $conversor->converter($celula));
    }

    /** O `1.5` do caso acima não é hipotético: é o prefixo de `1.5 - Multas`, uma classe de conta. */
    #[TestDox('Número puro da planilha continua passando (não é texto)')]
    public function testNumeroNativoNaoPassaPelaGuardaDeTexto(): void
    {
        $conversor = new class {
            use \App\Cobranca\Service\Espelho\LeCelulasDoEspelho;

            public function converter(mixed $v): ?int
            {
                return $this->centavos($v);
            }
        };

        // É assim que o dinheiro chega nos arquivos reais — `double` nativo, não texto.
        self::assertSame(123456, $conversor->converter(1234.56));
        self::assertSame(-1500, $conversor->converter(-15.0));
    }

    // ------------------------------------------------------------- receitas

    #[TestDox('Receitas: o portão fecha nas DUAS colunas de dinheiro')]
    public function testReceitasFechamNasDuasColunas(): void
    {
        $arquivo = $this->montarPlanilhaDeReceitas([
            ['1.1 - Taxa de condomínio', '100,00', '100,00'],
            ['1.5 - Multas', '2,00', '1,50'],
        ]);

        $leitor = new LeitorEspelhoReceitas();
        $espelhado = $leitor->ler($arquivo);
        $leitor->exigirReconciliacaoInterna($espelhado);

        $recebido = 0;

        foreach ($espelhado->linhasDeDados() as $linha) {
            $recebido += $linha->valorRecebido ?? 0;
        }

        self::assertSame(10150, $recebido, 'o recebido é campo PRÓPRIO, não o valor repetido');
    }

    /**
     * 🔴 Conferir só a coluna `Valor` deixaria passar erro na `Valor recebido` — e é a segunda que
     * diz quanto dinheiro entrou de verdade. Medição vale pelo recorte que cobre.
     *
     * **Reintrodução provada:** removendo a comparação de `$somaRecebido` em
     * `LeitorEspelhoReceitas::exigirReconciliacaoInterna()`, este teste fica vermelho.
     */
    #[TestDox('Receitas: adulterar SÓ o total recebido é pego')]
    public function testReceitasPegamAdulteracaoNoRecebido(): void
    {
        $arquivo = $this->montarPlanilhaDeReceitas(
            [['1.1 - Taxa de condomínio', '100,00', '100,00']],
            totalAdulterado: ['100,00', '90,00'],
        );

        $leitor = new LeitorEspelhoReceitas();

        $this->expectException(ReconciliacaoInternaFalhouException::class);
        $leitor->exigirReconciliacaoInterna($leitor->ler($arquivo));
    }

    // ------------------------------------------------------------- cadastro

    /**
     * 🔴 O portão do cadastro NÃO pode ser de contagem.
     *
     * Medido nos arquivos reais de 10/08: TOP LIFE I declara 230 e tem 242 linhas; TOP LIFE II
     * declara **também 230** e tem 243; AMLI declara 51 e tem 52. Um portão de contagem recusaria os
     * três, todo mês, para sempre.
     *
     * **Reintrodução provada:** fazendo `exigirReconciliacaoInterna()` comparar
     * `unidadesDeclaradas` com `count(linhasDeDados())`, este teste fica vermelho.
     */
    #[TestDox('Cadastro: o rótulo "Total de unidades" NÃO é portão')]
    public function testCadastroNaoUsaContagemComoPortao(): void
    {
        $arquivo = $this->montarPlanilhaDeCadastro(['01-01', '01-02', '01-03'], totalDeclarado: 230);

        $leitor = new LeitorEspelhoCadastro();
        $espelhado = $leitor->ler($arquivo);

        $leitor->exigirReconciliacaoInterna($espelhado);

        self::assertSame(230, $espelhado->unidadesDeclaradas, 'guardado…');
        self::assertCount(3, $espelhado->linhasDeDados(), '…e diferente da contagem real, sem isso ser erro');
    }

    #[TestDox('Cadastro: emissão sem nenhum condômino é recusada')]
    public function testCadastroVazioEhRecusado(): void
    {
        $arquivo = $this->montarPlanilhaDeCadastro([]);

        $leitor = new LeitorEspelhoCadastro();

        $this->expectException(ReconciliacaoInternaFalhouException::class);
        $leitor->exigirReconciliacaoInterna($leitor->ler($arquivo));
    }

    /**
     * A coluna 1 é `Nome/Nome fantasia` (quem é) e a 2 é `Sacado`, que aqui guarda o PAPEL
     * (`Proprietário`). Trocar as duas encheria o campo de "Proprietário" repetido e quebraria o
     * casamento por nome com as outras fontes.
     */
    #[TestDox('Cadastro: o sacado é o NOME, não o papel')]
    public function testCadastroGuardaNomeNoSacado(): void
    {
        $arquivo = $this->montarPlanilhaDeCadastro(['01-01']);

        $dados = (new LeitorEspelhoCadastro())->ler($arquivo)->linhasDeDados();

        self::assertSame('CONDOMINO 0', $dados[0]->sacado);
        self::assertSame('Proprietário', $dados[0]->classe);
    }

    #[TestDox('Cadastro: o rodapé não vira condômino')]
    public function testCadastroNaoLeRodapeComoDado(): void
    {
        $arquivo = $this->montarPlanilhaDeCadastro(['01-01', '01-02']);

        $espelhado = (new LeitorEspelhoCadastro())->ler($arquivo);

        self::assertCount(2, $espelhado->linhasDeDados());
        self::assertGreaterThan(0, $espelhado->contarPorBloco()[BlocoRelatorio::Rodape->value]);
        self::assertSame($espelhado->linhasTotal, array_sum($espelhado->contarPorBloco()));
    }
}
