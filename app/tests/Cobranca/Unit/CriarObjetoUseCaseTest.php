<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\UseCase\CriarObjetoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarObjetoUseCase::class)]
final class CriarObjetoUseCaseTest extends TestCase
{
    private ObjetoCobrancaRepository&MockObject $objetoRepository;
    private CarteiraRepository&MockObject $carteiraRepository;
    private CriarObjetoUseCase $sut;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->objetoRepository = $this->createMock(ObjetoCobrancaRepository::class);
        $this->carteiraRepository = $this->createMock(CarteiraRepository::class);
        $this->sut = new CriarObjetoUseCase($this->objetoRepository, $this->carteiraRepository);
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function criaObjetoNaCarteiraDoTenantComOsCamposDoInput(): void
    {
        $carteira = new Carteira();

        // Guarda multi-tenant: a carteira é resolvida por id + tenant do usuário.
        $this->carteiraRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(5, $this->tenant)
            ->willReturn($carteira);

        // Persistência com flush em uma única transação.
        $this->objetoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(ObjetoCobranca::class), true);

        $objeto = $this->sut->executar($this->input(), $this->tenant, $this->user);

        self::assertSame($this->tenant, $objeto->getTenant());
        self::assertSame($carteira, $objeto->getCarteira());
        self::assertSame('Apto 402', $objeto->getIdentificacao());
        self::assertSame('Cobertura frontal', $objeto->getDescricao());
        self::assertSame('EXT-0001', $objeto->getReferenciaExterna());
        self::assertSame($this->user, $objeto->getCriadoPor());
    }

    #[Test]
    public function normalizaTextosEmBrancoParaNull(): void
    {
        $this->carteiraRepository->method('findOneByIdDoTenant')->willReturn(new Carteira());
        $this->objetoRepository->expects($this->once())->method('salvar');

        $input = new CriarObjetoInput();
        $input->carteiraId = 5;
        $input->identificacao = '  Apto 402  ';
        $input->descricao = '   ';
        $input->referenciaExterna = '   ';

        $objeto = $this->sut->executar($input, $this->tenant, $this->user);

        self::assertSame('Apto 402', $objeto->getIdentificacao());
        self::assertNull($objeto->getDescricao());
        self::assertNull($objeto->getReferenciaExterna());
    }

    #[Test]
    public function rejeitaComExcecaoQuandoCarteiraNaoExisteNoTenantENaoSalva(): void
    {
        // Carteira inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->carteiraRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->objetoRepository->expects($this->never())->method('salvar');

        $this->expectException(CarteiraNaoEncontradaException::class);

        $this->sut->executar($this->input(), $this->tenant, $this->user);
    }

    private function input(): CriarObjetoInput
    {
        $input = new CriarObjetoInput();
        $input->carteiraId = 5;
        $input->identificacao = 'Apto 402';
        $input->descricao = 'Cobertura frontal';
        $input->referenciaExterna = 'EXT-0001';

        return $input;
    }
}
