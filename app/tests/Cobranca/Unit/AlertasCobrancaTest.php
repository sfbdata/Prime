<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
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
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private AlertasCobranca $sut;
    private Tenant $tenant;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->proximaAcaoRepository = $this->createMock(ProximaAcaoRepository::class);
        $this->calculadoraSaldo = $this->createMock(CalculadoraSaldo::class);
        // Sem stub default de propósito: o mock já devolve `[]` (nada alocado) pelo tipo de retorno, e um
        // `method()` configurado aqui GANHARIA do `method()` do teste — no PHPUnit o primeiro matcher
        // registrado é o que responde.
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->sut = new AlertasCobranca(
            $this->obrigacaoRepository,
            $this->proximaAcaoRepository,
            $this->calculadoraSaldo,
            $this->alocacaoRepository,
        );
        $this->tenant = new Tenant();
        $this->hoje = new \DateTimeImmutable('2026-07-09');
    }

    private function casoAtivo(?int $id = null): CasoCobranca
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Ativo);

        if ($id !== null) {
            (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, $id);
        }

        return $caso;
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

    /**
     * Obrigação com id e valor — o par que o mapa `obrigacaoId => Σ alocado` precisa para dizer se ela
     * já está paga. Sem encargos: `valorExigivel()` é o próprio `valorOriginal`.
     */
    private function obrigacaoIdentificada(
        int $id,
        int $valorOriginal,
        \DateTimeImmutable $vencimento,
        ?Acordo $acordoOrigem = null,
    ): Obrigacao {
        $obrigacao = $this->obrigacao($vencimento, $acordoOrigem)->setValorOriginal($valorOriginal);
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, $id);

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
    public function obrigacaoVencidaJaPagaNaoGeraAlerta(): void
    {
        // O defeito que este teste tranca: o alerta contava obrigação JÁ PAGA como vencida a verificar,
        // enquanto a aba "Dívida em aberto" a escondia na seção "Já pago" — a mesma página dizia duas
        // coisas contrárias. A régua aqui é a MESMA da tela (`ObrigacaoOutput::quitada()`).
        $caso = $this->casoAtivo(77);
        $paga = $this->obrigacaoIdentificada(1, 10_000, new \DateTimeImmutable('2026-06-30'));

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$paga]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([1 => 10_000]);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertNotContains(TipoAlerta::ObrigacaoVencida, $tipos);
    }

    #[Test]
    public function parcelaDeAcordoVencidaJaPagaNaoGeraAlerta(): void
    {
        // Parcela de acordo CUMPRIDO continua no conjunto exigível (`aorig.status IN (ativo, cumprido)`),
        // então sem esta regra a parcela quitada de um acordo já cumprido aparecia como vencida.
        $caso = $this->casoAtivo(77);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcela = $this->obrigacaoIdentificada(2, 25_000, new \DateTimeImmutable('2026-06-30'), $acordo);

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$parcela]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([2 => 25_000]);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertNotContains(TipoAlerta::ObrigacaoVencida, $tipos);
        self::assertNotContains(TipoAlerta::ParcelaAcordoVencida, $tipos);
    }

    #[Test]
    public function obrigacaoVencidaPagaPelaMetadeAindaGeraAlerta(): void
    {
        // Contraprova: a regra nova não pode calar o alerta de quem pagou só uma parte — aí ainda há
        // dívida vencida, e a tela também mostra a linha (com o "falta …").
        $caso = $this->casoAtivo(77);
        $parcial = $this->obrigacaoIdentificada(3, 10_000, new \DateTimeImmutable('2026-06-30'));

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$parcial]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(5000);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([3 => 4_999]);

        $tipos = $this->tipos($this->sut->alertasDoCaso($caso, $this->hoje));

        self::assertContains(TipoAlerta::ObrigacaoVencida, $tipos);
    }

    #[Test]
    public function contagemDoAlertaExcluiSoAsPagas(): void
    {
        // O número que vai para a tela: 3 vencidas, 2 já pagas → o alerta fala de UMA.
        $caso = $this->casoAtivo(77);
        $vencimento = new \DateTimeImmutable('2026-06-30');

        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoIdentificada(10, 10_000, $vencimento),
            $this->obrigacaoIdentificada(11, 10_000, $vencimento),
            $this->obrigacaoIdentificada(12, 10_000, $vencimento),
        ]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(10_000);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')
            ->willReturn([10 => 10_000, 11 => 10_000]);

        $alertas = $this->sut->alertasDoCaso($caso, $this->hoje);
        $vencidas = array_values(array_filter(
            $alertas,
            static fn ($alerta): bool => $alerta->tipo === TipoAlerta::ObrigacaoVencida,
        ));

        self::assertCount(1, $vencidas);
        self::assertSame('1 obrigação(ões) exigível(is) vencida(s) a verificar.', $vencidas[0]->descricao);
    }

    #[Test]
    public function loteTambemIgnoraObrigacaoJaPaga(): void
    {
        // Mesma regra na versão em LOTE (Central de Alertas / Dashboard): as duas não podem divergir.
        $caso = $this->casoAtivo(77);
        $paga = $this->obrigacaoIdentificada(4, 10_000, new \DateTimeImmutable('2026-06-30'));
        (new \ReflectionProperty(Obrigacao::class, 'caso'))->setValue($paga, $caso);

        $this->obrigacaoRepository->method('exigiveisDosCasos')->willReturn([$paga]);
        $this->proximaAcaoRepository->method('ativasDosCasos')->willReturn([]);
        $this->calculadoraSaldo->method('saldosDosCasos')->willReturn([77 => ['exigivel' => 5000, 'vencido' => 0]]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([4 => 10_000]);

        $porCaso = $this->sut->alertasDosCasos([$caso], $this->tenant, $this->hoje);

        self::assertNotContains(TipoAlerta::ObrigacaoVencida, $this->tipos($porCaso[77]));
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
