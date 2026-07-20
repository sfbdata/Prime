<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarConfiguracaoCasoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\EditarConfiguracaoCasoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarConfiguracaoCasoUseCase::class)]
final class EditarConfiguracaoCasoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private EditarConfiguracaoCasoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        // RegistrarEventoHistorico é final (não-mockável): usa-se o REAL com o repositório de eventos
        // mockado, validando o flush via a chamada salvar(EventoHistorico, flush).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // CalculadoraEncargos e ResolvedorConfigEncargos são `final` mas PUROS (sem I/O): usa-se o REAL
        // — mockar um motor de dinheiro esconderia justamente o cálculo que estamos verificando.
        $this->sut = new EditarConfiguracaoCasoUseCase(
            $this->casoRepository,
            $this->obrigacaoRepository,
            new ResolvedorConfigEncargos(),
            new CalculadoraEncargos(),
            new RegistrarEventoHistorico($this->eventoRepository),
        );
        // Tenant não é abstração do domínio: instância real, não mock.
        $this->tenant = new Tenant();
    }

    #[Test]
    #[TestDox('Altera os 4 campos de honorário do caso e persiste com flush único (evento sem flush)')]
    public function alteraOsQuatroCamposDeHonorarioEPersiste(): void
    {
        // Caso com tenant (exigido pelo RegistrarEventoHistorico) e sem obrigações a recalcular.
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(30, $this->tenant)
            ->willReturn($caso);

        $this->obrigacaoRepository
            ->expects($this->once())
            ->method('paraRecalculoDeEncargosDoCaso')
            ->with($caso)
            ->willReturn([]);

        // Flush único no caso; o evento entra SEM flush (a transação fecha no salvar do caso).
        $this->casoRepository->expects($this->once())->method('salvar')->with($caso, true);
        $this->eventoRepository->expects($this->once())->method('salvar')->with(self::isInstanceOf(EventoHistorico::class), false);

        $input = $this->input('15.50');
        $input->baseHonorarios = BaseEncargo::Principal;
        $input->carenciaHonorariosDias = 45;

        $resultado = $this->sut->executar($input, $this->tenant);

        self::assertSame($caso, $resultado);
        self::assertSame(FormaHonorarios::AcrescidoDivida, $resultado->getFormaHonorarios());
        self::assertSame('15.50', $resultado->getPercentualHonorarios());
        self::assertSame(BaseEncargo::Principal, $resultado->getBaseHonorarios());
        self::assertSame(45, $resultado->getCarenciaHonorariosDias());
    }

    #[Test]
    #[TestDox('Recalcula o honorário de automática viva na hora: subir 10% → 20% sobe o honorário')]
    public function recalculaHonorarioDeAutomaticaVivaAoSubirOPercentual(): void
    {
        $caso = $this->casoComHonorarios('10.00');
        $obrigacao = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');

        // Estado "antes": materializa a 10% pela MESMA cascata do cron, para hoje.
        $honAntes = $this->materializarParaHoje($obrigacao);
        self::assertGreaterThan(0, $honAntes, 'com 240+ dias de atraso a 10% já há honorário');

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->with($caso)->willReturn([$obrigacao]);
        $this->eventoRepository->method('salvar');

        $this->sut->executar($this->input('20.00'), $this->tenant);

        $honDepois = $obrigacao->getHonorarios();
        self::assertGreaterThan($honAntes, $honDepois, 'subir o percentual sobe o honorário na hora');
        // Honorário = taxa × base composta; a base (P+juros+multa+correção) não muda com a alíquota,
        // então de 10% para 20% o honorário praticamente dobra (± 1 centavo de arredondamento).
        self::assertLessThanOrEqual(1, abs($honDepois - 2 * $honAntes));
        // Recálculo automático NÃO congela (INV-E4): a obrigação segue sob o cron.
        self::assertFalse($obrigacao->encargosCongelados());
    }

    #[Test]
    #[TestDox('INV-E4: obrigação congelada não é tocada pelo laço de recálculo (prova por mutação)')]
    public function naoTocaObrigacaoCongelada(): void
    {
        $caso = $this->casoComHonorarios('10.00');

        // Congelada com valores "da contabilidade", DIFERENTES do que o recálculo produziria. O
        // predicado do repositório já exclui congeladas; aqui provamos o guard do laço: mesmo que uma
        // escapasse para a lista, ela NÃO é tocada.
        $congelada = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');
        $congelada->definirEncargos(111, 222, 333, 444, new \DateTimeImmutable('2026-02-01'));
        $congelada->congelarEncargos(new \DateTimeImmutable('2026-02-01'));

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->willReturn([$congelada]);
        $this->eventoRepository->method('salvar');

        $this->sut->executar($this->input('20.00'), $this->tenant);

        self::assertSame(111, $congelada->getJuros());
        self::assertSame(222, $congelada->getMulta());
        self::assertSame(333, $congelada->getCorrecao());
        self::assertSame(444, $congelada->getHonorarios());
        self::assertSame('2026-02-01', $congelada->getEncargosAtualizadosEm()?->format('Y-m-d'), 'nem a data de referência muda');
    }

    #[Test]
    #[TestDox('Redução é aplicada (sem freio): baixar 20% → 10% desce o honorário na hora')]
    public function aplicaReducaoSemFreio(): void
    {
        $caso = $this->casoComHonorarios('20.00');
        $obrigacao = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');

        $honAntes = $this->materializarParaHoje($obrigacao); // a 20%
        self::assertGreaterThan(0, $honAntes);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->willReturn([$obrigacao]);
        $this->eventoRepository->method('salvar');

        $this->sut->executar($this->input('10.00'), $this->tenant);

        $honDepois = $obrigacao->getHonorarios();
        self::assertLessThan($honAntes, $honDepois, 'baixar o percentual é decisão deliberada — reduzir é esperado (sem freio)');
        // De 20% para 10% o honorário cai praticamente à metade (± 1 centavo).
        self::assertLessThanOrEqual(1, abs(2 * $honDepois - $honAntes));
    }

    #[Test]
    #[TestDox('Guarda multi-tenant: caso de outro escritório → exceção, sem salvar nem recalcular')]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->obrigacaoRepository->expects($this->never())->method('paraRecalculoDeEncargosDoCaso');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $this->sut->executar($this->input('20.00'), $this->tenant);
    }

    #[Test]
    #[TestDox('Registra UM único evento no histórico, mesmo com várias obrigações recalculadas')]
    public function registraUmUnicoEventoMesmoComVariasObrigacoes(): void
    {
        $caso = $this->casoComHonorarios('10.00');
        $o1 = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');
        $o2 = $this->obrigacaoAutomatica($caso, 50000, '2019-06-01');

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->willReturn([$o1, $o2]);

        // UM único evento (não um por obrigação — seria ruído no histórico).
        $this->eventoRepository->expects($this->once())->method('salvar');

        $this->sut->executar($this->input('20.00'), $this->tenant);

        self::assertGreaterThan(0, $o1->getHonorarios());
        self::assertGreaterThan(0, $o2->getHonorarios());
    }

    /** Materializa os encargos da obrigação para HOJE pela cascata atual (estado "antes" coerente com o cron). */
    private function materializarParaHoje(Obrigacao $obrigacao): int
    {
        $hoje = new \DateTimeImmutable('today');
        $config = (new ResolvedorConfigEncargos())->resolver($obrigacao);
        $novos = (new CalculadoraEncargos())->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $hoje,
        );
        $obrigacao->definirEncargos($novos['juros'], $novos['multa'], $novos['correcao'], $novos['honorarios'], $hoje);

        return $obrigacao->getHonorarios();
    }

    /**
     * Caso com carteira "TOPLIFE" (juros 1% a.m., multa 2%, honorários sobre base composta com carência
     * de 30 dias) e snapshot de honorários `AcrescidoDivida` no percentual dado. Grafo em memória — o
     * resolver lê só os getters de config, sem persistência.
     */
    private function casoComHonorarios(string $percentual): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setTaxaJurosMensalBp(100)
            ->setTaxaMultaBp(200)
            ->setBaseHonorarios(BaseEncargo::Composta)
            ->setCarenciaHonorariosDias(30);

        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        return (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setObjeto($objeto)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios($percentual);
    }

    private function obrigacaoAutomatica(CasoCobranca $caso, int $valorOriginal, string $vencimento): Obrigacao
    {
        return (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto de teste')
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable($vencimento));
    }

    private function input(string $percentual): EditarConfiguracaoCasoInput
    {
        $input = new EditarConfiguracaoCasoInput();
        $input->casoId = 30;
        $input->formaHonorarios = FormaHonorarios::AcrescidoDivida;
        $input->percentualHonorarios = $percentual;

        return $input;
    }
}
