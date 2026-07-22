<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RegistrarAnotacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\RegistrarAnotacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarAnotacaoUseCase::class)]
final class RegistrarAnotacaoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RegistrarAnotacaoUseCase $sut;
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // O serviço é final (não mockável): usa-se o REAL com o repositório de eventos mockado.
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new RegistrarAnotacaoUseCase($this->casoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    #[TestDox('Grava a anotação como evento do tipo Anotacao, com o texto do usuário e flush próprio')]
    public function registraAnotacaoNoHistorico(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        // Guarda multi-tenant: o caso é resolvido por id + tenant do usuário.
        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(12, $this->tenant)
            ->willReturn($caso);

        $capturado = null;
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            // A anotação é a única escrita do UseCase: flush acontece aqui (true).
            ->with($this->callback(static function (EventoHistorico $e) use (&$capturado): bool {
                $capturado = $e;

                return true;
            }), true);

        $input = new RegistrarAnotacaoInput();
        $input->casoId = 12;
        $input->texto = 'Sindico confirmou que o lote foi vendido em 2024.';

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertInstanceOf(EventoHistorico::class, $capturado);
        self::assertSame(TipoEventoHistorico::Anotacao, $capturado->getTipo());
        self::assertSame('Sindico confirmou que o lote foi vendido em 2024.', $capturado->getDescricao());
        self::assertSame($this->usuario, $capturado->getUsuario(), 'a autoria é do usuário logado — é o que o relatório do gestor lê');
        self::assertSame($this->tenant, $capturado->getTenant());
        self::assertNull($capturado->getDados(), 'anotação não tem payload estruturado — é texto e só');
    }

    #[Test]
    #[TestDox('Espaço e quebra de linha em volta do texto não são gravados')]
    public function normalizaOTextoAntesDeGravar(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);

        $capturado = null;
        $this->eventoRepository
            ->method('salvar')
            ->with($this->callback(static function (EventoHistorico $e) use (&$capturado): bool {
                $capturado = $e;

                return true;
            }), true);

        $input = new RegistrarAnotacaoInput();
        $input->casoId = 12;
        $input->texto = "   Devedor pediu boleto por e-mail.\n\n  ";

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame('Devedor pediu boleto por e-mail.', $capturado->getDescricao());
    }

    #[Test]
    #[TestDox('Caso de outro escritório (ou inexistente) não recebe anotação')]
    public function casoDeOutroTenantNaoRecebeAnotacao(): void
    {
        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(99, $this->tenant)
            ->willReturn(null);

        // Nenhuma escrita pode acontecer quando o caso não é do tenant (anti-IDOR).
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new RegistrarAnotacaoInput();
        $input->casoId = 99;
        $input->texto = 'tentativa cross-tenant';

        $this->expectException(CasoNaoEncontradoException::class);

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    #[TestDox('Caso encerrado não aceita anotação — o ciclo daquele caso terminou')]
    public function casoEncerradoNaoAceitaAnotacao(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new RegistrarAnotacaoInput();
        $input->casoId = 12;
        $input->texto = 'anotação em caso encerrado';

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
