<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\BonusDeEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SituacaoDoEspaco::class)]
#[CoversClass(RetratoDoEspaco::class)]
final class SituacaoDoEspacoTest extends TestCase
{
    private const GB = RetratoDoEspaco::BYTES_POR_GB;

    #[TestDox('Escritório sem linha está no Free: 10 GB, origem plano')]
    public function testSemLinhaValeOFree(): void
    {
        $retrato = $this->situacao(null)->retratar(7, (int) (9.3 * self::GB));

        self::assertSame('free', $retrato->planoCodigo);
        self::assertSame(10 * self::GB, $retrato->bytesLimite);
        self::assertSame(93, $retrato->percentual);
        self::assertSame(FaixaDoEspaco::Alerta, $retrato->faixa);
        self::assertSame(OrigemDoLimite::Plano, $retrato->origemDoLimite);
    }

    #[TestDox('Limite do Master vence o do plano')]
    public function testLimiteDoMasterVenceOPlano(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), self::free());
        $espaco->definirLimite(30, false, 'contratou ampliação', null);

        $retrato = $this->situacao($espaco)->retratar(7, 24 * self::GB);

        self::assertSame(30 * self::GB, $retrato->bytesLimite);
        self::assertSame(80, $retrato->percentual);
        self::assertSame(OrigemDoLimite::Master, $retrato->origemDoLimite);
    }

    #[TestDox('Plano pago: o limite menor do Master não tira o que o escritório paga; o maior vale')]
    public function testPlanoPagoNaoEncolhe(): void
    {
        $essencial = new Plano('essencial', 'Essencial', 20, 1990, 'Para crescer', 2, 8);
        $espaco = new EspacoDoEscritorio(new Tenant(), $essencial);
        $espaco->definirLimite(12, false, 'limite antigo', null);
        $espaco->definirLimiteDeUsuarios(5, 'limite antigo', null);

        $retrato = $this->situacao($espaco)->retratar(7, 0);
        self::assertSame(20 * self::GB, $retrato->bytesLimite);
        self::assertSame(OrigemDoLimite::Plano, $retrato->origemDoLimite);
        // D5 revogada (dono, 26/10/2026): o plano não limita usuários, e o limite menor do Master não vale em plano pago.
        self::assertNull($retrato->limiteUsuarios);

        $espaco->definirLimite(30, false, 'cortesia', null);
        self::assertSame(30 * self::GB, $this->situacao($espaco)->retratar(7, 0)->bytesLimite);
    }

    #[TestDox('Em lote: o mesmo retrato de cada escritório, quem não tem linha no Free, na ordem pedida')]
    public function testRetratarVarios(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), self::free());
        $espaco->definirLimite(30, false, 'contratou ampliação', null);
        $situacao = $this->situacao($espaco);

        $retratos = $situacao->retratarVarios([9 => 5 * self::GB, 7 => 24 * self::GB]);

        self::assertSame([9, 7], array_keys($retratos));
        self::assertEquals($situacao->retratar(7, 24 * self::GB), $retratos[7]);
        self::assertSame(10 * self::GB, $retratos[9]->bytesLimite);
        self::assertSame(9, $retratos[9]->tenantId);
        self::assertSame([], $situacao->retratarVarios([]));
    }

    #[TestDox('Sem limite: nem limite, nem percentual, e sempre cabe')]
    public function testSemLimite(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), self::free());
        $espaco->definirLimite(null, true, 'escritório do dono', null);

        $retrato = $this->situacao($espaco, bloqueioAtivo: true)->retratar(7, 500 * self::GB);

        self::assertNull($retrato->bytesLimite);
        self::assertNull($retrato->percentual);
        self::assertSame(FaixaDoEspaco::Livre, $retrato->faixa);
        self::assertSame(OrigemDoLimite::SemLimite, $retrato->origemDoLimite);
        self::assertTrue($retrato->cabe(10 * self::GB));
    }

    /** @return iterable<string, array{int, FaixaDoEspaco}> */
    public static function faixas(): iterable
    {
        yield '69% livre' => [69, FaixaDoEspaco::Livre];
        yield '70% atenção' => [70, FaixaDoEspaco::Atencao];
        yield '90% alerta' => [90, FaixaDoEspaco::Alerta];
        yield '95% crítico' => [95, FaixaDoEspaco::Critico];
        yield '100% bloqueio' => [100, FaixaDoEspaco::Bloqueio];
        yield '130% bloqueio' => [130, FaixaDoEspaco::Bloqueio];
    }

    #[TestDox('Faixa de $percentual%')]
    #[DataProvider('faixas')]
    public function testFaixas(int $percentual, FaixaDoEspaco $esperada): void
    {
        $retrato = $this->situacao(null)->retratar(7, (int) ($percentual / 100 * 10 * self::GB));

        self::assertSame($esperada, $retrato->faixa);
    }

    #[TestDox('Com o bloqueio ligado, cabe até o limite exato e não um byte a mais')]
    public function testCabeAteOLimite(): void
    {
        $retrato = $this->situacao(null, bloqueioAtivo: true)->retratar(7, 10 * self::GB - 100);

        self::assertTrue($retrato->cabe(100));
        self::assertFalse($retrato->cabe(101));
    }

    #[TestDox('Com o bloqueio desligado, sempre cabe')]
    public function testBloqueioDesligadoSempreCabe(): void
    {
        $retrato = $this->situacao(null, bloqueioAtivo: false)->retratar(7, 50 * self::GB);

        self::assertTrue($retrato->cabe(self::GB));
    }

    #[TestDox('Limite de usuários: o do Master vence o do plano')]
    public function testLimiteDeUsuarios(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), self::free());
        $espaco->definirLimiteDeUsuarios(5, 'novo estagiário', null);

        $retrato = $this->situacao($espaco)->retratar(7, 0);

        self::assertSame(5, $retrato->limiteUsuarios);
        self::assertSame(OrigemDoLimite::Master, $retrato->origemDoLimiteDeUsuarios);
        self::assertNull($this->situacao(null)->retratar(7, 0)->limiteUsuarios, 'o Free do 08 não limita usuários');
    }

    #[TestDox('Sem limite dispensa também o limite de usuários, mesmo com plano e limite do Master')]
    public function testSemLimiteDispensaUsuarios(): void
    {
        $plano = new Plano('essencial', 'Essencial', 20, 1990, 'Essencial', 2, 8);
        $espaco = new EspacoDoEscritorio(new Tenant(), $plano);
        $espaco->definirLimiteDeUsuarios(5, 'antes', null);
        $espaco->definirLimite(null, true, 'escritório do dono', null);

        $retrato = $this->situacao($espaco)->retratar(7, 0);

        self::assertNull($retrato->limiteUsuarios);
        self::assertSame(OrigemDoLimite::SemLimite, $retrato->origemDoLimiteDeUsuarios);
    }

    #[TestDox('O plano não limita usuários (D5 revogada em 26/10/2026), mesmo com a coluna preenchida')]
    public function testLimiteDeUsuariosDoPlano(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), new Plano('essencial', 'Essencial', 20, 1990, 'Essencial', 2, 8));

        $retrato = $this->situacao($espaco)->retratar(7, 0);

        self::assertNull($retrato->limiteUsuarios);
        self::assertSame(OrigemDoLimite::Plano, $retrato->origemDoLimiteDeUsuarios);
    }

    #[TestDox('GB no formato do desenho: "9,3 GB", "10 GB"')]
    public function testFormatarGb(): void
    {
        self::assertSame('9,3 GB', RetratoDoEspaco::formatarGb((int) (9.3 * self::GB)));
        self::assertSame('10 GB', RetratoDoEspaco::formatarGb(10 * self::GB));
        self::assertSame('0 GB', RetratoDoEspaco::formatarGb(0));
    }

    #[TestDox('Faixa Bloqueio antecipada pelo Master (95%): o envio para em 95% do limite')]
    public function testBloqueioNaFaixaConfigurada(): void
    {
        $situacao = $this->situacao(null, bloqueioAtivo: true, faixas: [60, 80, 90, 95]);

        $retrato = $situacao->retratar(7, (int) (0.95 * 10 * self::GB) - 100);

        self::assertTrue($retrato->cabe(100));
        self::assertFalse($retrato->cabe(101));
    }

    #[TestDox('99,96% arredonda para 100 mas ainda aceita envio: a faixa é Crítico, não Bloqueio')]
    public function testFaixaBloqueioSoComOBloqueioDeVerdade(): void
    {
        $retrato = $this->situacao(null, bloqueioAtivo: true)->retratar(7, (int) (0.9996 * 10 * self::GB));

        self::assertSame(100, $retrato->percentual);
        self::assertSame(FaixaDoEspaco::Critico, $retrato->faixa);
        self::assertTrue($retrato->cabe(1));
    }

    private static function free(): Plano
    {
        return new Plano('free', 'Free', 10, 0, 'Todos os recursos do BlueJus, sem custo', 1);
    }

    private function bonusRepo(array $porTenant): BonusDeEspacoRepository
    {
        $repo = $this->createMock(BonusDeEspacoRepository::class);
        $repo->expects($this->once())->method('vigentesDosTenants')->willReturn($porTenant);

        return $repo;
    }

    private static function bonus(string $expira): BonusDeEspaco
    {
        $indicacao = (new \ReflectionClass(Indicacao::class))->newInstanceWithoutConstructor();

        return new BonusDeEspaco(new Tenant(), $indicacao, 5, new \DateTimeImmutable('2026-10-20'), new \DateTimeImmutable($expira));
    }

    #[TestDox('Bônus vigente soma ao limite do plano e expõe bytesBonus, validade e origem')]
    public function testBonusSomaAoPlano(): void
    {
        $retrato = $this->situacao(null, bonus: $this->bonusRepo([7 => [self::bonus('2026-11-19 10:00:00')]]))->retratar(7, 12 * self::GB);

        self::assertSame(15 * self::GB, $retrato->bytesLimite);
        self::assertSame(5 * self::GB, $retrato->bytesBonus);
        self::assertSame('2026-11-19', $retrato->bonusAte?->format('Y-m-d'));
        self::assertSame('indicacao', $retrato->bonusOrigem);
        self::assertSame(80, $retrato->percentual);
        self::assertSame(OrigemDoLimite::Plano, $retrato->origemDoLimite);
    }

    #[TestDox('Bônus vigente soma também ao limite próprio do Master')]
    public function testBonusSomaAoLimiteDoMaster(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), self::free());
        $espaco->definirLimite(30, false, 'contratou ampliação', null);

        $retrato = $this->situacao($espaco, bonus: $this->bonusRepo([7 => [self::bonus('2026-11-19')]]))->retratar(7, 0);

        self::assertSame(35 * self::GB, $retrato->bytesLimite);
        self::assertSame(OrigemDoLimite::Master, $retrato->origemDoLimite);
    }

    #[TestDox('Sem bônus vigente (a consulta só traz os não vencidos): o limite é o do plano e não há validade')]
    public function testSemBonusVigente(): void
    {
        $retrato = $this->situacao(null, bonus: $this->bonusRepo([]))->retratar(7, 0);

        self::assertSame(10 * self::GB, $retrato->bytesLimite);
        self::assertSame(0, $retrato->bytesBonus);
        self::assertNull($retrato->bonusAte);
    }

    #[TestDox('Sem limite ignora o bônus: sem efeito, sem validade')]
    public function testSemLimiteIgnoraOBonus(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), self::free());
        $espaco->definirLimite(null, true, 'escritório do dono', null);

        $retrato = $this->situacao($espaco, bonus: $this->bonusRepo([7 => [self::bonus('2026-11-19')]]))->retratar(7, 0);

        self::assertNull($retrato->bytesLimite);
        self::assertSame(0, $retrato->bytesBonus);
        self::assertNull($retrato->bonusAte);
    }

    #[TestDox('A leitura pergunta pelo bônus com a hora do relógio (vencido = expira_em não maior que agora, no repositório)')]
    public function testConsultaComAHoraDoRelogio(): void
    {
        $repo = $this->createMock(BonusDeEspacoRepository::class);
        $repo->expects($this->once())->method('vigentesDosTenants')
            ->with([7], $this->callback(static fn (\DateTimeImmutable $agora): bool => $agora->format('Y-m-d H:i:s') === '2026-10-26 10:00:00'))
            ->willReturn([]);

        $this->situacao(null, bonus: $repo)->retratar(7, 0);
    }

    #[TestDox('Em lote: os bônus vêm numa consulta só, e cada escritório recebe o seu')]
    public function testLoteSemNMaisUm(): void
    {
        $repo = $this->createMock(BonusDeEspacoRepository::class);
        $repo->expects($this->once())->method('vigentesDosTenants')->with([9, 7, 8])->willReturn([7 => [self::bonus('2026-11-19')]]);

        $retratos = $this->situacao(null, bonus: $repo)->retratarVarios([9 => 0, 7 => 0, 8 => 0]);

        self::assertSame(10 * self::GB, $retratos[9]->bytesLimite);
        self::assertSame(15 * self::GB, $retratos[7]->bytesLimite);
        self::assertSame(10 * self::GB, $retratos[8]->bytesLimite);
    }

    /** @param array{int, int, int, int} $faixas */
    private function situacao(?EspacoDoEscritorio $espaco, bool $bloqueioAtivo = false, array $faixas = [70, 90, 95, 100], ?BonusDeEspacoRepository $bonus = null): SituacaoDoEspaco
    {
        $espacos = $this->createStub(EspacoDoEscritorioRepository::class);
        $espacos->method('doTenant')->willReturn($espaco);
        $espacos->method('dosTenants')->willReturn($espaco === null ? [] : [7 => $espaco]);
        $planos = $this->createStub(PlanoRepository::class);
        $planos->method('free')->willReturn(self::free());
        $configuracao = new ConfiguracaoDoEspaco();
        $configuracao->ligarBloqueio($bloqueioAtivo);
        $configuracao->definirFaixas(...$faixas);
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn($configuracao);

        return new SituacaoDoEspaco($this->createStub(ConsumoDeArmazenamentoRepository::class), $espacos, $planos, $configuracoes, $bonus ?? $this->createStub(BonusDeEspacoRepository::class), new MockClock('2026-10-26 10:00:00'));
    }
}
