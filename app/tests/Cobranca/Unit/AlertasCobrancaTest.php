<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlertasCobranca::class)]
final class AlertasCobrancaTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private ProximaAcaoRepository&MockObject $proximaAcaoRepository;
    private CalculadoraSaldo&MockObject $calculadoraSaldo;
    private AlertasCobranca $sut;
    private Tenant $tenant;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->proximaAcaoRepository = $this->createMock(ProximaAcaoRepository::class);
        $this->calculadoraSaldo = $this->createMock(CalculadoraSaldo::class);
        $this->sut = new AlertasCobranca(
            $this->obrigacaoRepository,
            $this->proximaAcaoRepository,
            $this->calculadoraSaldo,
        );
        $this->tenant = new Tenant();
        $this->hoje = new \DateTimeImmutable('2026-07-09');
    }

    private function casoAtivo(): CasoCobranca
    {
        return (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Ativo);
    }

    /** @return TipoAlerta[] */
    private function tipos(array $alertas): array
    {
        return array_map(static fn ($alerta) => $alerta->tipo, $alertas);
    }

    private function obrigacao(\DateTimeImmutable $vencimento, ?Acordo $acordoOrigem = null): Obrigacao
    {
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setVencimentoOriginal($vencimento);

        if ($acordoOrigem !== null) {
            $obrigacao->setAcordoOrigem($acordoOrigem);
        }

        return $obrigacao;
    }

    #[Test]
    public function saldoZeroGeraAlertaProntoParaEncerrar(): void
    {
        $caso = $this->casoAtivo();

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->with($caso)->willReturn(0);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertContains(TipoAlerta::ProntoParaEncerrar, $tipos);
    }

    #[Test]
    public function acaoAtivaAtrasadaGeraAlerta(): void
    {
        $caso = $this->casoAtivo();
        $acao = (new ProximaAcao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setPrazo(new \DateTimeImmutable('2026-07-01')); // prazo já vencido em relação a hoje

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn($acao);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertContains(TipoAlerta::AcaoAtrasada, $tipos);
    }

    #[Test]
    public function acaoAtivaNoPrazoNaoGeraAlerta(): void
    {
        $caso = $this->casoAtivo();
        $acao = (new ProximaAcao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setPrazo(new \DateTimeImmutable('2026-07-20')); // prazo futuro

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn($acao);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertNotContains(TipoAlerta::AcaoAtrasada, $tipos);
    }

    #[Test]
    public function obrigacaoVencidaComumGeraSoAlertaDeObrigacao(): void
    {
        $caso = $this->casoAtivo();
        $vencida = $this->obrigacao(new \DateTimeImmutable('2026-06-30')); // vencida, sem acordo

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$vencida]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertContains(TipoAlerta::ObrigacaoVencida, $tipos);
        self::assertNotContains(TipoAlerta::ParcelaAcordoVencida, $tipos);
    }

    #[Test]
    public function parcelaDeAcordoVencidaGeraAmbosAlertas(): void
    {
        $caso = $this->casoAtivo();
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcela = $this->obrigacao(new \DateTimeImmutable('2026-06-30'), $acordo); // parcela vencida

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$parcela]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertContains(TipoAlerta::ObrigacaoVencida, $tipos);
        self::assertContains(TipoAlerta::ParcelaAcordoVencida, $tipos);
    }

    #[Test]
    public function obrigacaoAVencerNaoGeraAlerta(): void
    {
        $caso = $this->casoAtivo();
        $aVencer = $this->obrigacao(new \DateTimeImmutable('2026-08-01')); // vencimento futuro

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$aVencer]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertNotContains(TipoAlerta::ObrigacaoVencida, $tipos);
        self::assertNotContains(TipoAlerta::ParcelaAcordoVencida, $tipos);
    }

    #[Test]
    public function casoEncerradoNaoGeraNenhumAlerta(): void
    {
        // Caso encerrado é estado final: retorna lista vazia sem consultar nada (short-circuit).
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);

        $this->obrigacaoRepository->expects($this->never())->method('doCasoExigiveis');
        $this->proximaAcaoRepository->expects($this->never())->method('findAtivaDoCaso');
        $this->calculadoraSaldo->expects($this->never())->method('saldoExigivel');

        self::assertSame([], $this->sut->alertasDoCaso($caso, $this->hoje));
    }
}
