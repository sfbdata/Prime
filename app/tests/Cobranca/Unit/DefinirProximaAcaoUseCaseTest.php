<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\DefinirProximaAcaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\StatusProximaAcao;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ProximaAcaoAtivaJaExisteException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\UseCase\DefinirProximaAcaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinirProximaAcaoUseCase::class)]
final class DefinirProximaAcaoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private ProximaAcaoRepository&MockObject $proximaAcaoRepository;
    private DefinirProximaAcaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->proximaAcaoRepository = $this->createMock(ProximaAcaoRepository::class);
        $this->sut = new DefinirProximaAcaoUseCase($this->casoRepository, $this->proximaAcaoRepository);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function defineProximaAcaoQuandoNaoHaAtiva(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(10, $this->tenant)
            ->willReturn($caso);

        // Não há ação pendente no caso: o limite de 1 (§14) está livre.
        $this->proximaAcaoRepository
            ->expects($this->once())
            ->method('findAtivaDoCaso')
            ->with($caso)
            ->willReturn(null);

        // A ação nasce pendente e é commitada (flush único).
        $this->proximaAcaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(ProximaAcao::class), true);

        $prazo = new \DateTimeImmutable('2026-08-01');
        $input = new DefinirProximaAcaoInput();
        $input->casoId = 10;
        $input->descricao = 'Verificar pagamento do boleto';
        $input->prazo = $prazo;

        $acao = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(StatusProximaAcao::Pendente, $acao->getStatus());
        self::assertSame('Verificar pagamento do boleto', $acao->getDescricao());
        self::assertSame($prazo, $acao->getPrazo());
        self::assertSame($caso, $acao->getCaso());
        self::assertSame($this->tenant, $acao->getTenant());
        self::assertSame($this->usuario, $acao->getResponsavel());
        self::assertSame($this->usuario, $acao->getCriadoPor());
    }

    #[Test]
    public function rejeitaQuandoJaExisteAcaoAtiva(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $ativa = (new ProximaAcao())->setTenant($this->tenant)->setCaso($caso);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        // Já existe ação pendente: viola o limite de 1 por caso (§14).
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->with($caso)->willReturn($ativa);
        $this->proximaAcaoRepository->expects($this->never())->method('salvar');

        $this->expectException(ProximaAcaoAtivaJaExisteException::class);

        $input = new DefinirProximaAcaoInput();
        $input->casoId = 10;
        $input->descricao = 'Contatar devedor';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoEncerrado(): void
    {
        // Caso encerrado não recebe novas ações (SPEC §17).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->proximaAcaoRepository->expects($this->never())->method('findAtivaDoCaso');
        $this->proximaAcaoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new DefinirProximaAcaoInput();
        $input->casoId = 10;
        $input->descricao = 'Preparar ajuizamento';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoNaoEncontrado(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->proximaAcaoRepository->expects($this->never())->method('findAtivaDoCaso');
        $this->proximaAcaoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $input = new DefinirProximaAcaoInput();
        $input->casoId = 999;
        $input->descricao = 'Qualquer';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
