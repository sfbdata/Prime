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
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->objetoRepository = $this->createMock(ObjetoCobrancaRepository::class);
        $this->carteiraRepository = $this->createMock(CarteiraRepository::class);
        $this->sut = new CriarObjetoUseCase($this->objetoRepository, $this->carteiraRepository);
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function criaObjetoQuandoCarteiraExisteNoTenant(): void
    {
        $carteira = new Carteira();

        // Guarda multi-tenant: a carteira é resolvida por id + tenant do usuário.
        $this->carteiraRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(7, $this->tenant)
            ->willReturn($carteira);

        // Persistência com flush em uma única transação.
        $this->objetoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(ObjetoCobranca::class), true);

        $input = new CriarObjetoInput();
        $input->carteiraId = 7;
        $input->identificacao = 'Apto 402';
        $input->descricao = 'Cobertura com dois quartos';
        $input->referenciaExterna = 'IMP-402';

        $objeto = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame('Apto 402', $objeto->getIdentificacao());
        self::assertSame('Cobertura com dois quartos', $objeto->getDescricao());
        self::assertSame('IMP-402', $objeto->getReferenciaExterna());
        self::assertSame($this->tenant, $objeto->getTenant());
        self::assertSame($carteira, $objeto->getCarteira());
        self::assertSame($this->criadoPor, $objeto->getCriadoPor());
    }

    #[Test]
    public function rejeitaQuandoCarteiraNaoExisteNoTenant(): void
    {
        // Carteira inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->carteiraRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->objetoRepository->expects($this->never())->method('salvar');

        $this->expectException(CarteiraNaoEncontradaException::class);

        $input = new CriarObjetoInput();
        $input->carteiraId = 999;
        $input->identificacao = 'Apto 402';

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function descricaoEReferenciaEmBrancoViramNull(): void
    {
        $this->carteiraRepository
            ->method('findOneByIdDoTenant')
            ->willReturn(new Carteira());

        $this->objetoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(ObjetoCobranca::class), true);

        $input = new CriarObjetoInput();
        $input->carteiraId = 7;
        $input->identificacao = 'Apto 402';
        // Em branco: a normalização os transforma em null.
        $input->descricao = '   ';
        $input->referenciaExterna = '';

        $objeto = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame('Apto 402', $objeto->getIdentificacao());
        self::assertNull($objeto->getDescricao());
        self::assertNull($objeto->getReferenciaExterna());
    }
}
