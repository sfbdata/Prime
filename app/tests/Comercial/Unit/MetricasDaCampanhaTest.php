<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\MetricasDaCampanha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricasDaCampanha::class)]
final class MetricasDaCampanhaTest extends TestCase
{
    #[TestDox('Percentuais sobre os enviados, não lidos = entregues menos lidos, ✓✓ com o % quando alguém leu')]
    public function testAgregacaoLida(): void
    {
        $m = new MetricasDaCampanha(10, 2, 8, 6, 2, 1, 1);

        self::assertSame(4, $m->naoLidos());
        self::assertSame('25%', $m->taxaDeLeitura());
        self::assertSame(MetricasDaCampanha::LIDO, $m->leitura());
        self::assertSame(
            "Enviados: 8\nEntregues (recebidos): 6 (75%)\nAbertos e lidos: 2 (25%)\nNão lidos: 4\nRejeitados: 1\n\n" . MetricasDaCampanha::LEGENDA,
            $m->dica(),
        );
        $a = $m->paraArray();
        self::assertSame(['25%', 'lido', 10, 2], [$a['percentual'], $a['leitura'], $a['destinatarios'], $a['naFila']]);
    }

    #[TestDox('Sem leitura: ✓ verde (entregue) sem %, ou ✓ cinza (enviado); sem rejeitado, a linha some do tooltip')]
    public function testIcones(): void
    {
        $entregue = new MetricasDaCampanha(3, 0, 3, 3, 0, 0, 0);
        self::assertSame([MetricasDaCampanha::ENTREGUE, ''], [$entregue->leitura(), $entregue->paraArray()['percentual']]);
        self::assertStringNotContainsString('Rejeitados', $entregue->dica());

        $semEnvio = MetricasDaCampanha::deArray(null, 7);
        self::assertSame([7, 7, 0, MetricasDaCampanha::ENVIADO, '0%'], [$semEnvio->destinatarios, $semEnvio->naFila, $semEnvio->enviados, $semEnvio->leitura(), $semEnvio->taxaDeLeitura()]);
        self::assertSame(0, (new MetricasDaCampanha(2, 0, 2, 0, 1, 0, 0))->naoLidos());
    }
}
