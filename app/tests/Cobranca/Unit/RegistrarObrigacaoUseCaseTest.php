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
        // Sem encargos digitados a obrigação segue sob o cron: congelar aqui tiraria do cálculo
        // automático toda obrigação criada à mão, sem UI de descongelar (INV-E4).
        self::assertFalse($obrigacao->encargosCongelados());
    }

    /**
     * Uma dívida trazida de outro sistema já vem com os encargos calculados lá fora. O gestor os digita
     * no lançamento; a partir daí eles são a verdade e o cron não pode passar por cima na madrugada
     * seguinte (spec §8, INV-E4) — mesma regra da edição manual.
     */
    #[Test]
    public function encargosInformadosNoLancamentoNascemSeparadosECongelados(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);

        $capturado = null;
        $this->eventoRepository->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e, bool $flush) use (&$capturado): void {
                $capturado = $e;
            });

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Dívida antiga importada à mão';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2025-03-10');
        $input->juros = 5000;
        $input->multa = 2000;
        $input->correcao = 500;

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        // Cada componente no seu campo (nada de agregar tudo em `juros`).
        self::assertSame(5000, $obrigacao->getJuros());
        self::assertSame(2000, $obrigacao->getMulta());
        self::assertSame(500, $obrigacao->getCorrecao());
        self::assertSame(7500, $obrigacao->getEncargosReconhecidos(), 'o agregado é o derivado (INV-E1)');
        self::assertSame(107500, $obrigacao->valorExigivel());
        // Honorários NÃO são digitados neste form: nascem zerados, materializados pelo motor depois.
        self::assertSame(0, $obrigacao->getHonorarios());
        self::assertTrue($obrigacao->encargosCongelados(), 'valor digitado por gente não é sobrescrito pelo cron');

        // O histórico precisa explicar de onde veio esse dinheiro e por que a obrigação já nasceu presa.
        self::assertInstanceOf(EventoHistorico::class, $capturado);
        $dados = $capturado->getDados();
        self::assertSame(5000, $dados['juros']);
        self::assertSame(2000, $dados['multa']);
        self::assertSame(500, $dados['correcao']);
    }

    #[Test]
    public function encargosZeradosNaoCongelamAObrigacao(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Boleto novo';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2026-03-10');
        // Os três explicitamente em zero: é o default do form quando o gestor não preenche nada.
        $input->juros = 0;
        $input->multa = 0;
        $input->correcao = 0;

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertFalse(
            $obrigacao->encargosCongelados(),
            'zero não é "valor digitado": a obrigação nova tem de continuar sendo calculada pelo cron',
        );
        self::assertNull($obrigacao->getEncargosAtualizadosEm(), 'sem encargos informados não há materialização a datar');
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
