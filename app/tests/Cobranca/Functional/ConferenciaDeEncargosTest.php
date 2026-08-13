<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioLinha;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Espelho\AgrupadorDeBoletos;
use App\Cobranca\Service\Espelho\ConferenciaDeEncargos;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A régua do encargo GRAVADO (SPEC espelho §17) — a peça que faltava para a Fase 1 poder provar o
 * próprio conserto.
 *
 * O teste central é o da ASSINATURA: uma parcela de acordo cujo juros gravado é a coluna do relatório
 * mais o valor da linha `1.4`. É a forma exata da dupla contagem, e é o que separa "defeito" de
 * "snapshot velho".
 */
#[CoversClass(ConferenciaDeEncargos::class)]
final class ConferenciaDeEncargosTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private EntityManagerInterface $em;
    private ConferenciaDeEncargos $conferencia;
    private GravarEspelhoRelatorioUseCase $gravar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var RelatorioImportadoRepository $relatorios */
        $relatorios = $this->em->getRepository(RelatorioImportado::class);
        /** @var RelatorioLinhaRepository $linhas */
        $linhas = $this->em->getRepository(RelatorioLinha::class);
        /** @var ObrigacaoRepository $obrigacoes */
        $obrigacoes = $this->em->getRepository(Obrigacao::class);

        $this->gravar = new GravarEspelhoRelatorioUseCase(new LeitorEspelhoRelatorio(), $relatorios, $this->em);
        $this->conferencia = new ConferenciaDeEncargos(
            new AgrupadorDeBoletos($linhas),
            $obrigacoes,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            $relatorios,
        );
    }

    /**
     * A data de emissão do lote que os testes de caso único montam — e, por consequência do INV-CE6, a
     * data em que o snapshot das obrigações deles TEM de estar carimbado.
     *
     * Existe como constante porque a igualdade entre as duas datas era **acidental**: o snapshot vinha
     * de `$vencimento->modify('+240 days')`, que a partir de 15/12/2025 cai justamente em 12/08/2026.
     * Enquanto a régua lia o último lote isso não fazia diferença; depois do INV-CE6 faz, e uma
     * coincidência aritmética não pode ser o que segura um teste de dinheiro de pé.
     */
    private const EMISSAO_DO_LOTE = '12/08/2026';

    /** A mesma data da {@see self::EMISSAO_DO_LOTE}, no formato do snapshot. */
    private function snapshotNaEmissaoDoLote(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-12');
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('§17.3 — encargo gravado que a fórmula reproduz na data do snapshot é COERENTE')]
    public function testEncargoCoerenteComAFormula(): void
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '01-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        // Os números pinados de `CalculadoraEncargosTest`: P=170,00 · 240 dias · 20%.
        $this->obrigacao($caso, '74608', '12/2025', 17000, $vencimento, $snapshot, 1360, 340, 0, 3740);

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 170.00, juros: 13.60, multa: 3.40, correcao: 0.0, honorarios: 37.40,
            ),
        ]);

        self::assertSame(1, $r->conferidos);
        self::assertSame(1, $r->coerentes, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame(0, $r->comDuplaContagem);
        self::assertSame('coerente', $r->veredito());
    }

    #[TestDox('🔴 §17.3 — juros gravado = coluna do relatório + H da linha 1.4 é DUPLA CONTAGEM')]
    public function testAssinaturaDaDuplaContagemEhReconhecida(): void
    {
        // A forma EXATA do defeito 2: parcela de acordo cujo `valorOriginal` já soma o H da linha
        // `1.4 - Juros`, e cujo juros gravado soma esse MESMO H de novo, por cima da coluna I.
        //   linha 1.1  → valor 400,00 · juros 30,00
        //   linha 1.4  → valor  45,45 · juros  5,00     ← o H de 45,45 é o dinheiro duplicado
        //   coluna I somada = 35,00 · gravado = 35,00 + 45,45 = 80,45
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '01-02');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        $this->obrigacao($caso, '67611', '12/2025', 44545, $vencimento, $snapshot, 8045, 891, 0, 10854);

        $comum = [
            'unidade' => '01-02', 'nn' => '67611', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 30.00, multa: 8.00, honorarios: 100.00),
            $this->linhaDeDado(...$comum, classe: '1.4 - Juros', valor: 45.45, juros: 5.00, multa: 0.91, honorarios: 8.54),
        ]);

        self::assertSame(1, $r->conferidos);
        self::assertSame(1, $r->comDuplaContagem, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame(4545, $r->duplicadoEmCentavos, 'o duplicado é o H da linha 1.4, ao centavo');
        self::assertSame(0, $r->divergentes, 'não pode cair também no balde genérico');
        self::assertSame('dupla contagem', $r->veredito());
    }

    #[TestDox('🔴 INV-CE5 — a assinatura na MULTA (linha 1.5) é o campo que disparou em produção')]
    public function testAssinaturaNaMulta(): void
    {
        // Em produção o defeito materializou pela MULTA, não pelo juros: 22 em multa, 0 em juros.
        // Os testes só cobriam juros — a suíte ficaria verde com a régua cega no campo que importa.
        //   1.1  → valor 400,00 · multa 8,00
        //   1.5  → valor  45,45 · multa 0,91
        //   Σ J = 8,91 · multa gravada = 8,91 + 45,45 = 54,36
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '02-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        $this->obrigacao($caso, '74790', '12/2025', 44545, $vencimento, $snapshot, 3560, 5436, 0, 0);

        $r = $this->conferir($carteira, $this->boletoDeAcordo('02-01', '74790', multaDaLinha15: 0.91));

        self::assertSame(1, $r->comDuplaContagem, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame(4545, $r->duplicadoEmCentavos);
        self::assertSame(['multa' => 4545], $r->duplicadoPorCampo);
    }

    #[TestDox('🔴 INV-CE5 — no honorário o adapter TROCA a coluna L pelo H, e a assinatura segue isso')]
    public function testAssinaturaNoHonorarioUsaAFormaDoAdapter(): void
    {
        // `TopLifeInadimplenciaAdapter:186`: na linha 1.15 soma `$valor` NO LUGAR de `$hono`.
        // Testar `Σ_todas L + H` nunca casaria quando a própria 1.15 traz valor na coluna L —
        // medido em produção: 26 boletos assim, escondendo R$ 173,28 de dinheiro duplicado.
        //   1.1   → valor 400,00 · honorário 80,00
        //   1.15  → valor  45,45 · honorário 10,00   ← este 10,00 é DESCARTADO pelo adapter
        //   gravado = 80,00 + 45,45 = 125,45   (e NÃO 90,00 + 45,45)
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '02-02');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        $this->obrigacao($caso, '74791', '12/2025', 44545, $vencimento, $snapshot, 3560, 891, 0, 12545);

        $comum = [
            'unidade' => '02-02', 'nn' => '74791', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 80.00),
            $this->linhaDeDado(...$comum, classe: '1.15 - Honorário', valor: 45.45, juros: 3.60, multa: 0.91, honorarios: 10.00),
        ]);

        self::assertSame(1, $r->comDuplaContagem, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame(['honorarios' => 4545], $r->duplicadoPorCampo);
    }

    #[TestDox('🔴 INV-CE5 — boleto COMUM com linha de encargo NÃO é dupla contagem, é o adapter certo')]
    public function testBoletoComumComLinhaDeEncargoNaoEhDuplaContagem(): void
    {
        // O H só entra no `valorOriginal` no ramo do ACORDO. No boleto comum o principal são apenas
        // as classes 1.1/1.14/1.6, e somar o H da linha ao encargo é o comportamento documentado e
        // CORRETO do adapter (INV-E1) — o dinheiro aparece uma vez só. Sem esta guarda a régua
        // acusava o único boleto comum com linha de encargo da TOP LIFE I: R$ 2,90 de acusação falsa.
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '02-03');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        // `valorOriginal` = 400,00 (só a classe 1.1) e NÃO 445,45 — é o que caracteriza o boleto comum.
        $this->obrigacao($caso, '74792', '12/2025', 40000, $vencimento, $snapshot, 3200, 5436, 0, 0);

        $r = $this->conferir($carteira, $this->boletoDeAcordo('02-03', '74792', multaDaLinha15: 0.91, semAcordo: true));

        self::assertSame(0, $r->comDuplaContagem, 'o H não está no principal: não há dinheiro duplicado');
        self::assertSame([], $r->duplicadoPorCampo);
        self::assertSame(1, $r->divergentes, 'continua divergente — só não é ACUSADO de duplicar');
    }

    #[TestDox('A assinatura pode bater em DOIS campos na mesma dívida, e os dois são somados')]
    public function testAssinaturaEmDoisCamposNaMesmaDivida(): void
    {
        // Ocorre no dado real (NNs 61600 e 61821, TOP LIFE II): multa E honorário na mesma dívida.
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '02-04');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        // Σ H = 400,00 + 20,00 + 45,45 = 465,45
        // multa gravada = (8,00+0,40+0,91) + 20,00 = 29,31 · honorário = (80,00+4,00) + 45,45 = 129,45
        $this->obrigacao($caso, '74793', '12/2025', 46545, $vencimento, $snapshot, 3720, 2931, 0, 12945);

        $comum = [
            'unidade' => '02-04', 'nn' => '74793', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 80.00),
            $this->linhaDeDado(...$comum, classe: '1.5 - Multas', valor: 20.00, juros: 1.60, multa: 0.40, honorarios: 4.00),
            $this->linhaDeDado(...$comum, classe: '1.15 - Honorário', valor: 45.45, juros: 3.60, multa: 0.91, honorarios: 10.00),
        ]);

        self::assertSame(1, $r->comDuplaContagem, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame(['multa' => 2000, 'honorarios' => 4545], $r->duplicadoPorCampo);
        self::assertSame(6545, $r->duplicadoEmCentavos);
    }

    #[TestDox('Encargo MAIOR que a coluna, sem a assinatura, NÃO é dupla contagem — discrimina a régua')]
    public function testEncargoMaiorQueAColunaSemAAssinaturaNaoEhDuplaContagem(): void
    {
        // Este é o teste que separa a régua CERTA da régua errada plausível.
        //
        // A régua certa é IGUALDADE: gravado == coluna do relatório + H da linha de encargo. A régua
        // que qualquer um escreveria primeiro é "gravado MAIOR que a coluna" — e ela acusaria esta
        // dívida, que não tem defeito nenhum: o encargo simplesmente cresceu por passagem de tempo.
        //
        // O juros gravado (50,00) NÃO reproduz a fórmula na data do snapshot, então a dívida é mesmo
        // divergente — há um número ali sem procedência. Mas divergente é o balde brando: pode ser
        // edição manual, migração antiga, qualquer coisa.
        //
        // A coluna I do relatório soma 35,00 e o H da linha 1.4 é 45,45 → a assinatura seria 80,45.
        // 50,00 é MAIOR que 35,00 e DIFERENTE de 80,45. A régua certa manda para "divergente";
        // a régua "maior que" grita DUPLA CONTAGEM e transforma ruído em acusação de dinheiro
        // cobrado duas vezes.
        //
        // ⚠️ O snapshot fica na data de emissão do lote DE PROPÓSITO (INV-CE6). Antes ele era
        // `+400 days`, uma data sem lote nenhum — o que hoje mandaria a dívida para o balde
        // INJULGÁVEL, e a assinatura nunca chegaria a ser avaliada. O teste continuaria verde e
        // deixaria de provar o que diz provar: a discriminação só existe se a assinatura RODAR.
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '01-03');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        $this->obrigacao($caso, '67612', '12/2025', 44545, $vencimento, $snapshot, 5000, 891, 0, 10275);

        $comum = [
            'unidade' => '01-03', 'nn' => '67612', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 2/6',
        ];

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 30.00, multa: 8.00, honorarios: 100.00),
            $this->linhaDeDado(...$comum, classe: '1.4 - Juros', valor: 45.45, juros: 5.00, multa: 0.91, honorarios: 8.54),
        ]);

        self::assertSame(0, $r->comDuplaContagem, 'maior que a coluna, sem a assinatura, NÃO é dupla contagem');
        self::assertSame(1, $r->divergentes, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame(0, $r->duplicadoEmCentavos);
        self::assertSame('divergente', $r->veredito());
        self::assertSame(0, $r->injulgaveis, 'a assinatura precisa ter RODADO para o teste valer');
    }

    #[TestDox('🔴 INV-CE6 — a assinatura lê o lote que ESCREVEU a dívida, não o último carregado')]
    public function testAssinaturaLeOLoteQueEscreveuENaoOUltimo(): void
    {
        // O defeito que este teste fecha, medido em produção em 13/08/2026: o banco foi escrito pela
        // importação de 11/08 e o espelho já tinha o lote de 12/08 carregado (mas NÃO importado,
        // porque a importação está bloqueada). A régua lia o último e comparava o gravado contra
        // colunas que não o escreveram.
        //
        // Por que passava despercebido: a coluna J (multa) é 2% fixo e não anda entre emissões, então
        // a assinatura da multa casava nos dois lotes. As colunas I (juros) e L (honorário) andam todo
        // dia — contra o lote errado a IGUALDADE falha em silêncio e a régua SUBCONTA. Contra o lote
        // certo eram 25 dívidas / R$ 3.185,94; contra o último, 21 / R$ 1.167,58.
        //
        // Aqui: juros gravado 80,45 = Σ I do lote de 11/08 (35,00) + H da linha 1.4 (45,45).
        // No lote de 12/08 a coluna I subiu para 35,23 → a assinatura daria 80,68 e NÃO casaria.
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '01-06');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $this->obrigacao($caso, '67613', '12/2025', 44545, $vencimento, new \DateTimeImmutable('2026-08-11'), 8045, 891, 0, 10854);

        $comum = [
            'unidade' => '01-06', 'nn' => '67613', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        // O lote que ESCREVEU a dívida.
        $this->gravarLote($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 30.00, multa: 8.00, honorarios: 100.00),
            $this->linhaDeDado(...$comum, classe: '1.4 - Juros', valor: 45.45, juros: 5.00, multa: 0.91, honorarios: 8.54),
        ], dadosAte: '11/08/2026');

        // O lote MAIS RECENTE — um dia de juros a mais nas colunas. É o que a régua lia antes.
        $ultimo = $this->gravarLote($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 30.20, multa: 8.00, honorarios: 100.00),
            $this->linhaDeDado(...$comum, classe: '1.4 - Juros', valor: 45.45, juros: 5.03, multa: 0.91, honorarios: 8.54),
        ], dadosAte: self::EMISSAO_DO_LOTE);

        $r = $this->conferencia->conferir($ultimo);

        self::assertSame(1, $r->comDuplaContagem, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame(4545, $r->duplicadoEmCentavos, 'o H da linha 1.4 do lote de 11/08, ao centavo');
        self::assertSame(['juros' => 4545], $r->duplicadoPorCampo);
        self::assertSame(0, $r->injulgaveis);
    }

    #[TestDox('INV-CE6 — dívida cujo snapshot não tem lote é INJULGÁVEL, não "limpa"')]
    public function testDividaSemLoteNaDataDoSnapshotEhInjulgavel(): void
    {
        // Medido em produção: 99 de 3.672 dívidas do universo da régua (2,7%) têm snapshot em data sem
        // lote carregado — recálculo do cron, edição manual, importação antiga. A régua não tem contra
        // o que ler a assinatura delas.
        //
        // O balde existe porque silenciá-las faria "0 dupla contagem" significar duas coisas
        // incompatíveis: "conferi e está limpo" e "não tinha contra o que conferir". Esta dívida tem a
        // assinatura EXATA da dupla contagem — e mesmo assim não pode ser acusada, porque as colunas
        // que a acusariam são de outra emissão.
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '01-07');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $this->obrigacao($caso, '67614', '12/2025', 44545, $vencimento, new \DateTimeImmutable('2026-07-30'), 8045, 891, 0, 10854);

        $comum = [
            'unidade' => '01-07', 'nn' => '67614', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 30.00, multa: 8.00, honorarios: 100.00),
            $this->linhaDeDado(...$comum, classe: '1.4 - Juros', valor: 45.45, juros: 5.00, multa: 0.91, honorarios: 8.54),
        ]);

        self::assertSame(1, $r->injulgaveis, 'o snapshot é de 30/07 e o único lote é de 12/08');
        self::assertSame(0, $r->conferidos, 'não foi conferida');
        self::assertSame(0, $r->comDuplaContagem, 'e sobretudo NÃO foi acusada com colunas de outra emissão');
        self::assertSame(0, $r->duplicadoEmCentavos);
    }

    #[TestDox('INV-CE3 — a config sai da cascata: honorário zerado por override não vira divergência')]
    public function testUsaACascataENaoOPresetDaCarteira(): void
    {
        // 1.714 obrigações da TOP LIFE I têm `taxaHonorariosBp = 0` gravado pelo importador de
        // acordos. Conferir contra o preset da carteira (20%) as acusaria todas.
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '01-04');
        $vencimento = new \DateTimeImmutable('2025-12-15');
        $snapshot = $this->snapshotNaEmissaoDoLote();

        $obrigacao = $this->obrigacao($caso, '74609', '12/2025', 17000, $vencimento, $snapshot, 1360, 340, 0, 0);
        $obrigacao->setTaxaHonorariosBp(0);
        $this->em->flush();

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(
                unidade: '01-04', nn: '74609', competencia: '12/2025', vencimento: '15/12/2025',
                valor: 170.00, juros: 13.60, multa: 3.40, correcao: 0.0, honorarios: 37.40,
            ),
        ]);

        self::assertSame(1, $r->coerentes, sprintf('piores: %s', json_encode($r->piores)));
        self::assertSame('coerente', $r->veredito());
    }

    #[TestDox('INV-CE1 — dívida que o relatório não cobra fica fora desta conta, em balde próprio')]
    public function testDividaSemParNoRelatorioNaoEhConferida(): void
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, '01-05');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $this->obrigacao($caso, '99999', '12/2025', 17000, $vencimento, $this->snapshotNaEmissaoDoLote(), 1360, 340, 0, 3740);

        $r = $this->conferir($carteira, [
            $this->linhaDeDado(
                unidade: '01-05', nn: '11111', competencia: '12/2025', vencimento: '15/12/2025',
            ),
        ]);

        self::assertSame(0, $r->conferidos);
        self::assertSame(1, $r->semParNoRelatorio, 'é matéria da conferência, não desta régua');
        self::assertSame([], $r->piores);
    }

    /**
     * Um boleto de duas linhas — taxa + linha `1.5` — que é a forma em que o defeito apareceu em
     * produção. Com `$semAcordo` vira boleto COMUM, para exercitar a guarda do INV-CE5.
     *
     * @return list<list<mixed>>
     */
    private function boletoDeAcordo(
        string $unidade,
        string $nn,
        float $multaDaLinha15,
        bool $semAcordo = false,
    ): array {
        $comum = [
            'unidade' => $unidade, 'nn' => $nn, 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0,
            'acordo' => $semAcordo ? '-' : 'Acordo 426 - Parc. 1/6',
        ];

        return [
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 0.0),
            $this->linhaDeDado(...$comum, classe: '1.5 - Multas', valor: 45.45, juros: 3.60, multa: $multaDaLinha15, honorarios: 0.0),
        ];
    }

    /**
     * @param list<list<mixed>> $linhas
     */
    private function conferir(Carteira $carteira, array $linhas): \App\Cobranca\DTO\ResultadoConferenciaEncargos
    {
        return $this->conferencia->conferir($this->gravarLote($carteira, $linhas, self::EMISSAO_DO_LOTE));
    }

    /**
     * Carrega UM lote no espelho e devolve a entidade — os testes do INV-CE6 precisam de mais de um,
     * com datas de emissão diferentes, para distinguir "o lote que escreveu" de "o último carregado".
     *
     * @param list<list<mixed>> $linhas
     */
    private function gravarLote(Carteira $carteira, array $linhas, string $dadosAte): RelatorioImportado
    {
        // A emissão acompanha o `dadosAte`: é o que o rodapé do relatório real traz, e é o que torna
        // os dois arquivos distintos para a dedup por hash.
        $arquivo = $this->montarPlanilha($linhas, dadosAte: $dadosAte, emissao: $dadosAte . ' 09:42');
        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        $lote = $this->em->getRepository(RelatorioImportado::class)->find($saida->relatorioId);
        self::assertNotNull($lote);

        return $lote;
    }

    private function carteiraTopLife(): Carteira
    {
        return CarteiraFactory::createOne([
            'tenant' => TenantFactory::createOne(),
            'taxaJurosMensalBp' => 100,
            'regimeJuros' => RegimeJuros::Simples,
            'taxaMultaBp' => 200,
            'baseMulta' => BaseEncargo::Principal,
            'taxaCorrecaoBp' => 0,
            'baseCorrecao' => BaseEncargo::Principal,
            'baseHonorarios' => BaseEncargo::Composta,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
            'carenciaHonorariosDias' => 30,
            'toleranciaJurosMultaDias' => 0,
        ])->_real();
    }

    private function caso(Carteira $carteira, string $identificacao): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => $identificacao,
        ]);

        return CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();
    }

    private function obrigacao(
        CasoCobranca $caso,
        string $referencia,
        string $competencia,
        int $valorOriginal,
        \DateTimeImmutable $vencimento,
        \DateTimeImmutable $snapshot,
        int $juros,
        int $multa,
        int $correcao,
        int $honorarios,
    ): Obrigacao {
        // O encargo entra pelo MESMO caminho da produção (`definirEncargos`), que é o único que
        // carimba a data do snapshot — não há setter avulso para `encargosAtualizadosEm`, e é assim
        // de propósito: o valor e a data em que ele foi calculado andam juntos.
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => $referencia,
            'competencia' => $competencia,
            'valorOriginal' => $valorOriginal,
            'vencimentoOriginal' => $vencimento,
        ])->_real();

        $obrigacao->definirEncargos($juros, $multa, $correcao, $honorarios, $snapshot);
        $this->em->flush();

        return $obrigacao;
    }
}
