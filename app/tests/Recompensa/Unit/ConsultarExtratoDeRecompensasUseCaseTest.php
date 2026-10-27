<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Recompensa\UseCase\ConsultarExtratoDeRecompensasUseCase;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsultarExtratoDeRecompensasUseCase::class)]
final class ConsultarExtratoDeRecompensasUseCaseTest extends TestCase
{
    #[TestDox('a tela pede 60 e a exportação pede todos; o filtro por indicador vai junto; a saída tem o indicador e nada do indicado')]
    public function testLimitesEFiltro(): void
    {
        $pedidos = [];
        $lancamento = new LancamentoDeRecompensa(Pessoas::usuario(5, 'Bia Indicadora'), 'bonus', -250, 'erro', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', Pessoas::master(), new \DateTimeImmutable('2026-10-26 10:00:00'));
        $repo = $this->createStub(LancamentoDeRecompensaRepository::class);
        $repo->method('doExtrato')->willReturnCallback(function (?int $userId, ?int $limite) use (&$pedidos, $lancamento): array {
            $pedidos[] = [$userId, $limite];

            return [$lancamento];
        });
        $caso = new ConsultarExtratoDeRecompensasUseCase($repo);

        $recentes = $caso->recentes(5);
        $caso->todos();
        $caso->recentes();

        self::assertSame([[5, 60], [null, null], [null, 60]], $pedidos);
        self::assertCount(1, $recentes);
        $linha = $recentes[0]->paraArray();
        self::assertSame([5, 'Bia Indicadora', 'pessoa5@exemplo.com', 'bonus', -250, 'erro'], [$linha['userId'], $linha['nome'], $linha['email'], $linha['tipo'], $linha['valorCentavos'], $linha['observacao']]);
        self::assertSame('2026-10-26 10:00:00', $recentes[0]->em->format('Y-m-d H:i:s'));
        self::assertSame(['id', 'em', 'userId', 'nome', 'email', 'tipo', 'valorCentavos', 'observacao', 'forma', 'temComprovante'], array_keys($linha), 'nenhum campo do indicado');
    }
}
