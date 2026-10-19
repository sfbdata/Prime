<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

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

    #[TestDox('Sem linha: vale o limite de usuários do plano')]
    public function testLimiteDeUsuariosDoPlano(): void
    {
        $espaco = new EspacoDoEscritorio(new Tenant(), new Plano('essencial', 'Essencial', 20, 1990, 'Essencial', 2, 8));

        $retrato = $this->situacao($espaco)->retratar(7, 0);

        self::assertSame(8, $retrato->limiteUsuarios);
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

    /** @param array{int, int, int, int} $faixas */
    private function situacao(?EspacoDoEscritorio $espaco, bool $bloqueioAtivo = false, array $faixas = [70, 90, 95, 100]): SituacaoDoEspaco
    {
        $espacos = $this->createStub(EspacoDoEscritorioRepository::class);
        $espacos->method('doTenant')->willReturn($espaco);
        $planos = $this->createStub(PlanoRepository::class);
        $planos->method('free')->willReturn(self::free());
        $configuracao = new ConfiguracaoDoEspaco();
        $configuracao->ligarBloqueio($bloqueioAtivo);
        $configuracao->definirFaixas(...$faixas);
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn($configuracao);

        return new SituacaoDoEspaco($this->createStub(ConsumoDeArmazenamentoRepository::class), $espacos, $planos, $configuracoes);
    }
}
