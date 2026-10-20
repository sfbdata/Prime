<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Indicacao\DTO\AlterarLimitesDeIndicacaoInput;
use App\Indicacao\Entity\IndicacaoAlerta;
use App\Indicacao\Entity\IndicacaoConfig;
use App\Indicacao\Entity\IndicacaoPausa;
use App\Indicacao\Exception\IndicadorNaoEncontrado;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Repository\IndicacaoPausaRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\GuardaDoMaster;
use App\Indicacao\UseCase\AlterarLimitesDeIndicacaoUseCase;
use App\Indicacao\UseCase\ObterPainelDeIndicacoesUseCase;
use App\Indicacao\UseCase\PausarIndicacoesDoUsuarioUseCase;
use App\Indicacao\UseCase\PausarIndicacoesParaTodosUseCase;
use App\Repository\UserRepository;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Os UseCases que a 5d consome no card "Indicações do BlueJus" (contrato na spec indicar-bluejus.md). */
#[CoversClass(ObterPainelDeIndicacoesUseCase::class)]
#[CoversClass(AlterarLimitesDeIndicacaoUseCase::class)]
#[CoversClass(PausarIndicacoesParaTodosUseCase::class)]
#[CoversClass(PausarIndicacoesDoUsuarioUseCase::class)]
#[CoversClass(GuardaDoMaster::class)]
#[CoversClass(IndicacaoConfig::class)]
final class UseCasesDoMasterTest extends TestCase
{
    private const AGORA = '2026-10-19 15:00:00';

    private IndicacaoConfig $config;
    private IndicacaoConfigRepository $configs;
    private MockClock $relogio;
    private int $salvos = 0;

    protected function setUp(): void
    {
        $this->config = new IndicacaoConfig();
        $this->relogio = new MockClock(new \DateTimeImmutable(self::AGORA));
        $this->configs = $this->createStub(IndicacaoConfigRepository::class);
        $this->configs->method('obter')->willReturnCallback(fn (): IndicacaoConfig => $this->config);
        $this->configs->method('salvar')->willReturnCallback(function (): void {
            ++$this->salvos;
        });
    }

    #[TestDox('alterar limites: grava quem e quando e devolve os limites novos')]
    public function testAlterarLimites(): void
    {
        $master = Pessoas::master();

        $saida = (new AlterarLimitesDeIndicacaoUseCase($this->configs, $this->relogio))->executar(new AlterarLimitesDeIndicacaoInput(10, 100, 15), $master);

        self::assertSame([false, 10, 100, 15], [$saida->pausadoParaTodos, $saida->porDia, $saida->porMes, $saida->alertaEm24h]);
        self::assertSame($master, $this->config->getAlteradoPor());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $this->config->getAlteradoEm());
        self::assertSame(1, $this->salvos);
    }

    #[TestDox('alterar limites: valor abaixo de 1 é recusado e nada é gravado')]
    public function testLimiteAbaixoDeUm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new AlterarLimitesDeIndicacaoUseCase($this->configs, $this->relogio))->executar(new AlterarLimitesDeIndicacaoInput(0, 100, 15), Pessoas::master());
        } finally {
            self::assertSame(0, $this->salvos);
            self::assertSame(20, $this->config->getLimiteDia());
        }
    }

    #[TestDox('quem não é Master não altera nada (segunda camada da autorização)')]
    public function testSoMaster(): void
    {
        $naoMaster = Pessoas::usuario(5);
        $casos = [
            fn () => (new AlterarLimitesDeIndicacaoUseCase($this->configs, $this->relogio))->executar(new AlterarLimitesDeIndicacaoInput(1, 1, 1), $naoMaster),
            fn () => (new PausarIndicacoesParaTodosUseCase($this->configs, $this->relogio))->executar(true, $naoMaster),
            fn () => (new PausarIndicacoesDoUsuarioUseCase($this->createStub(IndicacaoPausaRepository::class), $this->createStub(UserRepository::class), $this->relogio))->executar(1, true, $naoMaster),
        ];
        foreach ($casos as $caso) {
            try {
                $caso();
                self::fail('Esperava a recusa.');
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame(0, $this->salvos);
        self::assertFalse($this->config->isPausadoParaTodos());
    }

    #[TestDox('pausar para todos e retomar')]
    public function testPausarParaTodos(): void
    {
        $useCase = new PausarIndicacoesParaTodosUseCase($this->configs, $this->relogio);

        $useCase->executar(true, Pessoas::master());
        self::assertTrue($this->config->isPausadoParaTodos());
        $useCase->executar(false, Pessoas::master());
        self::assertFalse($this->config->isPausadoParaTodos());
    }

    #[TestDox('pausar a pessoa grava a pausa (uma vez); retomar apaga; idempotente')]
    public function testPausarDoUsuario(): void
    {
        $alvo = Pessoas::usuario(5);
        $master = Pessoas::master();
        $atual = null;
        $gravou = 0;
        $removeu = 0;
        $pausas = $this->createStub(IndicacaoPausaRepository::class);
        $pausas->method('doUsuario')->willReturnCallback(static function () use (&$atual): ?IndicacaoPausa {
            return $atual;
        });
        $pausas->method('salvar')->willReturnCallback(static function (IndicacaoPausa $p) use (&$atual, &$gravou): void {
            $atual = $p;
            ++$gravou;
        });
        $pausas->method('remover')->willReturnCallback(static function () use (&$atual, &$removeu): void {
            $atual = null;
            ++$removeu;
        });
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturn($alvo);
        $useCase = new PausarIndicacoesDoUsuarioUseCase($pausas, $usuarios, $this->relogio);

        $useCase->executar(5, true, $master);
        $useCase->executar(5, true, $master);
        self::assertSame(1, $gravou);
        self::assertSame($alvo, $atual?->getUser());
        self::assertSame($master, $atual?->getPausadoPor());
        $useCase->executar(5, false, $master);
        $useCase->executar(5, false, $master);
        self::assertSame(1, $removeu);
        self::assertNull($atual);
    }

    #[TestDox('pausar usuário inexistente: IndicadorNaoEncontrado')]
    public function testPausarInexistente(): void
    {
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturn(null);

        $this->expectException(IndicadorNaoEncontrado::class);

        (new PausarIndicacoesDoUsuarioUseCase($this->createStub(IndicacaoPausaRepository::class), $usuarios, $this->relogio))->executar(404, true, Pessoas::master());
    }

    #[TestDox('painel: totais, limites, 3 alertas, indicadores com acimaDoAlerta e a página limitada')]
    public function testPainel(): void
    {
        $this->config->alterarLimites(20, 200, 5, Pessoas::master(), new \DateTimeImmutable());
        $agora = new \DateTimeImmutable(self::AGORA);
        $janelas = [];
        $indicacoes = $this->createStub(IndicacaoRepository::class);
        $indicacoes->method('totais')->willReturn(['enviadas' => 40, 'ultimas24h' => 9, 'viraramConta' => 3]);
        $indicacoes->method('contarIndicadores')->willReturn(2);
        $indicacoes->method('indicadores')->willReturnCallback(static function (\DateTimeImmutable $d24, \DateTimeImmutable $d30, int $offset, int $limite) use (&$janelas): array {
            $janelas = [$d24->format('Y-m-d H:i'), $d30->format('Y-m-d H:i'), $offset, $limite];

            return [
                ['user_id' => 7, 'nome' => 'Ana', 'email' => 'ana@x.com', 'escritorio' => 'Alfa', 'total' => 30, 'ultimas24h' => 5, 'ultimos30d' => 30, 'contas_criadas' => 2, 'ultima_em' => '2026-10-19 14:00:00', 'pausado' => true],
                ['user_id' => 8, 'nome' => 'Bia', 'email' => 'bia@x.com', 'escritorio' => null, 'total' => 10, 'ultimas24h' => 4, 'ultimos30d' => 10, 'contas_criadas' => 1, 'ultima_em' => null, 'pausado' => false],
            ];
        });
        $alertas = $this->createStub(IndicacaoAlertaRepository::class);
        $alertas->method('ultimos')->willReturn([new IndicacaoAlerta(Pessoas::usuario(7), 'Ana enviou 30 convites em 24h', $agora)]);

        $painel = (new ObterPainelDeIndicacoesUseCase($indicacoes, $this->configs, $alertas, $this->relogio))->executar(3, 1000);

        self::assertSame([40, 9, 3, 2, 3, 200], [$painel->enviadas, $painel->ultimas24h, $painel->viraramConta, $painel->totalDeIndicadores, $painel->pagina, $painel->porPagina]);
        self::assertSame(['2026-10-18 15:00', '2026-09-19 15:00', 400, 200], $janelas);
        self::assertSame(5, $painel->limites->alertaEm24h);
        self::assertSame('Ana enviou 30 convites em 24h', $painel->alertas[0]->texto);
        self::assertTrue($painel->indicadores[0]->acimaDoAlerta);
        self::assertTrue($painel->indicadores[0]->pausado);
        self::assertSame('2026-10-19 14:00', $painel->indicadores[0]->ultimaEm?->format('Y-m-d H:i'));
        self::assertFalse($painel->indicadores[1]->acimaDoAlerta);
        self::assertNull($painel->indicadores[1]->ultimaEm);
    }
}
