<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Recompensa\DTO\MeuSaldoDeRecompensaOutput;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\DadosDeRecebimentoDeRecompensaRepository;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Recompensa\UseCase\ConsultarPainelDeRecompensasUseCase;
use App\Recompensa\UseCase\ObterMeuSaldoDeRecompensaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(ObterMeuSaldoDeRecompensaUseCase::class)]
#[CoversClass(MeuSaldoDeRecompensaOutput::class)]
#[CoversClass(ConsultarPainelDeRecompensasUseCase::class)]
final class ObterMeuSaldoDeRecompensaUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-30 12:00:00';
    private const EU = 42;

    /** @var list<array<string, mixed>> */
    private array $indicadores = [];
    /** @var list<array<string, mixed>> */
    private array $contas = [];
    /** @var array<int, int> */
    private array $pagos = [];
    private ?ProgramaDeRecompensa $programa = null;
    private int $total = 0;
    private ?ConsultarPainelDeRecompensasUseCase $painel = null;

    #[TestDox('Quem nunca indicou: saldo zero, prêmio e mínimo da regra geral, cupom BJ- e contadores zerados')]
    public function testQuemNuncaIndicou(): void
    {
        $saldo = $this->executar();

        self::assertSame('R$ 0,00', $saldo->saldo());
        self::assertSame('10', $saldo->premio());
        self::assertSame('Libera com 10 escritórios ativos com pastas · você tem 0', $saldo->situacao());
        self::assertSame('BJ-ABCDEFGH', $saldo->cupom);
        self::assertSame([0, 0, 0], [$saldo->convites, $saldo->aguardando(), $saldo->contasAbertas]);
    }

    #[TestDox('Liberado: o saldo do painel (ativas x valor menos o pago) e "já recebeu"; aguardando = convites sem conta')]
    public function testLiberadoComPagamento(): void
    {
        $this->programa = new ProgramaDeRecompensa();
        $this->programa->alterarRegras(true, 1050, 2, 0, 50, false);
        $this->indicadores[] = ['user_id' => self::EU, 'nome' => 'Eu', 'email' => 'eu@exemplo.com', 'escritorio' => null, 'envios' => 5];
        for ($i = 0; $i < 3; ++$i) {
            $this->contas[] = [
                'indicador_id' => self::EU, 'conta_id' => 100 + $i, 'conta_criada_em' => '2026-09-01 10:00:00', 'tem_pasta' => false, 'aprovada' => false,
                'escritorios_conta' => [], 'escritorios_indicador' => [], 'cnpjs_conta' => [], 'cnpjs_indicador' => [],
                'dominio_conta' => 'conta' . $i . '.com.br', 'dominio_indicador' => 'eu.com.br',
            ];
        }
        $this->pagos = [self::EU => 1000];
        $this->total = 5;

        $saldo = $this->executar();
        $doPainel = array_values(array_filter($this->painel->executar()->indicadores, static fn ($l): bool => $l->userId === self::EU))[0];

        self::assertSame('R$ 21,50', $saldo->saldo());
        self::assertSame('10,50', $saldo->premio());
        self::assertSame('Liberado · 3 escritório(s) ativo(s) · já recebeu R$ 10,00', $saldo->situacao());
        self::assertSame([5, 2, 3], [$saldo->convites, $saldo->aguardando(), $saldo->contasAbertas]);
        self::assertEquals($doPainel, $this->painel->doIndicador(self::EU), 'a mesma linha do painel do Master');
        self::assertSame($doPainel->saldoCentavos, $saldo->saldoCentavos);
    }

    private function executar(): MeuSaldoDeRecompensaOutput
    {
        $indicacoes = $this->createStub(IndicacaoRepository::class);
        $indicacoes->method('indicadoresParaRecompensas')->willReturnCallback(fn (?int $id = null): array => array_values(array_filter($this->indicadores, static fn (array $l): bool => $id === null || $l['user_id'] === $id)));
        $indicacoes->method('contasParaRecompensas')->willReturnCallback(fn (?int $id = null): array => array_values(array_filter($this->contas, static fn (array $l): bool => $id === null || $l['indicador_id'] === $id)));
        $programas = $this->createStub(ProgramaDeRecompensaRepository::class);
        $programas->method('buscarUnico')->willReturnCallback(fn (): ?ProgramaDeRecompensa => $this->programa);
        $condicoes = $this->createStub(CondicaoDeRecompensaRepository::class);
        $lancamentos = $this->createStub(LancamentoDeRecompensaRepository::class);
        $lancamentos->method('somaDoBonusPorIndicador')->willReturn([]);
        $lancamentos->method('somaDoPagoPorIndicador')->willReturnCallback(fn (): array => $this->pagos);
        $painel = new ConsultarPainelDeRecompensasUseCase($indicacoes, $programas, $condicoes, $lancamentos, $this->createStub(DadosDeRecebimentoDeRecompensaRepository::class), new MockClock(new \DateTimeImmutable(self::AGORA)));
        $codigos = $this->createStub(IndicacaoCodigoRepository::class);
        $codigos->method('codigoDoUsuario')->willReturn('ABCDEFGH');
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, self::EU);

        $indicacoes->method('contarDoUsuario')->willReturnCallback(fn (): int => $this->total);
        $this->painel = $painel;

        return (new ObterMeuSaldoDeRecompensaUseCase($painel, $programas, $condicoes, new CodigoDeIndicacao($codigos, $this->createStub(UrlGeneratorInterface::class)), $indicacoes))->executar($user);
    }
}
