<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ReconhecerValorAtualizadoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\ReconhecerValorAtualizadoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReconhecerValorAtualizadoUseCase::class)]
final class ReconhecerValorAtualizadoUseCaseTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private ReconhecerValorAtualizadoUseCase $sut;
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // O serviço é final (não mockável): usa-se o REAL com o repositório de eventos mockado.
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new ReconhecerValorAtualizadoUseCase($this->obrigacaoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function reconheceEncargosSemCriarObrigacaoERegistraEvento(): void
    {
        $obrigacao = (new Obrigacao())
            ->setCaso((new CasoCobranca())->setTenant($this->tenant))
            ->setValorOriginal(10000);

        // Guarda multi-tenant: a obrigação é resolvida por id + tenant do usuário.
        $this->obrigacaoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(55, $this->tenant)
            ->willReturn($obrigacao);

        // O flush do evento commita, na mesma transação, a alteração da obrigação managed.
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);
        // NÃO cria obrigação nova: nunca persiste pelo repositório de obrigações.
        $this->obrigacaoRepository->expects($this->never())->method('salvar');

        $input = new ReconhecerValorAtualizadoInput();
        $input->obrigacaoId = 55;
        $input->encargosReconhecidos = 1500;
        $input->motivo = 'Juros e multa de mora.';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($obrigacao, $resultado);
        self::assertSame(1500, $resultado->getEncargosReconhecidos());
        // Valor original preservado (invariável 20) e valor exigível = original + encargos.
        self::assertSame(10000, $resultado->getValorOriginal());
        self::assertSame(11500, $resultado->valorExigivel());
    }

    #[Test]
    public function rejeitaObrigacaoDeOutroTenant(): void
    {
        // Obrigação inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new ReconhecerValorAtualizadoInput();
        $input->obrigacaoId = 999;
        $input->encargosReconhecidos = 500;

        $this->expectException(ObrigacaoNaoEncontradaException::class);

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
