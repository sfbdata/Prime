<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\PedidoDeMarcas;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaMarcaRepository;
use App\Comercial\UseCase\MarcarCampanhasDoRelatorioUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/** As marcas do Relatório (F3e; spec comercial-marcas.md, regras 2 a 4 e 8): o pedido e a gravação para o Master. */
#[CoversClass(MarcarCampanhasDoRelatorioUseCase::class)]
#[CoversClass(PedidoDeMarcas::class)]
final class MarcarCampanhasDoRelatorioUseCaseTest extends TestCase
{
    #[TestDox('Marca as campanhas pedidas para o Master, com a hora do relógio, e devolve quantas foram marcadas')]
    public function testMarca(): void
    {
        $agora = new \DateTimeImmutable('2026-10-21 10:00:00');
        $relogio = $this->createStub(ClockInterface::class);
        $relogio->method('now')->willReturn($agora);
        $repo = $this->createMock(CampanhaMarcaRepository::class);
        $repo->expects(self::once())->method('marcar')->with([3, 9], 42, 'arquivar', $agora)->willReturn(2);

        $pedido = PedidoDeMarcas::deJson(['ids' => [3, '9', 3, 'x', -1, 0, 99999999999], 'acao' => 'arquivar']);

        self::assertSame(2, (new MarcarCampanhasDoRelatorioUseCase($repo, $relogio))->executar($pedido, 42));
    }

    /** @return iterable<string, array{0: array<mixed>, 1: string}> */
    public static function pedidosRecusados(): iterable
    {
        yield 'ação fora da lista' => [['ids' => [1], 'acao' => 'apagar'], PedidoDeMarcas::ACAO_INVALIDA];
        yield 'sem ação' => [['ids' => [1]], PedidoDeMarcas::ACAO_INVALIDA];
        yield 'nenhum id' => [['ids' => [], 'acao' => 'estrela'], PedidoDeMarcas::SEM_CAMPANHA];
        yield 'nenhum id válido' => [['ids' => ['a', 0], 'acao' => 'estrela'], PedidoDeMarcas::SEM_CAMPANHA];
        yield 'mais de 50' => [['ids' => range(1, 51), 'acao' => 'remover'], PedidoDeMarcas::DEMAIS];
    }

    /** @param array<mixed> $json */
    #[DataProvider('pedidosRecusados')]
    #[TestDox('Pedido recusado: $mensagem')]
    public function testRecusa(array $json, string $mensagem): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage($mensagem);

        PedidoDeMarcas::deJson($json);
    }

    #[TestDox('Até 50 ids e as 5 ações da lista fechada passam')]
    public function testAcoesValidas(): void
    {
        foreach (CampanhaMarcaRepository::ACOES as $acao) {
            self::assertSame($acao, PedidoDeMarcas::deJson(['ids' => range(1, 50), 'acao' => $acao])->acao);
        }
        self::assertSame(['estrela', 'sem_estrela', 'arquivar', 'desarquivar', 'remover'], CampanhaMarcaRepository::ACOES);
    }
}
