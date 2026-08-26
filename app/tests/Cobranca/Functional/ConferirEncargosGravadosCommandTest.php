<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\ConferirEncargosGravadosCommand;
use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Cobranca\Support\MontaPlanilhasDosQuatroRelatorios;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * O que a régua do encargo gravado PROMETE ao mundo de fora — a tela e o **código de saída**.
 *
 * Existe por um motivo estreito, e a revisão o encontrou: o balde `injulgáveis` (INV-CE6) só serve
 * para alguma coisa se chegar a quem lê. Com o veredito e o exit code cegos para ele, o comando
 * imprimia a caixa verde *"Todo encargo gravado é um número que a nossa fórmula produz"* e saía com
 * `0` numa carteira com **2 dívidas conferidas e 528 jamais examinadas**. Nenhum teste quebrava.
 *
 * O exit code é o que um cron enxerga: avisar o humano na tela e dizer "tudo certo" para a máquina é
 * ter metade do aviso.
 */
final class ConferirEncargosGravadosCommandTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;
    use MontaPlanilhasDosQuatroRelatorios;

    private const EMISSAO_DO_LOTE = '12/08/2026';

    private EntityManagerInterface $em;
    private CommandTester $comando;
    private GravarEspelhoRelatorioUseCase $gravar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->gravar = static::getContainer()->get(GravarEspelhoRelatorioUseCase::class);

        $aplicacao = new Application(self::$kernel);
        $this->comando = new CommandTester($aplicacao->find('app:cobranca:espelho:encargos'));
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        $this->limparPlanilhasDosQuatro();
        parent::tearDown();
    }

    #[TestDox('🔴 A2 — dívida injulgável NÃO sai com código 0; sai com COBERTURA_INCOMPLETA')]
    public function testCoberturaIncompletaNaoSaiComSucesso(): void
    {
        // A dívida vence DEPOIS do próprio snapshot, então a fórmula em 30/07 produz zeros — que é o
        // que está gravado nela. Coerente por construção, e ainda assim injulgável: é o único jeito de
        // provar que o código de saída reage à COBERTURA e não à divergência, que sai com o mesmo 2.
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-07-30'),
            vencimento: new \DateTimeImmutable('2026-08-01'),
            encargos: [0, 0, 0, 0],
        );

        $codigo = $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);

        self::assertSame(
            ConferirEncargosGravadosCommand::COBERTURA_INCOMPLETA,
            $codigo,
            'sair 0 aqui diria à máquina "está limpo" logo depois de avisar o humano do contrário',
        );

        $saida = $this->comando->getDisplay();

        self::assertStringContainsString('INJULGÁVEIS', $saida);
        self::assertStringNotContainsString(
            'Todo encargo gravado é um número que a nossa fórmula produz',
            $saida,
            'a caixa verde não pode aparecer com dívida por conferir',
        );
        // Prende o veredito: sem isto o teste passaria também se a dívida caísse em "divergente",
        // que sai com o mesmo código — e aí não estaria provando a cobertura incompleta.
        self::assertStringContainsString(
            'Nada divergente no que deu para ler — e nada a concluir sobre o resto',
            $this->semQuebras($saida),
        );
        // N4 — a cobertura sai em CAIXA, não em texto solto que o veredito abafa.
        self::assertStringContainsString(
            'COBERTURA INCOMPLETA: a assinatura foi lida em 0 de 1 dívida(s) (0.00%)',
            $this->semQuebras($saida),
        );
        self::assertStringContainsString('assinatura avaliada: 0', $saida);
    }

    #[TestDox('Com os 3 relatórios carregados a régua ainda cobre 1 de 3 — sai 0, mas NÃO sai verde')]
    public function testCoberturaTotalSaiComSucesso(): void
    {
        // Os números pinados de `CalculadoraEncargosTest`: P=170,00 · 240 dias · 20%.
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-08-12'),
            vencimento: new \DateTimeImmutable('2025-12-15'),
            encargos: [1360, 340, 0, 3740],
        );

        // 🔑 Os TRÊS relatórios com dinheiro carregados — e ainda assim a caixa NÃO sai verde.
        //
        // Isto não é defeito, é a resposta honesta: carregado ≠ conferido. Esta régua lê **só a
        // inadimplência**, então mesmo com acordos e receitas no espelho ela cobre 1 de 3, e afirmar
        // "todo encargo gravado é um número que a fórmula produz" como conclusão sobre o todo seria
        // exatamente o número parcial com cara de total que esta frente existe para acabar.
        //
        // ⚠️ Enquanto a fatia 0c não fizer os instrumentos LEREM os outros dois, a caixa verde é
        // inalcançável aqui de propósito. Quem for implementá-la vai encontrar este teste — e é o
        // teste que dirá que a meta foi atingida quando ele passar a exigir `[OK]`.
        $this->carregarAcordosEReceitas($carteira);

        $codigo = $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);

        // O código de saída responde a OUTRA pergunta — divergência, não cobertura — e continua 0.
        self::assertSame(Command::SUCCESS, $codigo);

        $saida = $this->comando->getDisplay();

        self::assertStringContainsString('Todo encargo gravado é um número que a nossa fórmula produz', $saida);
        self::assertStringNotContainsString('INJULGÁVEIS', $saida, 'aviso que aparece sempre deixa de ser aviso');

        $semQuebras = $this->semQuebras($saida);

        self::assertStringNotContainsString('[OK]', $semQuebras, 'ler 1 de 3 não autoriza caixa verde');
        self::assertStringContainsString('cobrem 1 de 3', $semQuebras);
        // E a distinção que o bloco precisa fazer: os outros dois ESTÃO no espelho, e mesmo assim não
        // entram no número. "Ausente" e "não conferido" são coisas diferentes.
        self::assertStringContainsString('carregado, mas NÃO conferido por este comando', $semQuebras);
        self::assertStringNotContainsString('AUSENTE do espelho', $semQuebras);
    }

    /**
     * 🔴 Achado 1 da revisão, no lugar onde ele se manifesta — **o mesmo defeito pela terceira vez
     * nesta frente**: o instrumento calcula que cobriu 1 de 3 relatórios, imprime isso, e três linhas
     * abaixo estampa a caixa verde.
     *
     * Este é o cenário de 100% das execuções reais de hoje: os três instrumentos leem só a
     * inadimplência.
     *
     * **Reintrodução provada:** trocando `$veredito->sucesso($io, ...)` de volta por
     * `$io->success(...)` em `ConferirEncargosGravadosCommand`, este teste fica vermelho.
     */
    #[TestDox('🔴 Cobertura de 1 de 3 relatórios NÃO imprime caixa verde, mesmo sem nada divergente')]
    public function testCoberturaParcialNaoImprimeCaixaVerde(): void
    {
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-08-12'),
            vencimento: new \DateTimeImmutable('2025-12-15'),
            encargos: [1360, 340, 0, 3740],
        );

        $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);

        $saida = $this->semQuebras($this->comando->getDisplay());

        // O bloco de cobertura SAI, e diz o que falta — sem ele o número parece total.
        self::assertStringContainsString('Cobertura desta medição', $saida);
        self::assertStringContainsString('acordos', $saida);
        self::assertStringContainsString('AUSENTE do espelho', $saida);
        self::assertStringContainsString('cobrem 1 de 3', $saida);

        // E o veredito NÃO sai verde.
        self::assertStringNotContainsString('[OK]', $saida, 'caixa verde sobre cobertura de 1 de 3 é o defeito');
        self::assertStringContainsString(
            'não está sendo afirmado aqui',
            $saida,
            'a frase do veredito sai, mas com o recorte colado nela',
        );
    }

    /**
     * Os acordos vêm em DOIS arquivos por carteira. Com só um carregado, a cobertura não pode
     * exibir a mesma linha de quando os dois estão lá (achado 5 da revisão).
     */
    #[TestDox('Acordos carregados pela metade aparecem como 1 arquivo, não como completos')]
    public function testAcordosPelaMetadeNaoParecemCompletos(): void
    {
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-08-12'),
            vencimento: new \DateTimeImmutable('2025-12-15'),
            encargos: [1360, 340, 0, 3740],
        );

        $this->gravar->executar(new GravarEspelhoRelatorioInput(
            $carteira,
            $this->montarPlanilhaDeAcordos(
                [['numero' => 1, 'valorFinal' => '100,00', 'parcelas' => [['1.1 - Taxa de condomínio', '100,00']]]],
                situacao: 'Em andamento',
            ),
            TipoRelatorioContabil::Acordos,
        ));

        $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);
        $comUm = $this->semQuebras($this->comando->getDisplay());

        $this->gravar->executar(new GravarEspelhoRelatorioInput(
            $carteira,
            $this->montarPlanilhaDeAcordos(
                [['numero' => 2, 'valorFinal' => '200,00', 'parcelas' => [['1.1 - Taxa de condomínio', '200,00']]]],
                situacao: 'Liquidado',
            ),
            TipoRelatorioContabil::Acordos,
        ));

        $this->comando->execute(['--tenant-id' => (string) $carteira->getTenant()?->getId()]);
        $comDois = $this->semQuebras($this->comando->getDisplay());

        self::assertStringNotContainsString('2 arquivos', $comUm, 'com um só carregado não pode dizer dois');
        self::assertStringContainsString('2 arquivos', $comDois, 'com os dois, a linha muda — é o que distingue metade de inteiro');
    }

    /** Carrega acordos (as duas situações) e receitas, para a cobertura ficar completa. */
    private function carregarAcordosEReceitas(Carteira $carteira): void
    {
        foreach (['Em andamento', 'Liquidado'] as $i => $situacao) {
            $this->gravar->executar(new GravarEspelhoRelatorioInput(
                $carteira,
                $this->montarPlanilhaDeAcordos(
                    [['numero' => $i + 1, 'valorFinal' => '100,00', 'parcelas' => [['1.1 - Taxa de condomínio', '100,00']]]],
                    situacao: $situacao,
                ),
                TipoRelatorioContabil::Acordos,
            ));
        }

        $this->gravar->executar(new GravarEspelhoRelatorioInput(
            $carteira,
            $this->montarPlanilhaDeReceitas([['1.1 - Taxa de condomínio', '100,00', '100,00']]),
            TipoRelatorioContabil::Receitas,
        ));
    }

    #[TestDox('🔴 N6 — dupla contagem sai com FAILURE, e a lista da reconciliação sai completa')]
    public function testDuplaContagemSaiComFailureEListaNominal(): void
    {
        $carteira = $this->carteiraComDuplaContagem();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--duplicadas' => true,
        ]);

        self::assertSame(ConferirEncargosGravadosCommand::DUPLA_CONTAGEM, $codigo, 'o código que existe para gritar dinheiro duplicado');

        $saida = $this->semQuebras($this->comando->getDisplay());

        self::assertStringContainsString('DINHEIRO CONTADO DUAS VEZES', $saida);
        self::assertStringContainsString('Lista da reconciliação', $saida);
        // A linha traz o LOTE usado — é o que permite achar e desfazer um erro depois (§17.8).
        self::assertStringContainsString('(12/08/2026)', $saida);
        // 🔴 UM total só, e o rótulo nomeia os QUATRO encargos.
        //
        // Aqui o teste exigia dois totais separados — "sai do SALDO do devedor (juros + multa +
        // correção)" e "sai FORA do saldo (honorário...)" —, porque o honorário ficava fora do
        // `valorExigivel()`. A spec `cobranca-honorario-no-total.md` REVOGOU isso. Esta é a lista que o
        // dono lê para autorizar a escrita: o rótulo velho mostraria o número certo com o nome errado.
        self::assertStringContainsString('sai do SALDO do devedor (juros + multa + correção + honorário): R$ 45,45', $saida);

        // ⚠️ Este cenário NÃO tem honorário duplicado (as duas linhas vêm com honorário 0,00), então
        // ele não exercita o ramo que fala de honorário — quem faz isso é o teste logo abaixo. Um
        // `assertStringNotContainsString` aqui pareceria proteção e não seria: passaria mesmo com a
        // frase revogada restaurada, porque a frase nunca é impressa neste cenário. Achado da 10ª
        // revisão, e a lição é a mesma de sempre — asserção que não pode falhar não é asserção.
    }

    /**
     * 🔴 O ramo do HONORÁRIO na tela que o dono lê para autorizar a escrita.
     *
     * Existe porque o cenário acima tem honorário zero e nunca chega aqui. É neste ramo que ficava a
     * frase *"ATENÇÃO: o honorário NÃO entra no saldo exigível"*, impressa em vermelho, no ponto exato
     * da decisão — a proposição que a spec `cobranca-honorario-no-total.md` revogou.
     *
     * Assinatura em DOIS campos na mesma dívida, como no dado real (NNs 61600/61821, TOP LIFE II):
     *
     *   Σ H = 400,00 + 20,00 + 45,45 = 465,45
     *   multa gravada = (8,00 + 0,40 + 0,91) + 20,00 = 29,31  ← os 20,00 duplicados
     *   honorário gravado = (80,00 + 4,00) + 45,45 = 129,45   ← os 45,45 duplicados
     */
    #[TestDox('🔴 dupla contagem no HONORÁRIO: o rótulo diz que ele ENTRA no saldo')]
    public function testDuplaContagemNoHonorarioAnunciaQueEleEntraNoSaldo(): void
    {
        $carteira = $this->carteiraComDuplaContagemNoHonorario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--duplicadas' => true,
        ]);

        self::assertSame(ConferirEncargosGravadosCommand::DUPLA_CONTAGEM, $codigo);

        $saida = $this->semQuebras($this->comando->getDisplay());

        // O ramo do honorário É exercitado aqui — sem esta asserção o teste não valeria mais que o de cima.
        self::assertStringContainsString('R$ 45,45 são de honorário — que entra no saldo exigível', $saida);

        // Multa 20,00 + honorário 45,45 = 65,45, tudo dentro do saldo. Sob a régua revogada o total
        // teria vindo partido, com 45,45 anunciados como "fora do saldo".
        self::assertStringContainsString('sai do SALDO do devedor (juros + multa + correção + honorário): R$ 65,45', $saida);

        // 🔑 AGORA estas guardas valem: o ramo que imprimia as frases revogadas foi executado neste
        // cenário, então restaurar qualquer uma delas quebra o teste.
        //
        // ⚠️ Havia uma terceira, `'fora do saldo'` em MINÚSCULAS, e ela não podia falhar: as duas
        // frases históricas eram em caixa alta. Saiu — asserção que não pode falhar não é asserção, e
        // deixá-la aqui daria a impressão de uma cobertura que não existe.
        self::assertStringNotContainsString('NÃO entra no saldo', $saida);
        self::assertStringNotContainsString('FORA do saldo', $saida);
    }

    #[TestDox('N2 — tenant inexistente tem código próprio, fora dos códigos baixos')]
    public function testTenantInexistenteTemCodigoProprio(): void
    {
        $codigo = $this->comando->execute(['--tenant-id' => '999999']);

        self::assertSame(ConferirEncargosGravadosCommand::ERRO_DE_INVOCACAO, $codigo);
        // `1` é exceção não capturada e `2` é `Command::INVALID`, usado por 10 comandos irmãos no mesmo
        // diretório. Colidir com qualquer um dos dois faria um wrapper ler outra coisa.
        self::assertNotSame(Command::FAILURE, $codigo);
        self::assertNotSame(Command::INVALID, $codigo);
    }

    #[TestDox('🔴 achado 3 — sem carteira conferida NÃO sai 0; "não houve o que conferir" != "está limpo"')]
    public function testNenhumaCarteiraConferidaNaoSaiComSucesso(): void
    {
        // Medido pela revisão: `--carteira-id=9999` saía 0, ou seja, o comando dizia "limpo e completo"
        // sobre carteira nenhuma. É a mesma falha-aberto que a revisão anterior condenou no
        // repositório, sobrevivendo no comando.
        $carteira = $this->carteiraComLote(
            snapshot: new \DateTimeImmutable('2026-08-12'),
            vencimento: new \DateTimeImmutable('2025-12-15'),
            encargos: [1360, 340, 0, 3740],
        );

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $carteira->getTenant()?->getId(),
            '--carteira-id' => '999999',
        ]);

        self::assertSame(ConferirEncargosGravadosCommand::NADA_CONFERIDO, $codigo);
        self::assertNotSame(Command::SUCCESS, $codigo, 'sair 0 aqui é dizer "pode seguir" sobre coisa nenhuma');
        self::assertStringContainsString(
            'Nenhuma carteira foi conferida',
            $this->semQuebras($this->comando->getDisplay()),
        );
    }

    /**
     * Como a de cima, mas com a assinatura em DOIS campos — multa E honorário. É o cenário que
     * exercita o ramo do honorário no relatório; ver o teste que a usa.
     */
    private function carteiraComDuplaContagemNoHonorario(): Carteira
    {
        $carteira = $this->carteiraVazia();
        $caso = $this->caso($carteira, '02-04');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => '74793',
            'competencia' => '12/2025',
            'valorOriginal' => 46545,
            'vencimentoOriginal' => $vencimento,
        ])->_real();

        $obrigacao->definirEncargos(3720, 2931, 0, 12945, new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();

        $comum = [
            'unidade' => '02-04', 'nn' => '74793', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 80.00),
            $this->linhaDeDado(...$comum, classe: '1.5 - Multas', valor: 20.00, juros: 1.60, multa: 0.40, honorarios: 4.00),
            $this->linhaDeDado(...$comum, classe: '1.15 - Honorário', valor: 45.45, juros: 3.60, multa: 0.91, honorarios: 10.00),
        ], dadosAte: self::EMISSAO_DO_LOTE, emissao: self::EMISSAO_DO_LOTE . ' 09:42');

        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        return $carteira;
    }

    /**
     * Uma carteira com UMA parcela de acordo que tem a assinatura exata da dupla contagem na MULTA —
     * a forma em que o defeito apareceu em produção.
     *
     *   1.1 → valor 400,00 · multa 8,00 · 1.5 → valor 45,45 · multa 0,91
     *   Σ J = 8,91 · multa gravada = 8,91 + 45,45 = 54,36  ← os 45,45 duplicados
     */
    private function carteiraComDuplaContagem(): Carteira
    {
        $carteira = $this->carteiraVazia();
        $caso = $this->caso($carteira, '02-01');
        $vencimento = new \DateTimeImmutable('2025-12-15');

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => '74790',
            'competencia' => '12/2025',
            'valorOriginal' => 44545,
            'vencimentoOriginal' => $vencimento,
        ])->_real();

        $obrigacao->definirEncargos(3560, 5436, 0, 0, new \DateTimeImmutable('2026-08-12'));
        $this->em->flush();

        $comum = [
            'unidade' => '02-01', 'nn' => '74790', 'competencia' => '12/2025',
            'vencimento' => '15/12/2025', 'correcao' => 0.0, 'acordo' => 'Acordo 426 - Parc. 1/6',
        ];

        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(...$comum, classe: '1.1 - Taxa de condomínio', valor: 400.00, juros: 32.00, multa: 8.00, honorarios: 0.0),
            $this->linhaDeDado(...$comum, classe: '1.5 - Multas', valor: 45.45, juros: 3.60, multa: 0.91, honorarios: 0.0),
        ], dadosAte: self::EMISSAO_DO_LOTE, emissao: self::EMISSAO_DO_LOTE . ' 09:42');

        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        return $carteira;
    }

    /**
     * O `SymfonyStyle` quebra as caixas de aviso em várias linhas, com padding — asserir a frase
     * literal falharia por formatação, não por comportamento.
     */
    private function semQuebras(string $saida): string
    {
        return (string) preg_replace('/\s+/u', ' ', $saida);
    }

    /** A carteira TOPLIFE padrão dos testes do espelho, sem objeto nem obrigação. */
    private function carteiraVazia(): Carteira
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

        /** @var CasoCobranca $caso */
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();

        return $caso;
    }

    /**
     * Uma carteira com UM lote emitido em 12/08 e UMA obrigação COERENTE com a fórmula, carimbada na
     * data que o teste pedir. Os dois casos são coerentes de propósito: o que muda entre eles é só
     * haver ou não lote na data do snapshot, que é o fator sob teste.
     *
     * @param array{int, int, int, int} $encargos juros, multa, correção, honorários
     */
    private function carteiraComLote(
        \DateTimeImmutable $snapshot,
        \DateTimeImmutable $vencimento,
        array $encargos,
    ): Carteira {
        $carteira = $this->carteiraVazia();
        $caso = $this->caso($carteira, '01-01');

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => '74608',
            'competencia' => '12/2025',
            'valorOriginal' => 17000,
            'vencimentoOriginal' => $vencimento,
        ])->_real();

        [$juros, $multa, $correcao, $honorarios] = $encargos;
        $obrigacao->definirEncargos($juros, $multa, $correcao, $honorarios, $snapshot);
        $this->em->flush();

        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(
                unidade: '01-01', nn: '74608', classe: '1.1 - Taxa de condomínio', competencia: '12/2025',
                vencimento: '15/12/2025', valor: 170.00, juros: 13.60, multa: 3.40, correcao: 0.0,
                honorarios: 37.40,
            ),
        ], dadosAte: self::EMISSAO_DO_LOTE, emissao: self::EMISSAO_DO_LOTE . ' 09:42');

        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        return $carteira;
    }
}
