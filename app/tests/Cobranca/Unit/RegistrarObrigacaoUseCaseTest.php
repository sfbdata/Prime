<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
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
        // CalculadoraEncargos e ResolvedorConfigEncargos são `final` mas PUROS (sem I/O): usa-se o REAL,
        // como o RegistrarEventoHistorico — mockar um motor de dinheiro esconderia justamente o cálculo.
        $this->sut = new RegistrarObrigacaoUseCase(
            $this->obrigacaoRepository,
            $this->casoRepository,
            $registrarEvento,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            // Task 7 (spec taxa-por-obrigacao): ConversorTaxaEncargo também é PURO (sem I/O) — real, igual
            // aos outros dois serviços do motor.
            new ConversorTaxaEncargo(new CalculadoraEncargos()),
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
     * F6: sem digitar encargos, a obrigação é AUTOMÁTICA. Com carteira TOPLIFE e vencimento antigo, ela
     * já NASCE com os juros/multa/honorários do dia (estilo planilha), em vez de zero esperando o cron —
     * e segue recalculável (não congela). Valor exato é provado no CalculadoraEncargosTest; aqui só o efeito.
     */
    #[Test]
    public function obrigacaoNasceComEncargosCalculadosQuandoNaoDigitou(): void
    {
        $caso = $this->casoTopLife();
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->eventoRepository->method('salvar');

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Boleto muito atrasado';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        // Sem digitar nada nos encargos.

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertGreaterThan(0, $obrigacao->getJuros(), 'nasce com os juros do dia, não em zero');
        self::assertSame(2000, $obrigacao->getMulta(), 'multa fixa 2% de R$ 1.000,00');
        self::assertGreaterThan(0, $obrigacao->getHonorarios(), 'honorários materializados já na criação');
        self::assertFalse($obrigacao->encargosCongelados(), 'automática: segue recalculável (o cron a faz crescer)');
        self::assertNotNull($obrigacao->getEncargosAtualizadosEm(), 'materializou: tem data de referência');
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

    /**
     * Caso com carteira "TOPLIFE" (juros 1% a.m., multa 2%, carência 30, honorários 20% sobre base
     * composta), para os cenários em que o cálculo automático precisa produzir encargos > 0 de forma
     * determinística. Grafo em memória — o resolver lê só os getters de config, sem persistência.
     *
     * T1 (cascata ao vivo sem snapshot): a alíquota de honorários mora na CARTEIRA (herdada pelo
     * objeto/caso sem override) — o snapshot do CASO (`formaHonorarios`/`percentualHonorarios`) virou
     * coluna-sombra morta e não é mais lida pelo resolvedor.
     */
    private function casoTopLife(): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setTaxaJurosMensalBp(100)
            ->setTaxaMultaBp(200)
            ->setCarenciaHonorariosDias(30)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00');

        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        return (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setObjeto($objeto);
    }
}
