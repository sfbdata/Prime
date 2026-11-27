<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\UseCase\DecisaoSobreGrupoDeDuplicados;
use App\Pasta\UseCase\EnviarDuplicadosParaALixeiraUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra de qual cópia fica (`decidir`), pura, sem banco nem dublê. A gravação (lixeira, simulação,
 * releitura na transação, escopo) é provada com banco no `ApagarDuplicadosCommandTest` (functional).
 */
#[CoversClass(EnviarDuplicadosParaALixeiraUseCase::class)]
#[CoversClass(DecisaoSobreGrupoDeDuplicados::class)]
final class EnviarDuplicadosParaALixeiraUseCaseTest extends TestCase
{
    /**
     * @param list<int> $ids do mais antigo ao mais novo
     *
     * @return array{tenant_id: int, pasta_id: int, secao_id: int|null, sha256: string, ids: list<int>, tamanhos: list<int>}
     */
    private function grupo(array $ids): array
    {
        return ['tenant_id' => 7, 'pasta_id' => 50, 'secao_id' => null, 'sha256' => str_repeat('a', 64), 'ids' => $ids, 'tamanhos' => array_map(static fn (int $i): int => $i * 10, $ids)];
    }

    #[TestDox('sem vínculo: fica a mais antiga, as outras vão para a lixeira, com os bytes somados')]
    public function testSemVinculoFicaAMaisAntiga(): void
    {
        $d = EnviarDuplicadosParaALixeiraUseCase::decidir($this->grupo([1, 2, 3]), []);

        self::assertSame(1, $d->mantidoId);
        self::assertSame([2, 3], $d->enviadosIds);
        self::assertSame(50, $d->bytesEnviados, '20 + 30');
        self::assertSame(DecisaoSobreGrupoDeDuplicados::MAIS_ANTIGA, $d->motivo);
        self::assertFalse($d->foiPulado());
    }

    #[TestDox('vínculo na cópia mais nova: ela fica e a antiga vai para a lixeira')]
    public function testVinculoNaCopiaNovaFica(): void
    {
        $d = EnviarDuplicadosParaALixeiraUseCase::decidir($this->grupo([1, 2]), [2 => ['favorito']]);

        self::assertSame(2, $d->mantidoId);
        self::assertSame([1], $d->enviadosIds);
        self::assertSame(DecisaoSobreGrupoDeDuplicados::COM_VINCULO, $d->motivo);
    }

    #[TestDox('duas cópias com vínculo: grupo pulado, ninguém vai para a lixeira')]
    public function testDoisVinculosPulaOGrupo(): void
    {
        $d = EnviarDuplicadosParaALixeiraUseCase::decidir($this->grupo([1, 2, 3]), [1 => ['assinatura'], 3 => ['cliente_documento']]);

        self::assertTrue($d->foiPulado());
        self::assertNull($d->mantidoId);
        self::assertSame([], $d->enviadosIds);
        self::assertSame(DecisaoSobreGrupoDeDuplicados::VARIOS_VINCULOS, $d->motivo);
    }

    #[TestDox('a decisão carrega escritório, pasta e seção do grupo')]
    public function testCarregaOEscopoDoGrupo(): void
    {
        $g = $this->grupo([1, 2]);
        $g['secao_id'] = 9;

        $d = EnviarDuplicadosParaALixeiraUseCase::decidir($g, []);

        self::assertSame([7, 50, 9], [$d->tenantId, $d->pastaId, $d->secaoId]);
    }
}
