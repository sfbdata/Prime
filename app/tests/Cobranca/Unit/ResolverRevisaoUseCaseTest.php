<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ResolverRevisaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\RevisaoPessoaCobrada;
use App\Cobranca\Enum\StatusRevisao;
use App\Cobranca\Exception\RevisaoJaResolvidaException;
use App\Cobranca\Exception\RevisaoNaoEncontradaException;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\ResolverRevisaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolverRevisaoUseCase::class)]
final class ResolverRevisaoUseCaseTest extends TestCase
{
    private RevisaoPessoaCobradaRepository&MockObject $revisaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private ResolverRevisaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->revisaoRepository = $this->createMock(RevisaoPessoaCobradaRepository::class);
        // O serviço é final: usa-se o REAL com o repositório de eventos mockado (flush único).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new ResolverRevisaoUseCase($this->revisaoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function resolveRevisaoPendenteComEvento(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $revisao = (new RevisaoPessoaCobrada())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setMotivo('Mudança de proprietário');

        $this->revisaoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(70, $this->tenant)
            ->willReturn($revisao);

        // Revisão persistida sem flush; o evento fecha a transação com flush: true.
        $this->revisaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(RevisaoPessoaCobrada::class));
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new ResolverRevisaoInput();
        $input->revisaoId = 70;
        $input->resolucao = 'Manter a pessoa cobrada atual';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($revisao, $resultado);
        // Depois de resolvida, cessa o alerta (§8).
        self::assertFalse($resultado->estaPendente());
        self::assertSame(StatusRevisao::Resolvida, $resultado->getStatus());
        self::assertSame('Manter a pessoa cobrada atual', $resultado->getResolucao());
        self::assertSame($this->usuario, $resultado->getResolvidaPor());
    }

    #[Test]
    public function rejeitaRevisaoJaResolvida(): void
    {
        // Revisão já resolvida não transiciona de novo (resolução é única — §8).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $revisao = (new RevisaoPessoaCobrada())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setMotivo('Mudança de proprietário');
        $revisao->resolver('Já decidido antes', $this->usuario);

        $this->revisaoRepository->method('findOneByIdDoTenant')->willReturn($revisao);
        $this->revisaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(RevisaoJaResolvidaException::class);

        $input = new ResolverRevisaoInput();
        $input->revisaoId = 70;
        $input->resolucao = 'Nova tentativa';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaRevisaoNaoEncontrada(): void
    {
        // Revisão inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->revisaoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->revisaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(RevisaoNaoEncontradaException::class);

        $input = new ResolverRevisaoInput();
        $input->revisaoId = 999;
        $input->resolucao = 'Qualquer';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
