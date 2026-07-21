<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\DTO\CorrigirPagamentoInput;
use App\Cobranca\DTO\RegistrarLiquidacaoInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoLiquidacao;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\AutoAlocadorFifo;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ReconciliadorLiquidacao;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use Symfony\Component\Clock\MockClock;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\CorrigirPagamentoUseCase;
use App\Cobranca\UseCase\RegistrarLiquidacaoUseCase;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Cobranca\UseCase\RegistrarPagamentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Isolamento multi-tenant e saldo derivado dos MOVIMENTOS da Etapa 3 (pagamentos, correções,
 * liquidações), contra o BANCO REAL. Roda em KernelTestCase → o TenantFilter fica DESLIGADO (igual
 * ao CLI): prova a guarda EXPLÍCITA dos UseCases (`findOneByIdDoTenant`) e a invariável 12
 * (pagamento não atravessa casos) com entidades PERSISTIDAS (identity-map real), não a rede do
 * filtro. Também prova que o saldo derivado cai por alocações e liquidações e que o rateio de
 * honorários `acrescido_divida` é gravado corretamente (invariáveis 12, 18, 20; SPEC §11/§18).
 */
#[CoversClass(RegistrarPagamentoUseCase::class)]
#[CoversClass(CorrigirPagamentoUseCase::class)]
#[CoversClass(RegistrarLiquidacaoUseCase::class)]
#[CoversClass(AlocadorPagamento::class)]
#[CoversClass(CalculadoraSaldo::class)]
final class MovimentosCobrancaIsolamentoTenantTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private AbrirCasoUseCase $abrirCaso;
    private RegistrarObrigacaoUseCase $registrarObrigacao;
    private RegistrarPagamentoUseCase $registrarPagamento;
    private CorrigirPagamentoUseCase $corrigirPagamento;
    private RegistrarLiquidacaoUseCase $registrarLiquidacao;
    private CalculadoraSaldo $calculadoraSaldo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        /** @var ObjetoCobrancaRepository $objetoRepo */
        $objetoRepo = $this->em->getRepository(ObjetoCobranca::class);
        /** @var PessoaRepository $pessoaRepo */
        $pessoaRepo = $this->em->getRepository(Pessoa::class);
        /** @var ObrigacaoRepository $obrigacaoRepo */
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        /** @var EventoHistoricoRepository $eventoRepo */
        $eventoRepo = $this->em->getRepository(EventoHistorico::class);
        /** @var PagamentoRepository $pagamentoRepo */
        $pagamentoRepo = $this->em->getRepository(\App\Cobranca\Entity\Pagamento::class);
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(\App\Cobranca\Entity\AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);
        $calculadora = new CalculadoraHonorarios();
        $alocador = new AlocadorPagamento($obrigacaoRepo, $calculadora);
        $autoAlocador = new AutoAlocadorFifo(
            $obrigacaoRepo,
            $alocacaoRepo,
            $liquidacaoRepo,
            $calculadora,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
        );

        $this->abrirCaso = new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento);
        $this->registrarObrigacao = new RegistrarObrigacaoUseCase($obrigacaoRepo, $casoRepo, $registrarEvento, new CalculadoraEncargos(), new ResolvedorConfigEncargos(), new ConversorTaxaEncargo(new CalculadoraEncargos()));
        $reconciliador = new ReconciliadorLiquidacao(new CalculadoraEncargos(), new ResolvedorConfigEncargos());
        $this->registrarPagamento = new RegistrarPagamentoUseCase($pagamentoRepo, $casoRepo, $alocador, $autoAlocador, $registrarEvento, $alocacaoRepo, new ResolvedorConfigEncargos(), $reconciliador);
        $this->corrigirPagamento = new CorrigirPagamentoUseCase($pagamentoRepo, $alocador, $autoAlocador, $registrarEvento, $alocacaoRepo, new ResolvedorConfigEncargos(), $reconciliador);
        $this->registrarLiquidacao = new RegistrarLiquidacaoUseCase($liquidacaoRepo, $casoRepo, $registrarEvento);
        $this->calculadoraSaldo = new CalculadoraSaldo(
            $obrigacaoRepo,
            $casoRepo,
            $alocacaoRepo,
            $liquidacaoRepo,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
        );
    }

    #[TestDox('Pagamento alocado reduz o saldo derivado do caso no mesmo escritório')]
    public function testPagamentoReduzSaldoNoMesmoTenant(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user, ModoCarteira::Multiplo);

        $obrigA = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 10000), $tenant, $user);
        $obrigB = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 25000), $tenant, $user);
        self::assertSame(35000, $this->calculadoraSaldo->saldoExigivel($caso));

        $pagamento = $this->registrarPagamento->executar(
            $this->pagamentoInput((int) $caso->getId(), 15000, [
                [(int) $obrigA->getId(), 10000],
                [(int) $obrigB->getId(), 5000],
            ]),
            $tenant,
            $user,
        );

        self::assertSame(15000, $pagamento->getValorDivida());
        self::assertSame(0, $pagamento->getValorHonorarios());
        self::assertCount(2, $pagamento->getAlocacoes());
        // Saldo derivado cai pelas alocações (35000 − 15000).
        self::assertSame(20000, $this->calculadoraSaldo->saldoExigivel($caso));
    }

    #[TestDox('Pagamento RETROATIVO quita pelo exigível da DATA do pagamento (FIFO e reconciliação na mesma base)')]
    public function testPagamentoRetroativoQuitaPelaDataDoPagamentoEFechaSaldoEmZero(): void
    {
        // Relógio dos serviços = 20/07/2026 (setUp). Config TOPLIFE no caso (juros 1% a.m., multa 2%).
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user, ModoCarteira::Multiplo);
        $caso->setTaxaJurosMensalBp(100)->setTaxaMultaBp(200);
        $this->em->flush();

        // Duas obrigações P=100,00, venc 10/06/2026. Exigível em 10/07 (30 dias) = 103,00 CADA (juros
        // 1,00 + multa 2,00). Em 20/07 (hoje, 40 dias) seria 103,33 cada — é essa divergência que o
        // pagamento retroativo tem de respeitar (medir na data do pagamento, não em hoje).
        $obrigA = $this->registrarObrigacao->executar($this->obrigacaoInputVenc((int) $caso->getId(), 10000, '2026-06-10'), $tenant, $user);
        $obrigB = $this->registrarObrigacao->executar($this->obrigacaoInputVenc((int) $caso->getId(), 10000, '2026-06-10'), $tenant, $user);

        // Paga o total devido NA DATA (10/07) = 206,00, via FIFO (auto-alocação), com data retroativa.
        $input = new RegistrarPagamentoInput();
        $input->casoId = (int) $caso->getId();
        $input->valorPago = 20600;
        $input->data = new \DateTimeImmutable('2026-07-10');
        $input->alocarManualmente = false;
        $this->registrarPagamento->executar($input, $tenant, $user);

        $this->em->refresh($obrigA);
        $this->em->refresh($obrigB);

        // AMBAS quitam na data do pagamento (antes do fix, a 2ª ficava Viva por o FIFO medir em 20/07).
        self::assertTrue($obrigA->estaLiquidada(), 'obrigação A liquidada');
        self::assertTrue($obrigB->estaLiquidada(), 'obrigação B liquidada');
        self::assertEquals(new \DateTimeImmutable('2026-07-10'), $obrigA->getLiquidadaEm());
        self::assertSame(10300, $obrigA->valorExigivel(), 'snapshot na data do pagamento (não em hoje)');
        // Saldo do caso fecha em ZERO — sem resíduo e sem negativo (sem divergência de data-base).
        self::assertSame(0, $this->calculadoraSaldo->saldoExigivel($caso));
    }

    #[TestDox('Pagamento acrescido_divida rateia honorários e grava a composição no banco')]
    public function testPagamentoAcrescidoRateiaHonorarios(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user, ModoCarteira::Multiplo, FormaHonorarios::AcrescidoDivida, '10.00');

        $obrig = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 100000), $tenant, $user);

        // Devedor paga R$110,00 (11000 centavos): 10% acrescido → dívida 10000 + honorários 1000.
        $pagamento = $this->registrarPagamento->executar(
            $this->pagamentoInput((int) $caso->getId(), 11000, [[(int) $obrig->getId(), 10000]]),
            $tenant,
            $user,
        );

        self::assertSame(10000, $pagamento->getValorDivida());
        self::assertSame(1000, $pagamento->getValorHonorarios());
        // Só a parte da dívida abate o saldo do credor (100000 − 10000); honorários ficam à parte.
        self::assertSame(90000, $this->calculadoraSaldo->saldoExigivel($caso));
    }

    #[TestDox('Registrar pagamento não alcança caso de outro escritório (IDOR por tenant)')]
    public function testPagamentoNaoAlcancaCasoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user, ModoCarteira::Multiplo);
        $obrigA = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $casoA->getId(), 10000), $tenantA, $user);

        $this->expectException(CasoNaoEncontradoException::class);

        $this->registrarPagamento->executar(
            $this->pagamentoInput((int) $casoA->getId(), 10000, [[(int) $obrigA->getId(), 10000]]),
            $tenantB,
            $user,
        );
    }

    #[TestDox('Pagamento não atravessa casos: alocar obrigação de outro caso é rejeitado (invariável 12)')]
    public function testPagamentoNaoAtravessaCasos(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        // Modo múltiplo: dois casos ativos no mesmo objeto.
        $objeto = $this->criarObjeto($tenant, ModoCarteira::Multiplo);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $caso1 = $this->abrirCaso->executar($this->abrirInput($objeto, $pessoa), $tenant, $user);
        $caso2 = $this->abrirCaso->executar($this->abrirInput($objeto, $pessoa), $tenant, $user);

        $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso1->getId(), 10000), $tenant, $user);
        $obrigDoCaso2 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso2->getId(), 8000), $tenant, $user);

        // Pagar no caso 1 alocando uma obrigação do caso 2 → rejeição (mesmo tenant, casos distintos).
        $this->expectException(ObrigacaoDeOutroCasoException::class);

        $this->registrarPagamento->executar(
            $this->pagamentoInput((int) $caso1->getId(), 8000, [[(int) $obrigDoCaso2->getId(), 8000]]),
            $tenant,
            $user,
        );
    }

    #[TestDox('Liquidação reduz o saldo pelo valor reconhecido, distinto do valor do bem')]
    public function testLiquidacaoReduzSaldoPeloReconhecido(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user, ModoCarteira::Multiplo);
        $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 100000), $tenant, $user);

        $input = new RegistrarLiquidacaoInput();
        $input->casoId = (int) $caso->getId();
        $input->tipo = TipoLiquidacao::BemImovel;
        $input->descricaoBem = 'Imóvel dado em pagamento';
        $input->valorAtribuidoBem = 800000;   // valor do bem
        $input->valorReconhecido = 30000;      // o que se reconhece da dívida (≠ valor do bem)
        $input->data = new \DateTimeImmutable('-1 day');

        $liquidacao = $this->registrarLiquidacao->executar($input, $tenant, $user);

        self::assertSame(800000, $liquidacao->getValorAtribuidoBem());
        self::assertSame(30000, $liquidacao->getValorReconhecido());
        // Saldo cai só pelo reconhecido (100000 − 30000), nunca pelo valor do bem.
        self::assertSame(70000, $this->calculadoraSaldo->saldoExigivel($caso));
    }

    #[TestDox('Registrar liquidação não alcança caso de outro escritório (IDOR por tenant)')]
    public function testLiquidacaoNaoAlcancaCasoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user, ModoCarteira::Multiplo);

        $input = new RegistrarLiquidacaoInput();
        $input->casoId = (int) $casoA->getId();
        $input->tipo = TipoLiquidacao::BemMovel;
        $input->descricaoBem = 'Veículo';
        $input->valorReconhecido = 5000;
        $input->data = new \DateTimeImmutable('-1 day');

        $this->expectException(CasoNaoEncontradoException::class);

        $this->registrarLiquidacao->executar($input, $tenantB, $user);
    }

    #[TestDox('Corrigir pagamento não alcança pagamento de outro escritório (IDOR por tenant)')]
    public function testCorrigirPagamentoNaoAlcancaPagamentoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user, ModoCarteira::Multiplo);
        $obrigA = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $casoA->getId(), 10000), $tenantA, $user);
        $pagamento = $this->registrarPagamento->executar(
            $this->pagamentoInput((int) $casoA->getId(), 10000, [[(int) $obrigA->getId(), 10000]]),
            $tenantA,
            $user,
        );

        $input = new CorrigirPagamentoInput();
        $input->pagamentoId = (int) $pagamento->getId();
        $input->valorPago = 5000;
        $input->alocarManualmente = true;
        $input->alocacoes = [$this->alocacao((int) $obrigA->getId(), 5000)];
        $input->motivoCorrecao = 'Valor lançado errado';

        $this->expectException(PagamentoNaoEncontradoException::class);

        $this->corrigirPagamento->executar($input, $tenantB, $user);
    }

    // ----------------------------------------------------------------- helpers

    private function abrirCasoDe(
        Tenant $tenant,
        User $user,
        ModoCarteira $modo,
        FormaHonorarios $forma = FormaHonorarios::SemPercentual,
        ?string $percentual = null,
    ): CasoCobranca {
        $objeto = $this->criarObjeto($tenant, $modo, $forma, $percentual);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        return $this->abrirCaso->executar($this->abrirInput($objeto, $pessoa), $tenant, $user);
    }

    private function abrirInput(object $objeto, object $pessoa): AbrirCasoInput
    {
        $input = new AbrirCasoInput();
        $input->objetoId = (int) $objeto->getId();
        $input->pessoaCobradaId = (int) $pessoa->getId();

        return $input;
    }

    private function obrigacaoInput(int $casoId, int $valorCentavos): RegistrarObrigacaoInput
    {
        $input = new RegistrarObrigacaoInput();
        $input->casoId = $casoId;
        $input->descricao = 'Competência';
        $input->valorOriginal = $valorCentavos;
        $input->vencimentoOriginal = new \DateTimeImmutable('-10 days');

        return $input;
    }

    private function obrigacaoInputVenc(int $casoId, int $valorCentavos, string $vencimento): RegistrarObrigacaoInput
    {
        $input = new RegistrarObrigacaoInput();
        $input->casoId = $casoId;
        $input->descricao = 'Competência';
        $input->valorOriginal = $valorCentavos;
        $input->vencimentoOriginal = new \DateTimeImmutable($vencimento);

        return $input;
    }

    /**
     * @param array<array{0:int,1:int}> $alocacoes lista de [obrigacaoId, valor]
     */
    private function pagamentoInput(int $casoId, int $valorPago, array $alocacoes): RegistrarPagamentoInput
    {
        $input = new RegistrarPagamentoInput();
        $input->casoId = $casoId;
        $input->valorPago = $valorPago;
        $input->data = new \DateTimeImmutable('-1 day');
        $input->alocarManualmente = true; // estes testes exercitam a alocação MANUAL explícita.
        $input->alocacoes = array_map(fn (array $a): AlocacaoPagamentoInput => $this->alocacao($a[0], $a[1]), $alocacoes);

        return $input;
    }

    private function alocacao(int $obrigacaoId, int $valor): AlocacaoPagamentoInput
    {
        $input = new AlocacaoPagamentoInput();
        $input->obrigacaoId = $obrigacaoId;
        $input->valor = $valor;

        return $input;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant MOV ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('mov_' . uniqid() . '@test.com');
        $user->setFullName('User Movimentos');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Objeto consistente com o tenant, com carteira no modo e regra de honorários indicados. */
    private function criarObjeto(
        Tenant $tenant,
        ModoCarteira $modo,
        FormaHonorarios $forma = FormaHonorarios::SemPercentual,
        ?string $percentual = null,
    ): object {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => $modo,
            'formaHonorarios' => $forma,
            'percentualHonorarios' => $percentual,
        ]);

        return ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
    }
}
