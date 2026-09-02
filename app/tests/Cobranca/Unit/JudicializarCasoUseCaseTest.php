<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoJaJudicializadoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PastaNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cliente\Repository\ClientePFRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\ResolvedorClienteDoResponsavel;
use App\Cobranca\UseCase\JudicializarCasoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\GerarNumeroDePasta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(JudicializarCasoUseCase::class)]
final class JudicializarCasoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private PastaRepository&MockObject $pastaRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private EntityManagerInterface&MockObject $em;
    private JudicializarCasoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->pastaRepository = $this->createMock(PastaRepository::class);
        // O serviço é final: usa-se o REAL com o repositório de eventos mockado.
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        // CriarPastaUseCase e ResolvedorClienteDoResponsavel também são final: entram REAIS, com as
        // dependências mockadas. Nos casos deste arquivo (modo `vincular` e as três guardas) eles não
        // devem ser chamados — e é justamente isso que `wrapInTransaction` never prova.
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sut = new JudicializarCasoUseCase(
            $this->casoRepository,
            $this->pastaRepository,
            $registrarEvento,
            new CriarPastaUseCase($this->em, new GerarNumeroDePasta($this->createMock(NumeracaoDePastaInterface::class))),
            new ResolvedorClienteDoResponsavel($this->createMock(ClientePFRepository::class)),
            new ComporNomeDaPastaJudicial(),
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function judicializaCasoAtivoVinculandoPastaComDoisEventos(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $pasta = (new Pasta())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(50, $this->tenant)
            ->willReturn($caso);

        // Guarda multi-tenant da pasta: resolvida por id + tenant.
        $this->pastaRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 70, 'tenant' => $this->tenant])
            ->willReturn($pasta);

        $this->casoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(CasoCobranca::class));

        // Dois eventos: judicialização (sem flush) e vínculo com pasta (fecha a transação, flush true).
        $flushes = [];
        $this->eventoRepository
            ->expects($this->exactly(2))
            ->method('salvar')
            ->willReturnCallback(function (EventoHistorico $evento, bool $flush) use (&$flushes): void {
                $flushes[] = $flush;
            });

        $input = new JudicializarCasoInput();
        $input->casoId = 50;
        $input->modo = JudicializarCasoInput::MODO_VINCULAR;
        $input->pastaId = 70;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($caso, $resultado);
        // Judicializar NÃO encerra: status vira Judicializado, jamais Encerrado (invariável 16).
        self::assertSame(StatusCaso::Judicializado, $resultado->getStatus());
        self::assertFalse($resultado->estaEncerrado());
        self::assertSame($pasta, $resultado->getPastaJudicial());
        self::assertSame([false, true], $flushes);
    }

    #[Test]
    public function rejeitaCasoNaoEncontrado(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->pastaRepository->expects($this->never())->method('findOneBy');
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $input = new JudicializarCasoInput();
        $input->casoId = 999;
        $input->modo = JudicializarCasoInput::MODO_VINCULAR;
        $input->pastaId = 70;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->pastaRepository->expects($this->never())->method('findOneBy');
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new JudicializarCasoInput();
        $input->casoId = 50;
        $input->modo = JudicializarCasoInput::MODO_VINCULAR;
        $input->pastaId = 70;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoJaJudicializado(): void
    {
        // Judicialização é transição única (SPEC §16); revincular não é operação do MVP.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Judicializado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->pastaRepository->expects($this->never())->method('findOneBy');
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoJaJudicializadoException::class);

        $input = new JudicializarCasoInput();
        $input->casoId = 50;
        $input->modo = JudicializarCasoInput::MODO_VINCULAR;
        $input->pastaId = 70;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaPastaDeOutroTenantOuInexistente(): void
    {
        // Guarda crítica multi-tenant: pasta de outro escritório (ou inexistente) devolve null e nada é salvo.
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->pastaRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 70, 'tenant' => $this->tenant])
            ->willReturn(null);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(PastaNaoEncontradaException::class);

        $input = new JudicializarCasoInput();
        $input->casoId = 50;
        $input->modo = JudicializarCasoInput::MODO_VINCULAR;
        $input->pastaId = 70;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function noModoCriarAsGuardasRodamAntesDeAbrirAPasta(): void
    {
        // Ordem que importa: criar a pasta de um caso encerrado e só depois recusar deixaria uma
        // pasta órfã no acervo a cada clique errado. `wrapInTransaction` never prova que nada foi
        // aberto — é por ele que o CriarPastaUseCase passa antes de gravar qualquer coisa.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->em->expects($this->never())->method('wrapInTransaction');
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new JudicializarCasoInput();
        $input->casoId = 50;
        $input->modo = JudicializarCasoInput::MODO_CRIAR;
        $input->nomeCliente = 'FULANO DE TAL';
        $input->nomeAcao = JudicializarCasoInput::ACAO_PADRAO;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function oHistoricoDizQueAPastaJaExistiaQuandoElaFoiApenasVinculada(): void
    {
        // O histórico tem de distinguir pasta CRIADA de pasta VINCULADA: se ela depois aparecer
        // errada, a consequência é diferente em cada caso.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $pasta = (new Pasta())->setTenant($this->tenant);
        $pasta->setNup('1232');

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->pastaRepository->method('findOneBy')->willReturn($pasta);

        $mensagens = [];
        $this->eventoRepository
            ->method('salvar')
            ->willReturnCallback(function (EventoHistorico $evento) use (&$mensagens): void {
                $mensagens[] = $evento->getDescricao();
            });

        $input = new JudicializarCasoInput();
        $input->casoId = 50;
        $input->modo = JudicializarCasoInput::MODO_VINCULAR;
        $input->pastaId = 70;

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(['Caso judicializado.', 'Vínculo com a pasta 1232.'], $mensagens);
    }
}
