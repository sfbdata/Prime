<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\DTO\CriarCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\ClienteCredorNaoEncontradoException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\UseCase\CriarCarteiraUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarCarteiraUseCase::class)]
final class CriarCarteiraUseCaseTest extends TestCase
{
    private CarteiraRepository&MockObject $carteiraRepository;
    private ClienteRepository&MockObject $clienteRepository;
    private CriarCarteiraUseCase $sut;
    private Tenant&MockObject $tenant;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->carteiraRepository = $this->createMock(CarteiraRepository::class);
        $this->clienteRepository = $this->createMock(ClienteRepository::class);
        $this->sut = new CriarCarteiraUseCase($this->carteiraRepository, $this->clienteRepository);
        $this->tenant = $this->createMock(Tenant::class);
        $this->user = $this->createMock(User::class);
    }

    #[Test]
    public function criaCarteiraComOsCamposDoInputQuandoCredorExisteNoTenant(): void
    {
        $cliente = $this->createStub(Cliente::class);

        // Guarda multi-tenant: o credor é resolvido por id + tenant do usuário.
        $this->clienteRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 42, 'tenant' => $this->tenant])
            ->willReturn($cliente);

        // Persistência com flush em uma única transação.
        $this->carteiraRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(Carteira::class), true);

        $carteira = $this->sut->executar($this->input(), $this->tenant, $this->user);

        self::assertSame('Condomínio Alfa', $carteira->getNome());
        self::assertSame($this->tenant, $carteira->getTenant());
        self::assertSame($cliente, $carteira->getCliente());
        self::assertSame(ModoCarteira::Multiplo, $carteira->getModo());
        self::assertSame(FormaHonorarios::AcrescidoDivida, $carteira->getFormaHonorarios());
        self::assertSame('10.00', $carteira->getPercentualHonorarios());
        self::assertSame(5, $carteira->getToleranciaAtrasoDias());
        self::assertSame(TipoVinculo::Proprietario, $carteira->getTipoVinculoPreferido());
        self::assertSame('Unidade', $carteira->getRotuloObjeto());
        self::assertSame($this->user, $carteira->getCriadoPor());
    }

    /**
     * A carteira precisa nascer JÁ configurada: o caso snapshota a config no instante em que é aberto
     * (spec §5), então todo caso criado antes de alguém abrir "Editar configuração" ficaria pinado em
     * 0% para sempre. Este teste prende os 9 campos de encargo no caminho da CRIAÇÃO.
     */
    #[Test]
    public function gravaOsNoveCamposDeEncargoNaCriacaoDaCarteira(): void
    {
        $this->clienteRepository->method('findOneBy')->willReturn($this->createStub(Cliente::class));

        $input = $this->input();
        $input->taxaJurosMensalBp = 100;
        $input->regimeJuros = RegimeJuros::Composto;
        $input->taxaMultaBp = 200;
        $input->baseMulta = BaseEncargo::Composta;
        $input->taxaCorrecaoBp = 50;
        $input->baseCorrecao = BaseEncargo::Composta;
        $input->baseHonorarios = BaseEncargo::Principal;
        $input->carenciaHonorariosDias = 30;
        $input->toleranciaJurosMultaDias = 3;

        $carteira = $this->sut->executar($input, $this->tenant, $this->user);

        self::assertSame(100, $carteira->getTaxaJurosMensalBp());
        self::assertSame(RegimeJuros::Composto, $carteira->getRegimeJuros());
        self::assertSame(200, $carteira->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $carteira->getBaseMulta());
        self::assertSame(50, $carteira->getTaxaCorrecaoBp());
        self::assertSame(BaseEncargo::Composta, $carteira->getBaseCorrecao());
        self::assertSame(BaseEncargo::Principal, $carteira->getBaseHonorarios());
        self::assertSame(30, $carteira->getCarenciaHonorariosDias());
        self::assertSame(3, $carteira->getToleranciaJurosMultaDias());
    }

    /**
     * Default não-breaking (decisão D4 do ledger): sem nada informado, a carteira nasce NEUTRA — taxas
     * zeradas, comportamento idêntico ao de antes da feature. Criar carteira não pode passar a gerar
     * encargo sozinho num módulo que já está em produção.
     */
    #[Test]
    public function carteiraNasceComEncargosNeutrosQuandoNadaEInformado(): void
    {
        $this->clienteRepository->method('findOneBy')->willReturn($this->createStub(Cliente::class));

        $carteira = $this->sut->executar($this->input(), $this->tenant, $this->user);

        self::assertSame(0, $carteira->getTaxaJurosMensalBp());
        self::assertSame(0, $carteira->getTaxaMultaBp());
        self::assertSame(0, $carteira->getTaxaCorrecaoBp());
        self::assertSame(RegimeJuros::Simples, $carteira->getRegimeJuros());
        self::assertSame(BaseEncargo::Principal, $carteira->getBaseMulta());
        self::assertSame(BaseEncargo::Principal, $carteira->getBaseCorrecao());
        self::assertSame(BaseEncargo::Composta, $carteira->getBaseHonorarios());
        // null = herda a tolerância de atraso da própria carteira; não vira 0 por acidente.
        self::assertNull($carteira->getCarenciaHonorariosDias());
        self::assertSame(0, $carteira->getToleranciaJurosMultaDias());
    }

    #[Test]
    public function rejeitaComExcecaoQuandoCredorNaoExisteNoTenantENaoSalva(): void
    {
        // Cliente inexistente OU de outro escritório: findOneBy(id + tenant) devolve null.
        $this->clienteRepository->method('findOneBy')->willReturn(null);
        $this->carteiraRepository->expects($this->never())->method('salvar');

        $this->expectException(ClienteCredorNaoEncontradoException::class);

        $this->sut->executar($this->input(), $this->tenant, $this->user);
    }

    private function input(): CriarCarteiraInput
    {
        $input = new CriarCarteiraInput();
        $input->nome = 'Condomínio Alfa';
        $input->clienteId = 42;
        $input->modo = ModoCarteira::Multiplo;
        $input->formaHonorarios = FormaHonorarios::AcrescidoDivida;
        $input->percentualHonorarios = '10.00';
        $input->toleranciaAtrasoDias = 5;
        $input->tipoVinculoPreferido = TipoVinculo::Proprietario;
        $input->rotuloObjeto = 'Unidade';

        return $input;
    }
}
