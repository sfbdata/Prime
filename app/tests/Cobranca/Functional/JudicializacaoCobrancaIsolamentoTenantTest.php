<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\ConcluirAcaoInput;
use App\Cobranca\DTO\DefinirProximaAcaoInput;
use App\Cobranca\DTO\EncerrarCasoInput;
use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoLiquidacao;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PastaNaoEncontradaException;
use App\Cobranca\Exception\ProximaAcaoAtivaJaExisteException;
use App\Cobranca\Exception\ProximaAcaoNaoEncontradaException;
use App\Cobranca\Exception\SaldoNaoResolvidoException;
use App\Cobranca\UseCase\ConcluirAcaoUseCase;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\DefinirProximaAcaoUseCase;
use App\Cobranca\UseCase\EncerrarCasoUseCase;
use App\Cobranca\UseCase\JudicializarCasoUseCase;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Etapa 5 (estados/judicialização/encerramento/ações) contra o BANCO REAL. Prova:
 * (1) o vínculo com Pasta respeita o tenant — NÃO é possível judicializar com Pasta de outro
 * escritório (SPEC §16, invariável 1 — o ponto ALTO-risco da etapa); (2) judicialização muda a fase
 * e NÃO encerra (invariável 16); (3) encerramento é manual e só com saldo exigível zero (invariável
 * 17); (4) máx. 1 próxima ação ativa por caso (§14). Roda em KernelTestCase → TenantFilter desligado:
 * prova a guarda EXPLÍCITA dos UseCases, não a rede do filtro.
 */
#[CoversClass(JudicializarCasoUseCase::class)]
#[CoversClass(EncerrarCasoUseCase::class)]
#[CoversClass(DefinirProximaAcaoUseCase::class)]
#[CoversClass(ConcluirAcaoUseCase::class)]
final class JudicializacaoCobrancaIsolamentoTenantTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private AbrirCasoUseCase $abrirCaso;
    private RegistrarObrigacaoUseCase $registrarObrigacao;
    private JudicializarCasoUseCase $judicializar;
    private EncerrarCasoUseCase $encerrar;
    private DefinirProximaAcaoUseCase $definirProximaAcao;
    private ConcluirAcaoUseCase $concluirAcao;

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
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);
        /** @var PastaRepository $pastaRepo */
        $pastaRepo = $this->em->getRepository(Pasta::class);
        /** @var ProximaAcaoRepository $proximaAcaoRepo */
        $proximaAcaoRepo = $this->em->getRepository(ProximaAcao::class);

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);
        $calculadoraSaldo = new CalculadoraSaldo($obrigacaoRepo, $casoRepo, $alocacaoRepo, $liquidacaoRepo, new \App\Cobranca\Service\EncargosVivos(new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2026-07-20')), new \App\Cobranca\Service\CalculadoraEncargos(), new \App\Cobranca\Service\ResolvedorConfigEncargos()), new \App\Cobranca\Service\ResolvedorConfigEncargos());

        $this->abrirCaso = new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento);
        $this->registrarObrigacao = new RegistrarObrigacaoUseCase($obrigacaoRepo, $casoRepo, $registrarEvento, new CalculadoraEncargos(), new ResolvedorConfigEncargos());
        $this->judicializar = new JudicializarCasoUseCase($casoRepo, $pastaRepo, $registrarEvento);
        $this->encerrar = new EncerrarCasoUseCase($casoRepo, $calculadoraSaldo, $registrarEvento);
        $this->definirProximaAcao = new DefinirProximaAcaoUseCase($casoRepo, $proximaAcaoRepo);
        $this->concluirAcao = new ConcluirAcaoUseCase($proximaAcaoRepo);
    }

    #[TestDox('Judicializar vincula Pasta do mesmo tenant, muda a fase e NÃO encerra (invariável 16)')]
    public function testJudicializaComPastaDoMesmoTenantNaoEncerra(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);
        $pasta = PastaFactory::createOne(['tenant' => $tenant]);

        $input = new JudicializarCasoInput();
        $input->casoId = (int) $caso->getId();
        $input->pastaId = (int) $pasta->getId();

        $resultado = $this->judicializar->executar($input, $tenant, $user);

        self::assertSame(StatusCaso::Judicializado, $resultado->getStatus());
        self::assertFalse($resultado->estaEncerrado());
        self::assertNotNull($resultado->getPastaJudicial());
        self::assertSame((int) $pasta->getId(), (int) $resultado->getPastaJudicial()->getId());
    }

    #[TestDox('Judicializar NÃO alcança Pasta de outro escritório (isolamento de tenant no vínculo)')]
    public function testJudicializarRejeitaPastaDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);
        // Pasta pertence ao tenant B; o caso é do tenant A. O vínculo NÃO pode atravessar escritórios.
        $pastaB = PastaFactory::createOne(['tenant' => $tenantB]);

        $input = new JudicializarCasoInput();
        $input->casoId = (int) $casoA->getId();
        $input->pastaId = (int) $pastaB->getId();

        $this->expectException(PastaNaoEncontradaException::class);

        $this->judicializar->executar($input, $tenantA, $user);
    }

    #[TestDox('Judicializar não alcança caso de outro escritório (IDOR por tenant)')]
    public function testJudicializarNaoAlcancaCasoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);
        $pastaB = PastaFactory::createOne(['tenant' => $tenantB]);

        $input = new JudicializarCasoInput();
        $input->casoId = (int) $casoA->getId();
        $input->pastaId = (int) $pastaB->getId();

        $this->expectException(CasoNaoEncontradoException::class);

        // Operando como tenant B sobre um caso do tenant A: nem chega a olhar a pasta.
        $this->judicializar->executar($input, $tenantB, $user);
    }

    #[TestDox('Encerrar exige saldo zero: com obrigação em aberto rejeita; funciona a partir de judicializado')]
    public function testEncerrarExigeSaldoZeroEFuncionaDeJudicializado(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);
        $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 15000), $tenant, $user);

        // Judicializa (continua acompanhando; não encerra).
        $pasta = PastaFactory::createOne(['tenant' => $tenant]);
        $jInput = new JudicializarCasoInput();
        $jInput->casoId = (int) $caso->getId();
        $jInput->pastaId = (int) $pasta->getId();
        $this->judicializar->executar($jInput, $tenant, $user);

        // Saldo em aberto (15000): encerrar é bloqueado (invariável 17).
        $encerrarInput = new EncerrarCasoInput();
        $encerrarInput->casoId = (int) $caso->getId();

        try {
            $this->encerrar->executar($encerrarInput, $tenant, $user);
            self::fail('Deveria ter rejeitado o encerramento com saldo em aberto.');
        } catch (SaldoNaoResolvidoException) {
            // esperado
        }

        // Zera o saldo com um pagamento total e então encerra (a partir de judicializado).
        $this->quitarObrigacoes($caso, $tenant, $user, 15000);

        $resultado = $this->encerrar->executar($encerrarInput, $tenant, $user);
        self::assertSame(StatusCaso::Encerrado, $resultado->getStatus());
        self::assertTrue($resultado->estaEncerrado());
    }

    #[TestDox('Máximo de uma próxima ação ativa por caso (§14) — validado no DB')]
    public function testMaximoUmaProximaAcaoAtiva(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);

        $primeira = new DefinirProximaAcaoInput();
        $primeira->casoId = (int) $caso->getId();
        $primeira->descricao = 'Verificar pagamento';
        $this->definirProximaAcao->executar($primeira, $tenant, $user);

        $segunda = new DefinirProximaAcaoInput();
        $segunda->casoId = (int) $caso->getId();
        $segunda->descricao = 'Entrar em contato';

        $this->expectException(ProximaAcaoAtivaJaExisteException::class);
        $this->definirProximaAcao->executar($segunda, $tenant, $user);
    }

    #[TestDox('IDOR por tenant: Encerrar/Definir não alcançam caso de outro escritório')]
    public function testUseCasesDeCasoNaoAlcancamOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);
        $casoId = (int) $casoA->getId();

        $encerrar = new EncerrarCasoInput();
        $encerrar->casoId = $casoId;
        try {
            $this->encerrar->executar($encerrar, $tenantB, $user);
            self::fail('Encerrar deveria rejeitar caso de outro tenant.');
        } catch (CasoNaoEncontradoException) {
            // esperado
        }

        $definir = new DefinirProximaAcaoInput();
        $definir->casoId = $casoId;
        $definir->descricao = 'Ação intrusa';
        $this->expectException(CasoNaoEncontradoException::class);
        $this->definirProximaAcao->executar($definir, $tenantB, $user);
    }

    #[TestDox('IDOR por tenant: Concluir ação não alcança registro de outro escritório')]
    public function testConcluirNaoAlcancaOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);

        // Ação criada no tenant A.
        $definir = new DefinirProximaAcaoInput();
        $definir->casoId = (int) $casoA->getId();
        $definir->descricao = 'Verificar pagamento';
        $acaoA = $this->definirProximaAcao->executar($definir, $tenantA, $user);

        // Tenant B tenta concluir a ação do A: rejeitado.
        $concluir = new ConcluirAcaoInput();
        $concluir->acaoId = (int) $acaoA->getId();
        $concluir->resultado = 'Tentativa intrusa';
        $this->expectException(ProximaAcaoNaoEncontradaException::class);
        $this->concluirAcao->executar($concluir, $tenantB, $user);
    }

    // ----------------------------------------------------------------- helpers

    private function quitarObrigacoes(CasoCobranca $caso, Tenant $tenant, User $user, int $totalCentavos): void
    {
        // Registra uma liquidação reconhecida que zera o saldo exigível (caminho não monetário simples).
        $liquidacao = new Liquidacao();
        $liquidacao->setTenant($tenant);
        $liquidacao->setCaso($caso);
        $liquidacao->setTipo(TipoLiquidacao::Outro);
        $liquidacao->setDescricaoBem('Quitação para teste de encerramento');
        $liquidacao->setValorReconhecido($totalCentavos);
        $liquidacao->setData(new \DateTimeImmutable('-1 day'));
        $this->em->persist($liquidacao);
        $this->em->flush();
    }

    private function abrirCasoDe(Tenant $tenant, User $user): CasoCobranca
    {
        $objeto = $this->criarObjeto($tenant);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $input = new AbrirCasoInput();
        $input->objetoId = (int) $objeto->getId();
        $input->pessoaCobradaId = (int) $pessoa->getId();

        return $this->abrirCaso->executar($input, $tenant, $user);
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

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant JUD ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('jud_' . uniqid() . '@test.com');
        $user->setFullName('User Jud');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarObjeto(Tenant $tenant): object
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => ModoCarteira::Multiplo,
        ]);

        return ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
    }
}
