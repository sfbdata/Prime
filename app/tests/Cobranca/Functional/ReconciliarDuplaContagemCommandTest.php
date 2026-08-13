<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\ReconciliarDuplaContagemCommand;
use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Auth\UserFactory;
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
 * As TRAVAS do comando que escreve dinheiro (SPEC espelho §17.11).
 *
 * Existe porque a revisão apontou o óbvio que tinha passado: das duas peças da frente, a somente-leitura
 * tinha teste de comando e **a que escreve não tinha nenhum**. As guardas testadas aqui — `--aplicar`
 * sem autor, autor de outro escritório, e a expectativa da lista aprovada — são exatamente o que separa
 * "o dono aprovou esta lista" de "o comando escreveu sobre outra coisa".
 */
final class ReconciliarDuplaContagemCommandTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

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
        $this->comando = new CommandTester($aplicacao->find('app:cobranca:reconciliar-dupla-contagem'));
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('🔴 sem --aplicar, SIMULA: nada é gravado e a linha para aplicar sai pronta')]
    public function testSimulaPorPadrao(): void
    {
        [$carteira, $obrigacao] = $this->cenario();

        $codigo = $this->comando->execute(['--tenant-id' => (string) $this->tenantDe($carteira)->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);

        $this->em->flush();
        $this->em->refresh($obrigacao);
        self::assertSame(5436, $obrigacao->getMulta(), 'a simulação não grava');

        // A simulação entrega as DUAS formas prontas: a simples e a travada nesta lista.
        $saida = $this->semQuebras($this->comando->getDisplay());
        self::assertStringContainsString('--aplicar --usuario-id=<id>', $saida);
        self::assertStringContainsString('--esperado-dividas=1 --esperado-total=4545', $saida);
    }

    #[TestDox('🔴 --aplicar SEM --usuario-id não grava: mudança financeira precisa de autor')]
    public function testAplicarSemAutorNaoGrava(): void
    {
        [$carteira, $obrigacao] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $this->tenantDe($carteira)->getId(),
            '--aplicar' => true,
            '--esperado-dividas' => '1',
            '--esperado-total' => '4545',
        ]);

        self::assertSame(ReconciliarDuplaContagemCommand::ERRO_DE_INVOCACAO, $codigo);

        // 🔑 Prende QUAL guarda barrou. Sem esta asserção o teste passa mesmo com a guarda de autor
        // removida — quem barraria seria a guarda seguinte (a de tenant, que não acha vínculo para um
        // usuário nulo), e o teste estaria provando outra coisa. Conferido reintroduzindo o defeito.
        self::assertStringContainsString(
            'a correção precisa de autor',
            $this->semQuebras($this->comando->getDisplay()),
        );

        $this->em->refresh($obrigacao);
        self::assertSame(5436, $obrigacao->getMulta(), 'sem autor, nada pode ter sido gravado');
    }

    #[TestDox('--aplicar funciona SOZINHO: a trava da lista é opcional, não obrigatória')]
    public function testAplicarSemExpectativaGrava(): void
    {
        // Decisão do dono: a trava fica disponível, não exigida. O risco que ela cobre é pequeno
        // enquanto a importação está bloqueada, e exigi-la custaria uma etapa manual em toda execução.
        [$carteira, $obrigacao] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $this->tenantDe($carteira)->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $this->usuario($carteira)->getId(),
        ]);

        self::assertSame(Command::SUCCESS, $codigo);

        $this->em->clear();
        /** @var Obrigacao $recarregada */
        $recarregada = $this->em->getRepository(Obrigacao::class)->find($obrigacao->getId());
        self::assertSame(891, $recarregada->getMulta(), 'gravou sem precisar da trava');
    }

    #[TestDox('🔴 as duas opções da trava andam JUNTAS — meia trava é pior que nenhuma')]
    public function testMeiaTravaEhRecusada(): void
    {
        // Quem informa só uma acha que travou, e não travou. Recusar é a única leitura honesta.
        [$carteira, $obrigacao] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $this->tenantDe($carteira)->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $this->usuario($carteira)->getId(),
            '--esperado-dividas' => '1',
        ]);

        self::assertSame(ReconciliarDuplaContagemCommand::ERRO_DE_INVOCACAO, $codigo);
        self::assertStringContainsString(
            'andam juntos',
            $this->semQuebras($this->comando->getDisplay()),
        );

        $this->em->refresh($obrigacao);
        self::assertSame(5436, $obrigacao->getMulta(), 'meia trava não pode gravar');
    }

    #[TestDox('🔴 autor de OUTRO escritório não assina a correção')]
    public function testAutorDeOutroEscritorioNaoGrava(): void
    {
        [$carteira, $obrigacao] = $this->cenario();

        /** @var User $intruso */
        $intruso = UserFactory::createOne()->_real();
        $this->em->persist(new UserTenant($intruso, TenantFactory::createOne()->_real()));
        $this->em->flush();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $this->tenantDe($carteira)->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $intruso->getId(),
            '--esperado-dividas' => '1',
            '--esperado-total' => '4545',
        ]);

        self::assertSame(ReconciliarDuplaContagemCommand::ERRO_DE_INVOCACAO, $codigo);
        self::assertStringContainsString(
            'não é membro deste escritório',
            $this->semQuebras($this->comando->getDisplay()),
        );

        $this->em->refresh($obrigacao);
        self::assertSame(5436, $obrigacao->getMulta(), 'intruso não pode ter movido dinheiro');
    }

    #[TestDox('🔴 §17.11 — se a lista MUDOU desde a aprovação, aborta e NÃO grava nada')]
    public function testListaDiferenteDaAprovadaAbortaSemGravar(): void
    {
        [$carteira, $obrigacao] = $this->cenario();

        // O dono aprovou 2 dívidas; o comando vai achar 1. É o cenário do lote novo entrando entre a
        // aprovação e a escrita — e é o que separa "abortar" de "adivinhar".
        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $this->tenantDe($carteira)->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $this->usuario($carteira)->getId(),
            '--esperado-dividas' => '2',
            '--esperado-total' => '4545',
        ]);

        self::assertSame(ReconciliarDuplaContagemCommand::LISTA_MUDOU, $codigo);
        self::assertStringContainsString('Nada foi gravado', $this->semQuebras($this->comando->getDisplay()));

        // A prova de que a transação foi revertida: a alteração JÁ tinha sido feita no laço quando a
        // trava disparou, e o rollback tem de tê-la desfeito.
        $this->em->clear();
        /** @var Obrigacao $recarregada */
        $recarregada = $this->em->getRepository(Obrigacao::class)->find($obrigacao->getId());
        self::assertSame(5436, $recarregada->getMulta(), 'o rollback tem de ter desfeito a escrita');
    }

    #[TestDox('Com autor, expectativa certa e tudo conferido: grava e sai 0')]
    public function testAplicarComTudoCertoGrava(): void
    {
        [$carteira, $obrigacao] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $this->tenantDe($carteira)->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $this->usuario($carteira)->getId(),
            '--esperado-dividas' => '1',
            '--esperado-total' => '4545',
        ]);

        self::assertSame(Command::SUCCESS, $codigo);

        $this->em->clear();
        /** @var Obrigacao $recarregada */
        $recarregada = $this->em->getRepository(Obrigacao::class)->find($obrigacao->getId());
        self::assertSame(891, $recarregada->getMulta());

        $saida = $this->semQuebras($this->comando->getDisplay());
        self::assertStringContainsString('SAIU do SALDO do devedor', $saida);
        // O aviso que impede alguém de "desconsertar" ao ver a régua marcar divergente depois.
        self::assertStringContainsString('DIVERGENTE, e isso é esperado', $saida);
    }

    #[TestDox('Tenant inexistente e carteira de outro escritório não passam')]
    public function testInvocacaoInvalida(): void
    {
        [$carteira] = $this->cenario();

        self::assertSame(
            ReconciliarDuplaContagemCommand::ERRO_DE_INVOCACAO,
            $this->comando->execute(['--tenant-id' => '999999']),
        );

        self::assertSame(
            ReconciliarDuplaContagemCommand::ERRO_DE_INVOCACAO,
            $this->comando->execute([
                '--tenant-id' => (string) $this->tenantDe($carteira)->getId(),
                '--carteira-id' => '999999',
            ]),
        );
    }

    /**
     * Uma parcela de acordo com a assinatura na MULTA — a forma em que o defeito apareceu em produção.
     *   Σ J = 8,91 · multa gravada = 8,91 + 45,45 = 54,36 ← os 45,45 duplicados
     *
     * @return array{Carteira, Obrigacao}
     */
    private function cenario(): array
    {
        $carteira = CarteiraFactory::createOne([
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

        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => '02-01',
        ]);

        /** @var CasoCobranca $caso */
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => '74790',
            'competencia' => '12/2025',
            'valorOriginal' => 44545,
            'vencimentoOriginal' => new \DateTimeImmutable('2025-12-15'),
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

        return [$carteira, $obrigacao];
    }

    private function tenantDe(Carteira $carteira): Tenant
    {
        $tenant = $carteira->getTenant();
        self::assertNotNull($tenant);

        return $tenant;
    }

    private function usuario(Carteira $carteira): User
    {
        /** @var User $user */
        $user = UserFactory::createOne()->_real();
        $this->em->persist(new UserTenant($user, $this->tenantDe($carteira)));
        $this->em->flush();

        return $user;
    }

    private function semQuebras(string $saida): string
    {
        return (string) preg_replace('/\s+/u', ' ', $saida);
    }
}
