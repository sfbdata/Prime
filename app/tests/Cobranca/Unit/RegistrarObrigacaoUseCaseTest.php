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
     * F6: uma dívida trazida de outro sistema já vem com os encargos calculados lá fora. O gestor os
     * DIGITA no lançamento; a partir daí eles são a verdade e a obrigação nasce TRAVADA (o cron não passa
     * por cima — INV-E4). Os honorários, que não têm campo no form, são COMPLETADOS pelo motor sobre a
     * base digitada — antes travavam em zero (o bug bloqueante da F4), agora congelar é seguro.
     */
    #[Test]
    public function encargosDigitadosNoLancamentoCongelamEGanhamHonorariosPeloMotor(): void
    {
        // Carteira TOPLIFE (20% sobre base composta) e vencimento antigo → o motor completa os honorários
        // de forma determinística.
        $caso = $this->casoTopLife();
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
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        $input->juros = 5000;
        $input->multa = 2000;
        $input->correcao = 500;

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        // Cada componente digitado no seu campo (nada de agregar tudo em `juros`).
        self::assertSame(5000, $obrigacao->getJuros());
        self::assertSame(2000, $obrigacao->getMulta());
        self::assertSame(500, $obrigacao->getCorrecao());
        self::assertSame(7500, $obrigacao->getEncargosReconhecidos(), 'o agregado é o derivado (INV-E1)');
        self::assertSame(107500, $obrigacao->valorExigivel());
        // Honorários COMPLETADOS pelo motor: base composta 100000 + 5000 + 2000 + 500 = 107500 · 20% = 21500.
        self::assertSame(21500, $obrigacao->getHonorarios(), 'honorários completados sobre a base digitada, não travados em zero');
        // E agora CONGELA: o valor digitado é a verdade e o cron não passa por cima (INV-E4).
        self::assertTrue($obrigacao->encargosCongelados(), 'digitar encargos à mão trava a obrigação');

        // O histórico precisa explicar de onde veio esse dinheiro.
        self::assertInstanceOf(EventoHistorico::class, $capturado);
        $dados = $capturado->getDados();
        self::assertSame(5000, $dados['juros']);
        self::assertSame(2000, $dados['multa']);
        self::assertSame(500, $dados['correcao']);
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

    /**
     * Ajuste 2 (D-A2-5): honorário DIGITADO é override — o motor NÃO o sobrescreve — e a obrigação nasce
     * TRAVADA. Com carteira TOPLIFE (20% sobre base composta) o motor produziria 21500; o gestor fixou
     * 9999 e é isso que fica. Digitar o honorário sozinho já é digitação (mesmo com juros/multa/correção).
     */
    #[Test]
    public function honorarioDigitadoNoLancamentoEhOverrideECongela(): void
    {
        $caso = $this->casoTopLife();
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->eventoRepository->method('salvar');

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Dívida com honorário fixado à mão';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        $input->juros = 5000;
        $input->multa = 2000;
        $input->correcao = 500;
        // O motor daria 21500 (20% de 107500); o gestor fixou 9999 — é o que tem de prevalecer.
        $input->honorarios = 9999;

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame(9999, $obrigacao->getHonorarios(), 'honorário digitado é override: o motor não o sobrescreve');
        self::assertNotSame(21500, $obrigacao->getHonorarios(), 'não é o valor que o motor calcularia');
        self::assertSame(107500, $obrigacao->valorExigivel(), 'honorário fora do exigível (INV-E2)');
        self::assertTrue($obrigacao->encargosCongelados(), 'digitar honorário à mão trava a obrigação');
    }

    /**
     * Ajuste 2 (D-A2-5, risco 3): honorário VAZIO (`null`) NÃO é digitação. Com os outros três também
     * vazios, a obrigação é AUTOMÁTICA (o motor calcula os 4 e ela segue recalculável). Prova por mutação:
     * se `honorarios !== null` fosse afrouxado para tratar o vazio como digitado, ela congelaria e este
     * teste ficaria vermelho.
     */
    #[Test]
    public function honorarioVazioMantemAObrigacaoAutomatica(): void
    {
        $caso = $this->casoTopLife();
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->eventoRepository->method('salvar');

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Boleto automático';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        $input->honorarios = null; // explícito: vazio = automático

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertFalse($obrigacao->encargosCongelados(), 'honorário vazio não congela: segue automática');
        self::assertGreaterThan(0, $obrigacao->getHonorarios(), 'o motor completa o honorário do dia (não fica em zero)');
    }

    /**
     * Ajuste 2 (risco 3): `0` NÃO é `null`. Zero explícito é uma decisão (honorário zero fixo) e CONGELA,
     * distinto do vazio (automático). Sem essa distinção, o motor de 20% recomporia um honorário que o
     * gestor zerou de propósito.
     */
    #[Test]
    public function honorarioZeroExplicitoEhRespeitadoECongela(): void
    {
        $caso = $this->casoTopLife();
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->eventoRepository->method('salvar');

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 30;
        $input->descricao = 'Sem honorário, fixo em zero';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        $input->honorarios = 0;

        $obrigacao = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame(0, $obrigacao->getHonorarios(), 'honorário zero explícito é respeitado, não recomposto pelo motor');
        self::assertTrue($obrigacao->encargosCongelados(), 'zero explícito é digitação: congela');
    }

    #[Test]
    public function encargosZeradosNaoCongelamAObrigacao(): void
    {
        // Carteira NEUTRA (sem objeto/carteira no caso): o cálculo automático dá 0 e nada é digitado.
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

        self::assertSame(0, $obrigacao->getEncargosReconhecidos(), 'carteira neutra → nenhum encargo');
        self::assertFalse(
            $obrigacao->encargosCongelados(),
            'zero não é "valor digitado": a obrigação nova tem de continuar sendo calculada pelo cron',
        );
        // Novo modelo (estilo planilha): mesmo com encargos zero a obrigação NASCE materializada para
        // hoje — a data de referência é gravada (o cálculo automático rodou, só deu 0).
        self::assertNotNull($obrigacao->getEncargosAtualizadosEm(), 'a materialização do dia é datada mesmo quando dá 0');
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
     */
    private function casoTopLife(): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setTaxaJurosMensalBp(100)
            ->setTaxaMultaBp(200)
            ->setCarenciaHonorariosDias(30);

        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        return (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setObjeto($objeto)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00');
    }
}
