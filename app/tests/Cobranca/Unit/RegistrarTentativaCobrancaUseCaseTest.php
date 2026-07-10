<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\RegistrarTentativaCobrancaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarTentativaCobrancaUseCase::class)]
final class RegistrarTentativaCobrancaUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RegistrarTentativaCobrancaUseCase $sut;
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // O serviço é final (não mockável): usa-se o REAL com o repositório de eventos mockado.
        // Prova indireta de que NÃO cria obrigação: não há ObrigacaoRepository entre as dependências.
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new RegistrarTentativaCobrancaUseCase($this->casoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function registraTentativaSoNoHistoricoComValorEPrazo(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        // Guarda multi-tenant: o caso é resolvido por id + tenant do usuário.
        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(12, $this->tenant)
            ->willReturn($caso);

        // Evento é a ÚNICA escrita: salva 1x com flush.
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new RegistrarTentativaCobrancaInput();
        $input->casoId = 12;
        $input->valorSolicitado = 12000;
        $input->novoPrazo = new \DateTimeImmutable('2026-05-10');
        $input->observacao = '  Boleto reenviado com valor atualizado  ';

        $evento = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertInstanceOf(EventoHistorico::class, $evento);
        self::assertSame(TipoEventoHistorico::BoletoEnviado, $evento->getTipo());
        self::assertSame($caso, $evento->getCaso());
        self::assertSame('Boleto reenviado com valor atualizado', $evento->getDescricao());
        self::assertSame(
            ['valorSolicitado' => 12000, 'novoPrazo' => '2026-05-10'],
            $evento->getDados(),
        );
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new RegistrarTentativaCobrancaInput();
        $input->casoId = 999;

        $this->expectException(CasoNaoEncontradoException::class);

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaTentativaEmCasoEncerrado(): void
    {
        // Caso encerrado não aceita novos movimentos (SPEC §17): não muta o histórico.
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new RegistrarTentativaCobrancaInput();
        $input->casoId = 12;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
