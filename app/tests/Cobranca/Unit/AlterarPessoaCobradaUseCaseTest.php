<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AlterarPessoaCobradaInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\AlterarPessoaCobradaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarPessoaCobradaUseCase::class)]
final class AlterarPessoaCobradaUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private AlterarPessoaCobradaUseCase $sut;
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // O serviço é final (não mockável): usa-se o REAL com o repositório de eventos mockado.
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new AlterarPessoaCobradaUseCase(
            $this->casoRepository,
            $this->pessoaRepository,
            $registrarEvento,
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function alteraPessoaCobradaERegistraEvento(): void
    {
        $anterior = new Pessoa();
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setPessoaCobradaAtual($anterior);
        $novaPessoa = new Pessoa();

        // Guarda multi-tenant: caso e nova pessoa resolvidos por id + tenant do usuário.
        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(3, $this->tenant)
            ->willReturn($caso);
        $this->pessoaRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(9, $this->tenant)
            ->willReturn($novaPessoa);

        // O flush do evento commita, na mesma transação, a troca da pessoa cobrada.
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new AlterarPessoaCobradaInput();
        $input->casoId = 3;
        $input->novaPessoaCobradaId = 9;
        $input->motivo = '  Transferência de titularidade  ';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($caso, $resultado);
        self::assertSame($novaPessoa, $resultado->getPessoaCobradaAtual());
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        // Nem chega a resolver a pessoa nem a registrar evento.
        $this->pessoaRepository->expects($this->never())->method('findOneByIdDoTenant');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new AlterarPessoaCobradaInput();
        $input->casoId = 999;
        $input->novaPessoaCobradaId = 9;
        $input->motivo = 'x';

        $this->expectException(CasoNaoEncontradoException::class);

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaNovaPessoaDeOutroTenant(): void
    {
        $anterior = new Pessoa();
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setPessoaCobradaAtual($anterior);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        // Nova pessoa inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new AlterarPessoaCobradaInput();
        $input->casoId = 3;
        $input->novaPessoaCobradaId = 999;
        $input->motivo = 'x';

        try {
            $this->sut->executar($input, $this->tenant, $this->usuario);
            self::fail('Esperava PessoaNaoEncontradaException.');
        } catch (PessoaNaoEncontradaException) {
            // A troca nunca chega a acontecer: a pessoa cobrada permanece a anterior.
            self::assertSame($anterior, $caso->getPessoaCobradaAtual());
        }
    }
}
