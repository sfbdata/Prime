<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\AlterarPessoaCobradaInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoAtivoJaExisteException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\AlterarPessoaCobradaUseCase;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
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
 * Isolamento multi-tenant e saldo derivado do núcleo do Caso de Cobrança (Etapa 2), contra o BANCO
 * REAL. Roda em KernelTestCase → o TenantFilter fica DESLIGADO (igual ao CLI): prova a guarda
 * EXPLÍCITA dos UseCases (`findOneByIdDoTenant`), não a rede do filtro. Semeia as pontas no MESMO
 * tenant e depois diverge uma para outro escritório, provando a rejeição — sem confiar nos defaults
 * das factories (invariável 1/23).
 */
#[CoversClass(AbrirCasoUseCase::class)]
#[CoversClass(RegistrarObrigacaoUseCase::class)]
#[CoversClass(AlterarPessoaCobradaUseCase::class)]
#[CoversClass(CalculadoraSaldo::class)]
final class CasoCobrancaIsolamentoTenantTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private AbrirCasoUseCase $abrirCaso;
    private RegistrarObrigacaoUseCase $registrarObrigacao;
    private AlterarPessoaCobradaUseCase $alterarPessoaCobrada;
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
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);
        $this->abrirCaso = new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento);
        $this->registrarObrigacao = new RegistrarObrigacaoUseCase($obrigacaoRepo, $casoRepo, $registrarEvento, new CalculadoraEncargos(), new ResolvedorConfigEncargos(), new ConversorTaxaEncargo(new CalculadoraEncargos()));
        $this->alterarPessoaCobrada = new AlterarPessoaCobradaUseCase($casoRepo, $pessoaRepo, $registrarEvento);
        $this->calculadoraSaldo = new CalculadoraSaldo($obrigacaoRepo, $casoRepo, $alocacaoRepo, $liquidacaoRepo, new \App\Cobranca\Service\EncargosVivos(new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2026-07-20')), new \App\Cobranca\Service\CalculadoraEncargos(), new \App\Cobranca\Service\ResolvedorConfigEncargos()), new \App\Cobranca\Service\ResolvedorConfigEncargos());
    }

    #[TestDox('Abre caso e deriva saldo quando objeto e pessoa são do mesmo escritório')]
    public function testAbreCasoEDerivaSaldoNoMesmoTenant(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $objeto = $this->criarObjeto($tenant, ModoCarteira::Multiplo);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        $caso = $this->abrirCaso->executar($this->abrirInput($objeto, $pessoa), $tenant, $user);

        self::assertNotNull($caso->getId());
        self::assertSame(StatusCaso::Ativo, $caso->getStatus());
        self::assertSame($pessoa->getId(), $caso->getPessoaCobradaAtual()?->getId());

        // Duas obrigações → saldo exigível = soma (centavos). Saldo é derivado, nunca coluna.
        $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 10000), $tenant, $user);
        $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 25000), $tenant, $user);

        self::assertSame(35000, $this->calculadoraSaldo->saldoExigivel($caso));
    }

    #[TestDox('Rejeita abrir caso com OBJETO de outro escritório')]
    public function testRejeitaObjetoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        $objetoB = $this->criarObjeto($tenantB, ModoCarteira::Multiplo);
        $pessoaA = PessoaFactory::createOne(['tenant' => $tenantA]);

        $this->expectException(ObjetoNaoEncontradoException::class);

        $this->abrirCaso->executar($this->abrirInput($objetoB, $pessoaA), $tenantA, $user);
    }

    #[TestDox('Rejeita abrir caso com PESSOA de outro escritório')]
    public function testRejeitaPessoaDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        $objetoA = $this->criarObjeto($tenantA, ModoCarteira::Multiplo);
        $pessoaB = PessoaFactory::createOne(['tenant' => $tenantB]);

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->abrirCaso->executar($this->abrirInput($objetoA, $pessoaB), $tenantA, $user);
    }

    #[TestDox('Registrar obrigação não alcança caso de outro escritório (IDOR por tenant)')]
    public function testRegistrarObrigacaoNaoAlcancaCasoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        $objetoA = $this->criarObjeto($tenantA, ModoCarteira::Multiplo);
        $pessoaA = PessoaFactory::createOne(['tenant' => $tenantA]);
        $casoA = $this->abrirCaso->executar($this->abrirInput($objetoA, $pessoaA), $tenantA, $user);

        // Tenta registrar obrigação no caso do A usando o tenant B → caso não encontrado.
        $this->expectException(CasoNaoEncontradoException::class);

        $this->registrarObrigacao->executar($this->obrigacaoInput((int) $casoA->getId(), 5000), $tenantB, $user);
    }

    #[TestDox('Modo único rejeita um segundo caso ativo para o mesmo objeto')]
    public function testModoUnicoRejeitaSegundoCasoAtivo(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $objeto = $this->criarObjeto($tenant, ModoCarteira::Unico);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        $this->abrirCaso->executar($this->abrirInput($objeto, $pessoa), $tenant, $user);

        $this->expectException(CasoAtivoJaExisteException::class);

        $this->abrirCaso->executar($this->abrirInput($objeto, $pessoa), $tenant, $user);
    }

    #[TestDox('Alterar pessoa cobrada não aceita pessoa de outro escritório e não altera a atual')]
    public function testAlterarPessoaCobradaNaoAceitaPessoaDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        $objetoA = $this->criarObjeto($tenantA, ModoCarteira::Multiplo);
        $pessoaA = PessoaFactory::createOne(['tenant' => $tenantA]);
        $pessoaB = PessoaFactory::createOne(['tenant' => $tenantB]);
        $caso = $this->abrirCaso->executar($this->abrirInput($objetoA, $pessoaA), $tenantA, $user);

        $input = new AlterarPessoaCobradaInput();
        $input->casoId = (int) $caso->getId();
        $input->novaPessoaCobradaId = (int) $pessoaB->getId();
        $input->motivo = 'Tentativa cross-tenant';

        try {
            $this->alterarPessoaCobrada->executar($input, $tenantA, $user);
            self::fail('deveria rejeitar pessoa de outro escritório');
        } catch (PessoaNaoEncontradaException) {
            // A pessoa cobrada permanece a original (A) — nada mudou.
            self::assertSame($pessoaA->getId(), $caso->getPessoaCobradaAtual()?->getId());
        }
    }

    // ----------------------------------------------------------------- helpers

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

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant CASO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('caso_' . uniqid() . '@test.com');
        $user->setFullName('User Cobrança');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Objeto consistente com o tenant, com carteira no modo indicado (cliente credor no mesmo escritório). */
    private function criarObjeto(Tenant $tenant, ModoCarteira $modo): object
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => $modo,
        ]);

        return ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
    }
}
