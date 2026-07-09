<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarObrigacaoUseCase::class)]
final class RegistrarObrigacaoUseCaseTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RegistrarObrigacaoUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        // O serviço é final (não-mockável): usa-se o REAL com o repositório de eventos mockado,
        // validando o flush único via a chamada salvar(EventoHistorico, true).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new RegistrarObrigacaoUseCase(
            $this->obrigacaoRepository,
            $this->casoRepository,
            $registrarEvento,
        );
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function registraObrigacaoNoCasoAtivoComEvento(): void
    {
        // Caso ativo (status default) e com tenant — exigido pelo RegistrarEventoHistorico.
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(30, $this->tenant)
            ->willReturn($caso);

        // Obrigação persistida sem flush; o evento fecha a transação com flush: true.
        $this->obrigacaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Obrigacao::class));

        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $vencimento = new \DateTimeImmutable('2026-03-10');
        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = '  Aluguel março/2026  ';
        $input->valorOriginal = 150000;
        $input->vencimentoOriginal = $vencimento;
        $input->referenciaExterna = 'EXT-77';

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        // Descrição normalizada (trim).
        self::assertSame('Aluguel março/2026', $obrigacao->getDescricao());
        self::assertSame(150000, $obrigacao->getValorOriginal());
        self::assertSame($vencimento, $obrigacao->getVencimentoOriginal());
        self::assertSame($this->tenant, $obrigacao->getTenant());
        self::assertSame($caso, $obrigacao->getCaso());
        self::assertSame($this->criadoPor, $obrigacao->getCriadoPor());
        self::assertSame('EXT-77', $obrigacao->getReferenciaExterna());
        // Encargos nascem zerados; valor original preservado (invariável 20).
        self::assertSame(0, $obrigacao->getEncargosReconhecidos());
    }

    #[Test]
    public function rejeitaObrigacaoEmCasoEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Taxa';
        $input->valorOriginal = 5000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2026-03-10');

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->obrigacaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 999;
        $input->descricao = 'Taxa';
        $input->valorOriginal = 5000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2026-03-10');

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function referenciaExternaEmBrancoViraNull(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Multa';
        $input->valorOriginal = 2000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2026-03-10');
        // Referência só com espaços: a normalização a transforma em null.
        $input->referenciaExterna = '  ';

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertNull($obrigacao->getReferenciaExterna());
    }
}
