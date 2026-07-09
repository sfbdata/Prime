<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RegistrarLiquidacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoLiquidacao;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\RegistrarLiquidacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarLiquidacaoUseCase::class)]
final class RegistrarLiquidacaoUseCaseTest extends TestCase
{
    private LiquidacaoRepository&MockObject $liquidacaoRepository;
    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RegistrarLiquidacaoUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->liquidacaoRepository = $this->createMock(LiquidacaoRepository::class);
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        // O serviço é final (não-mockável): usa-se o REAL com o repositório de eventos mockado,
        // validando o flush único via a chamada salvar(EventoHistorico, true).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new RegistrarLiquidacaoUseCase(
            $this->liquidacaoRepository,
            $this->casoRepository,
            $registrarEvento,
        );
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function registraLiquidacaoComValorDoBemDistintoDoReconhecido(): void
    {
        // Caso ativo (status default) e com tenant — exigido pelo RegistrarEventoHistorico.
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(42, $this->tenant)
            ->willReturn($caso);

        // Liquidação persistida sem flush; o evento fecha a transação com flush: true.
        $this->liquidacaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Liquidacao::class));

        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $data = new \DateTimeImmutable('2026-04-15');
        $input = new RegistrarLiquidacaoInput();
        $input->casoId = 42;
        $input->tipo = TipoLiquidacao::BemMovel;
        $input->descricaoBem = '  Fiat Uno 2015 placa ABC-1234  ';
        // Regra central §11: bem vale 800000, mas só 500000 é reconhecido como extinto da dívida.
        $input->valorAtribuidoBem = 800000;
        $input->valorReconhecido = 500000;
        $input->data = $data;

        $liquidacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame(TipoLiquidacao::BemMovel, $liquidacao->getTipo());
        // Descrição normalizada (trim).
        self::assertSame('Fiat Uno 2015 placa ABC-1234', $liquidacao->getDescricaoBem());
        // Os dois valores são guardados DISTINTOS — nunca se força igualdade (§11).
        self::assertSame(800000, $liquidacao->getValorAtribuidoBem());
        self::assertSame(500000, $liquidacao->getValorReconhecido());
        self::assertSame($data, $liquidacao->getData());
        self::assertSame($this->tenant, $liquidacao->getTenant());
        self::assertSame($caso, $liquidacao->getCaso());
        self::assertSame($this->criadoPor, $liquidacao->getCriadoPor());
    }

    #[Test]
    public function registraLiquidacaoSemValorAtribuidoAoBem(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);

        $this->liquidacaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Liquidacao::class));

        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new RegistrarLiquidacaoInput();
        $input->casoId = 42;
        $input->tipo = TipoLiquidacao::Outro;
        $input->descricaoBem = 'Cessão de direito creditório';
        // Valor do bem é opcional: fica null, sem afetar o reconhecido que reduz o saldo.
        $input->valorAtribuidoBem = null;
        $input->valorReconhecido = 123456;
        $input->data = new \DateTimeImmutable('2026-04-20');

        $liquidacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertNull($liquidacao->getValorAtribuidoBem());
        self::assertSame(123456, $liquidacao->getValorReconhecido());
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->liquidacaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $input = new RegistrarLiquidacaoInput();
        $input->casoId = 999;
        $input->tipo = TipoLiquidacao::Dinheiro;
        $input->descricaoBem = 'Depósito';
        $input->valorReconhecido = 5000;
        $input->data = new \DateTimeImmutable('2026-04-15');

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function rejeitaLiquidacaoEmCasoEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->liquidacaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new RegistrarLiquidacaoInput();
        $input->casoId = 42;
        $input->tipo = TipoLiquidacao::BemImovel;
        $input->descricaoBem = 'Apartamento';
        $input->valorAtribuidoBem = 20000000;
        $input->valorReconhecido = 15000000;
        $input->data = new \DateTimeImmutable('2026-04-15');

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }
}
