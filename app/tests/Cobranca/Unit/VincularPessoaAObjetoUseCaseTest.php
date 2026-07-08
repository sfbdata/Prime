<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\VincularPessoaAObjetoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(VincularPessoaAObjetoUseCase::class)]
final class VincularPessoaAObjetoUseCaseTest extends TestCase
{
    private VinculoPessoaObjetoRepository&MockObject $vinculoRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private ObjetoCobrancaRepository&MockObject $objetoRepository;
    private VincularPessoaAObjetoUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->vinculoRepository = $this->createMock(VinculoPessoaObjetoRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->objetoRepository = $this->createMock(ObjetoCobrancaRepository::class);
        $this->sut = new VincularPessoaAObjetoUseCase(
            $this->vinculoRepository,
            $this->pessoaRepository,
            $this->objetoRepository,
        );
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function criaVinculoAbertoQuandoPessoaEObjetoSaoDoProprioTenant(): void
    {
        $pessoa = new Pessoa();
        $objeto = new ObjetoCobranca();

        $this->pessoaRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(7, $this->tenant)
            ->willReturn($pessoa);

        $this->objetoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(13, $this->tenant)
            ->willReturn($objeto);

        $this->vinculoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(VinculoPessoaObjeto::class), true);

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = 7;
        $input->objetoId = 13;
        $input->tipoVinculo = TipoVinculo::Inquilino;
        $input->dataInicio = new \DateTimeImmutable('2026-01-10');
        $input->observacao = '  contrato de locação  ';

        $vinculo = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame($this->tenant, $vinculo->getTenant());
        self::assertSame($pessoa, $vinculo->getPessoa());
        self::assertSame($objeto, $vinculo->getObjeto());
        self::assertSame(TipoVinculo::Inquilino, $vinculo->getTipoVinculo());
        self::assertEquals(new \DateTimeImmutable('2026-01-10'), $vinculo->getDataInicio());
        self::assertSame('contrato de locação', $vinculo->getObservacao());
        self::assertSame($this->criadoPor, $vinculo->getCriadoPor());
        self::assertTrue($vinculo->estaAberto());
    }

    #[Test]
    public function usaDataDeHojeQuandoDataInicioNaoInformada(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(new Pessoa());
        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn(new ObjetoCobranca());
        $this->vinculoRepository->expects($this->once())->method('salvar');

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = 1;
        $input->objetoId = 2;

        $vinculo = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            $vinculo->getDataInicio()->format('Y-m-d'),
        );
        self::assertNull($vinculo->getObservacao());
    }

    #[Test]
    public function rejeitaObjetoDeOutroTenantENaoSalva(): void
    {
        // Guarda cross-tenant: objeto de outro escritório não é encontrado por (id, tenant).
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(new Pessoa());
        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObjetoNaoEncontradoException::class);

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = 7;
        $input->objetoId = 999;

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function rejeitaPessoaInexistenteSemBuscarObjetoNemSalvar(): void
    {
        // Pessoa inexistente/de outro tenant: falha antes de tocar no objeto.
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->objetoRepository->expects($this->never())->method('findOneByIdDoTenant');
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = 999;
        $input->objetoId = 13;

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }
}
