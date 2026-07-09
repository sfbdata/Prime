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
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
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
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function criaVinculoQuandoPessoaEObjetoExistemNoTenant(): void
    {
        $pessoa = new Pessoa();
        $objeto = new ObjetoCobranca();
        $dataInicio = new \DateTimeImmutable('2026-01-15');

        // Guarda same-tenant: pessoa e objeto são resolvidos por id + tenant da operação.
        $this->pessoaRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(10, $this->tenant)
            ->willReturn($pessoa);

        $this->objetoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(20, $this->tenant)
            ->willReturn($objeto);

        $this->vinculoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(VinculoPessoaObjeto::class), true);

        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = 10;
        $input->objetoId = 20;
        $input->tipoVinculo = TipoVinculo::Inquilino;
        $input->dataInicio = $dataInicio;
        $input->observacao = '  Contrato até 2027  ';

        $vinculo = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame($this->tenant, $vinculo->getTenant());
        self::assertSame($pessoa, $vinculo->getPessoa());
        self::assertSame($objeto, $vinculo->getObjeto());
        self::assertSame(TipoVinculo::Inquilino, $vinculo->getTipoVinculo());
        self::assertSame($dataInicio, $vinculo->getDataInicio());
        self::assertSame('Contrato até 2027', $vinculo->getObservacao());
        self::assertSame($this->criadoPor, $vinculo->getCriadoPor());
        // Vínculo nasce aberto: a data final só é gravada num evento de encerramento.
        self::assertTrue($vinculo->estaAberto());
    }

    #[Test]
    public function rejeitaQuandoPessoaNaoExisteNoTenant(): void
    {
        // Pessoa inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(), $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function rejeitaQuandoObjetoNaoExisteNoTenant(): void
    {
        // Pessoa existe, mas o objeto é inexistente ou de outro escritório.
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(new Pessoa());
        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObjetoNaoEncontradoException::class);

        $this->sut->executar($this->input(), $this->tenant, $this->criadoPor);
    }

    private function input(): VincularPessoaAObjetoInput
    {
        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = 10;
        $input->objetoId = 20;

        return $input;
    }
}
