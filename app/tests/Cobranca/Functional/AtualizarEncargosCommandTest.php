<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\AtualizarEncargosCommand;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Contrato do cron de crescimento dos encargos (spec §6-B, fase F3) contra o BANCO REAL.
 *
 * Este comando é o ÚNICO lugar do sistema onde o dinheiro cresce sozinho — `valorExigivel()` é puro
 * e sem `$hoje` (INV-E3). Por isso os números aqui são LITERAIS conferidos contra as provas reais da
 * planilha TOPLIFE (Apêndice A da spec / `CalculadoraEncargosTest`), e não recalculados pela mesma
 * fórmula que está sob teste: um teste que reexecuta a fórmula só prova que ela é igual a si mesma.
 *
 * Cenário-base repetido nos testes (todos com `--hoje` injetado, sem depender do relógio):
 *   P = R$ 170,00 · vencido em 10/01/2026 · referência 07/09/2026 = 240 dias de atraso
 *   juros 1% a.m. simples · multa 2% do principal · honorários 20% sobre a base composta, carência 30d
 *   ⇒ juros R$ 13,60 · multa R$ 3,40 · correção R$ 0,00 · honorários R$ 37,40 · exigível R$ 187,00
 */
#[CoversClass(AtualizarEncargosCommand::class)]
final class AtualizarEncargosCommandTest extends KernelTestCase
{
    use Factories;

    /** Principal do cenário-base, em centavos (R$ 170,00). */
    private const PRINCIPAL = 17000;

    private const VENCIMENTO = '2026-01-10';

    /** 240 dias corridos depois do vencimento — conferido em `python`/`date`, não pela fórmula. */
    private const HOJE = '2026-09-07';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    #[TestDox('Obrigação não congelada CRESCE para a data de referência injetada')]
    public function obrigacaoNaoCongeladaCresceParaADataDeReferencia(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);
        $obrigacaoId = $this->idDe($this->obrigacao($tenant, $caso));

        $tester = $this->rodar(['--tenant' => (string) $tenant->getId(), '--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $obrigacao = $this->recarregar($obrigacaoId);

        // Valores LITERAIS da prova real (P=170,00 · 240 dias · 20%): Jur 13,60 · Mul 3,40 · Hon 37,40.
        self::assertSame(1360, $obrigacao->getJuros());
        self::assertSame(340, $obrigacao->getMulta(), 'a multa é fixa sobre o principal e não cresce com o atraso');
        self::assertSame(0, $obrigacao->getCorrecao());
        self::assertSame(3740, $obrigacao->getHonorarios());

        // INV-E1/INV-E2: o exigível é original+juros+multa+correção; honorários ficam FORA.
        self::assertSame(18700, $obrigacao->valorExigivel());
        self::assertSame(22440, $obrigacao->totalComHonorarios());

        self::assertSame(
            self::HOJE,
            $obrigacao->getEncargosAtualizadosEm()?->format('Y-m-d'),
            'a data de referência materializada tem de ser a injetada, não o relógio da máquina',
        );
        self::assertFalse($obrigacao->encargosCongelados(), 'o cron NUNCA congela nada');

        // O relatório tem de contar a atualização e o delta do exigível (1360+340 = 1700 centavos).
        self::assertSame(1, $this->metrica($tester->getDisplay(), 'Atualizadas'));
        self::assertStringContainsString('R$ 17,00', $tester->getDisplay());
    }

    #[Test]
    #[TestDox('INV-E4: obrigação CONGELADA não é tocada pelo cron')]
    public function obrigacaoCongeladaNaoEhTocada(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);

        // Congelada com valores "da contabilidade" — deliberadamente DIFERENTES do que o cron
        // calcularia para 240 dias. Se o cron a tocasse, virariam 1360/340/0/3740.
        $obrigacao = $this->obrigacao($tenant, $caso);
        $obrigacao->definirEncargos(111, 222, 333, 444, new \DateTimeImmutable('2026-02-01'));
        $obrigacao->congelarEncargos(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $this->em->flush();
        $obrigacaoId = $this->idDe($obrigacao);

        $tester = $this->rodar(['--tenant' => (string) $tenant->getId(), '--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $recarregada = $this->recarregar($obrigacaoId);

        self::assertSame(111, $recarregada->getJuros());
        self::assertSame(222, $recarregada->getMulta());
        self::assertSame(333, $recarregada->getCorrecao());
        self::assertSame(444, $recarregada->getHonorarios());
        self::assertSame(
            '2026-02-01',
            $recarregada->getEncargosAtualizadosEm()?->format('Y-m-d'),
            'nem a data de atualização pode mudar numa obrigação congelada',
        );
        self::assertTrue($recarregada->encargosCongelados());

        self::assertSame(0, $this->metrica($tester->getDisplay(), 'Atualizadas'));
    }

    #[Test]
    #[TestDox('--dry-run calcula e relata, mas não persiste nada')]
    public function dryRunNaoPersisteNada(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);
        $obrigacaoId = $this->idDe($this->obrigacao($tenant, $caso));

        $tester = $this->rodar([
            '--tenant' => (string) $tenant->getId(),
            '--hoje' => self::HOJE,
            '--dry-run' => true,
        ]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        // O relatório mostra o que MUDARIA...
        self::assertSame(1, $this->metrica($tester->getDisplay(), 'Atualizadas'));
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());

        // ...mas o banco continua zerado (releitura de verdade: identity map descartado antes).
        $obrigacao = $this->recarregar($obrigacaoId);
        self::assertSame(0, $obrigacao->getJuros());
        self::assertSame(0, $obrigacao->getMulta());
        self::assertSame(0, $obrigacao->getCorrecao());
        self::assertSame(0, $obrigacao->getHonorarios());
        self::assertSame(self::PRINCIPAL, $obrigacao->valorExigivel());
        self::assertNull($obrigacao->getEncargosAtualizadosEm());
    }

    #[Test]
    #[TestDox('Multi-tenant: obrigação de OUTRO escritório não é tocada')]
    public function naoTocaObrigacaoDeOutroEscritorio(): void
    {
        // O TenantFilter fica DESLIGADO no CLI: se o repositório não filtrasse por tenant
        // explicitamente, este cron recalcularia dinheiro do escritório vizinho.
        $tenantA = $this->tenant();
        $obrigacaoA = $this->idDe($this->obrigacao($tenantA, $this->casoTopLife($tenantA)));

        $tenantB = $this->tenant();
        $obrigacaoB = $this->idDe($this->obrigacao($tenantB, $this->casoTopLife($tenantB)));

        $tester = $this->rodar(['--tenant' => (string) $tenantA->getId(), '--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        self::assertSame(1360, $this->recarregar($obrigacaoA)->getJuros(), 'o escritório alvo é atualizado');

        $doVizinho = $this->recarregar($obrigacaoB);
        self::assertSame(0, $doVizinho->getJuros(), 'obrigação de outro escritório não pode ser tocada');
        self::assertSame(0, $doVizinho->getMulta());
        self::assertSame(0, $doVizinho->getHonorarios());
        self::assertNull($doVizinho->getEncargosAtualizadosEm());

        self::assertSame(1, $this->metrica($tester->getDisplay(), 'Obrigações examinadas'));
    }

    #[Test]
    #[TestDox('Caso ENCERRADO é pulado; caso JUDICIALIZADO continua crescendo')]
    public function casoEncerradoEhPuladoMasJudicializadoNao(): void
    {
        $tenant = $this->tenant();

        $encerrado = $this->idDe($this->obrigacao(
            $tenant,
            $this->casoTopLife($tenant, StatusCaso::Encerrado),
        ));
        $judicializado = $this->idDe($this->obrigacao(
            $tenant,
            $this->casoTopLife($tenant, StatusCaso::Judicializado),
        ));

        $tester = $this->rodar(['--tenant' => (string) $tenant->getId(), '--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        self::assertSame(
            0,
            $this->recarregar($encerrado)->getJuros(),
            'caso encerrado é fim de ciclo (SPEC §17): não cresce mais',
        );
        self::assertSame(
            1360,
            $this->recarregar($judicializado)->getJuros(),
            'judicializado é fase VIVA — a dívida continua correndo',
        );

        // O encerrado nem chega a ser examinado: some já na query.
        self::assertSame(1, $this->metrica($tester->getDisplay(), 'Obrigações examinadas'));
        self::assertSame(1, $this->metrica($tester->getDisplay(), 'Atualizadas'));
    }

    #[Test]
    #[TestDox('Idempotente: a segunda rodada com o mesmo --hoje não emite UPDATE')]
    public function rodarDuasVezesComAMesmaDataNaoMudaNada(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);
        $obrigacaoId = $this->idDe($this->obrigacao($tenant, $caso));

        $primeira = $this->rodar(['--tenant' => (string) $tenant->getId(), '--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $primeira->getStatusCode(), $primeira->getDisplay());
        self::assertSame(1, $this->metrica($primeira->getDisplay(), 'Atualizadas'));

        $depoisDaPrimeira = $this->recarregar($obrigacaoId);
        // `atualizadoEm` é escrito pelo PreUpdate: é a prova de que houve (ou não) UPDATE.
        $carimboOriginal = $depoisDaPrimeira->getAtualizadoEm();
        self::assertNotNull($carimboOriginal, 'a primeira rodada tem de ter gravado');

        $segunda = $this->rodar(['--tenant' => (string) $tenant->getId(), '--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $segunda->getStatusCode(), $segunda->getDisplay());

        self::assertSame(0, $this->metrica($segunda->getDisplay(), 'Atualizadas'));
        self::assertSame(1, $this->metrica($segunda->getDisplay(), 'Inalteradas'));

        $depoisDaSegunda = $this->recarregar($obrigacaoId);
        self::assertSame(1360, $depoisDaSegunda->getJuros());
        self::assertSame(340, $depoisDaSegunda->getMulta());
        self::assertSame(3740, $depoisDaSegunda->getHonorarios());
        self::assertEquals(
            $carimboOriginal,
            $depoisDaSegunda->getAtualizadoEm(),
            'sem mudança de valor não pode haver UPDATE (nem carimbo novo de atualização)',
        );
    }

    #[Test]
    #[TestDox('Encargos inexequíveis não derrubam a rodada: a obrigação sadia é atualizada e o comando alarma')]
    public function falhaDeUmaObrigacaoNaoImpedeAsDemaisEAlarmaNoFim(): void
    {
        $tenant = $this->tenant();

        // Sadia: cenário-base TOPLIFE.
        $sadiaId = $this->idDe($this->obrigacao($tenant, $this->casoTopLife($tenant)));

        // Patológica: 100% a.m. no regime COMPOSTO por 3.650 dias — o montante passa de PHP_INT_MAX
        // e a calculadora lança `EncargosInexequiveisException` em vez de degradar para float.
        $carteiraToxica = $this->carteira($tenant, [
            'taxaJurosMensalBp' => 10000,
            'regimeJuros' => RegimeJuros::Composto,
        ]);
        $casoToxico = $this->caso($tenant, $carteiraToxica);
        $toxicaId = $this->idDe($this->obrigacao($tenant, $casoToxico, 100000, '2016-09-09'));

        $tester = $this->rodar(['--tenant' => (string) $tenant->getId(), '--hoje' => self::HOJE]);

        // O cron alarma (exit 1) — mas só DEPOIS de terminar a rodada.
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());

        self::assertSame(
            1360,
            $this->recarregar($sadiaId)->getJuros(),
            'um caso patológico não pode matar a rodada: a obrigação sadia tem de ter sido gravada',
        );
        self::assertSame(0, $this->recarregar($toxicaId)->getJuros(), 'a patológica fica intacta');

        $display = $tester->getDisplay();
        self::assertSame(1, $this->metrica($display, 'Atualizadas'));
        self::assertSame(1, $this->metrica($display, 'Falhas'));
        self::assertStringContainsString('#' . $toxicaId, $display, 'o relatório tem de identificar a obrigação');
        self::assertStringContainsString('Encargos inexequíveis', $display);
    }

    #[Test]
    #[TestDox('--hoje inválido é recusado antes de tocar em qualquer obrigação')]
    public function dataDeReferenciaInvalidaResultaEmInvalid(): void
    {
        $tenant = $this->tenant();
        $obrigacaoId = $this->idDe($this->obrigacao($tenant, $this->casoTopLife($tenant)));

        $tester = $this->rodar(['--tenant' => (string) $tenant->getId(), '--hoje' => '07/09/2026']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(0, $this->recarregar($obrigacaoId)->getJuros());
    }

    // -------------------------------------------------------------------------------------------
    // Infra do teste
    // -------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     */
    private function rodar(array $args): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:cobranca:atualizar-encargos'));
        $tester->execute($args);

        return $tester;
    }

    /**
     * Lê um número do relatório em tabela do comando (estilo SymfonyStyle: colunas separadas por
     * espaço, sem pipes). Sempre acompanhado de asserção no BANCO — o relatório é o que o operador
     * do cron lê, mas a verdade é o que está gravado.
     */
    private function metrica(string $display, string $rotulo): int
    {
        self::assertSame(
            1,
            preg_match('/' . preg_quote($rotulo, '/') . '[^\S\n]+(\d+)/u', $display, $captura),
            sprintf('métrica "%s" não encontrada no relatório: %s', $rotulo, $display),
        );

        return (int) $captura[1];
    }

    private function idDe(Obrigacao $obrigacao): int
    {
        return (int) $obrigacao->getId();
    }

    /** Releitura HONESTA: descarta o identity map, senão o teste leria o objeto que ele mesmo criou. */
    private function recarregar(int $id): Obrigacao
    {
        $this->em->clear();
        $obrigacao = $this->em->find(Obrigacao::class, $id);
        self::assertInstanceOf(Obrigacao::class, $obrigacao);

        return $obrigacao;
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Encargos ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /**
     * Carteira com o default TOPLIFE (juros 1% a.m. simples, multa 2%, honorários 20% sobre base
     * composta com carência de 30 dias), salvo o que o cenário sobrescrever.
     *
     * @param array<string, mixed> $config
     */
    private function carteira(Tenant $tenant, array $config = []): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        return CarteiraFactory::createOne(array_merge([
            'tenant' => $tenant,
            'cliente' => $cliente,
            'taxaJurosMensalBp' => 100,
            'regimeJuros' => RegimeJuros::Simples,
            'taxaMultaBp' => 200,
            'taxaCorrecaoBp' => 0,
            'carenciaHonorariosDias' => 30,
            'toleranciaJurosMultaDias' => 0,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ], $config))->_real();
    }

    private function caso(Tenant $tenant, Carteira $carteira, StatusCaso $status = StatusCaso::Ativo): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => $status,
            // Snapshot dos honorários no nascimento do caso (§18.2): é daqui que sai a alíquota.
            'formaHonorarios' => $carteira->getFormaHonorarios(),
            'percentualHonorarios' => $carteira->getPercentualHonorarios(),
        ])->_real();
    }

    private function casoTopLife(Tenant $tenant, StatusCaso $status = StatusCaso::Ativo): CasoCobranca
    {
        return $this->caso($tenant, $this->carteira($tenant), $status);
    }

    /**
     * Original cujo acordo substituto foi CANCELADO volta ao saldo (invariável 20) e, por isso, tem
     * de voltar a crescer.
     *
     * Este caso foi encontrado em DADO REAL de dev, não por inspeção: o cron pulava essas obrigações
     * porque `foiSubstituida()` só olha se existe substituto, ignorando o STATUS dele. Resultado: uma
     * dívida viva ficava congelada de fato, para sempre, sem nunca ter sido congelada de direito.
     */
    #[Test]
    #[TestDox('Original cujo acordo substituto foi CANCELADO volta a crescer')]
    public function originalComAcordoSubstitutoCanceladoVoltaACrescer(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);
        $obrigacao = $this->obrigacao($tenant, $caso);
        $obrigacao->setAcordoSubstituto($this->acordo($tenant, $caso, StatusAcordo::Cancelado));
        $this->em->flush();
        $id = $this->idDe($obrigacao);

        $tester = $this->rodar(['--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $this->em->clear();
        $recarregada = $this->recarregar($id);
        self::assertSame(1360, $recarregada->getJuros(), 'dívida que voltou ao saldo tem de crescer');
        self::assertSame(340, $recarregada->getMulta());
        self::assertSame(18700, $recarregada->valorExigivel());
    }

    /**
     * Parcela de acordo CANCELADO virou histórico — não é dívida, e o cron não pode inflá-la.
     *
     * O outro lado do mesmo erro: como nada filtrava a parcela de acordo não-vigente, o cron
     * materializava encargos sobre uma linha que o saldo nem enxerga. Foi observado em dev
     * materializando honorários numa parcela cancelada.
     */
    #[Test]
    #[TestDox('Parcela de acordo CANCELADO não recebe encargos (virou histórico)')]
    public function parcelaDeAcordoCanceladoNaoRecebeEncargos(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);
        $parcela = $this->obrigacao($tenant, $caso);
        $parcela->setAcordoOrigem($this->acordo($tenant, $caso, StatusAcordo::Cancelado));
        $this->em->flush();
        $id = $this->idDe($parcela);

        $tester = $this->rodar(['--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $this->em->clear();
        $recarregada = $this->recarregar($id);
        self::assertSame(0, $recarregada->getJuros(), 'parcela de acordo cancelado não é dívida viva');
        self::assertSame(0, $recarregada->getMulta());
        self::assertSame(0, $recarregada->getHonorarios());
        self::assertSame(self::PRINCIPAL, $recarregada->valorExigivel());
    }

    /**
     * Parcela de acordo VIGENTE não recebe mora automática: aquele número saiu de uma negociação.
     *
     * Note a assimetria deliberada com a exigibilidade do saldo, que INCLUI a parcela de acordo
     * vigente. São perguntas diferentes: "quanto se deve hoje" ≠ "sobre o que o robô faz juros
     * correrem". Se o acordo furar, o caminho é rompê-lo — aí a original volta e cresce inteira.
     */
    #[Test]
    #[TestDox('Parcela de acordo VIGENTE não recebe mora automática (valor pactuado)')]
    public function parcelaDeAcordoVigenteNaoRecebeMora(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);
        $parcela = $this->obrigacao($tenant, $caso);
        $parcela->setAcordoOrigem($this->acordo($tenant, $caso, StatusAcordo::Ativo));
        $this->em->flush();
        $id = $this->idDe($parcela);

        $tester = $this->rodar(['--hoje' => self::HOJE]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $this->em->clear();
        $recarregada = $this->recarregar($id);
        self::assertSame(0, $recarregada->getJuros(), 'valor pactuado em acordo vigente não cresce sozinho');
        self::assertSame(0, $recarregada->getMulta());
        self::assertSame(self::PRINCIPAL, $recarregada->valorExigivel());
    }

    /**
     * O freio central: o cron faz encargo crescer, nunca encolher por conta própria.
     *
     * É a proteção que faltava quando 3.271 obrigações com R$ 155.209,73 estavam a uma rodada de
     * serem zeradas por carteiras com taxa 0 (ver `Version20260719140000`). Aqui a obrigação já tem
     * encargos altos e a carteira é neutra: sem o freio, o recálculo daria 0 e apagaria tudo.
     */
    #[Test]
    #[TestDox('Redução de encargos é BLOQUEADA por padrão e a obrigação fica intacta')]
    public function reducaoDeEncargosEhBloqueadaPorPadrao(): void
    {
        $tenant = $this->tenant();
        // Carteira NEUTRA: taxa 0 em tudo — o recálculo daria 0,0,0,0.
        $caso = $this->caso($tenant, $this->carteira($tenant, [
            'taxaJurosMensalBp' => 0,
            'taxaMultaBp' => 0,
            'percentualHonorarios' => null,
            'formaHonorarios' => FormaHonorarios::SemPercentual,
        ]));

        $obrigacao = $this->obrigacao($tenant, $caso);
        $obrigacao->definirEncargos(5000, 1000, 0, 2000, new \DateTimeImmutable('2026-02-01'));
        $this->em->flush();
        $id = $this->idDe($obrigacao);

        $tester = $this->rodar(['--hoje' => self::HOJE]);

        $this->em->clear();
        $recarregada = $this->recarregar($id);
        self::assertSame(5000, $recarregada->getJuros(), 'o cron NÃO pode apagar encargo já reconhecido');
        self::assertSame(1000, $recarregada->getMulta());
        self::assertSame(2000, $recarregada->getHonorarios());
        self::assertSame(1, $this->metrica($tester->getDisplay(), 'Reduções bloqueadas'));
    }

    #[Test]
    #[TestDox('--hoje no passado é recusado sem --forcar-retroativo')]
    public function hojeRetroativoEhRecusado(): void
    {
        $tenant = $this->tenant();
        $caso = $this->casoTopLife($tenant);
        $id = $this->idDe($this->obrigacao($tenant, $caso));

        $tester = $this->rodar(['--hoje' => '2020-01-01']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--forcar-retroativo', $tester->getDisplay());

        $this->em->clear();
        self::assertSame(0, $this->recarregar($id)->getJuros(), 'nada pode ter sido tocado');
    }

    private function acordo(Tenant $tenant, CasoCobranca $caso, StatusAcordo $status): Acordo
    {
        return AcordoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'status' => $status,
        ])->_real();
    }

    private function obrigacao(
        Tenant $tenant,
        CasoCobranca $caso,
        int $valorOriginal = self::PRINCIPAL,
        string $vencimento = self::VENCIMENTO,
    ): Obrigacao {
        return ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Boleto de teste',
            'valorOriginal' => $valorOriginal,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable($vencimento),
        ])->_real();
    }
}
