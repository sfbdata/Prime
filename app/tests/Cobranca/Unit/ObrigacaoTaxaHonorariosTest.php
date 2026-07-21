<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Obrigacao;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObrigacaoTaxaHonorariosTest extends TestCase
{
    #[Test]
    public function taxaHonorariosBpDefaultEhNullEHerdaCaso(): void
    {
        self::assertNull((new Obrigacao())->getTaxaHonorariosBp());
    }

    #[Test]
    public function taxaHonorariosBpAceitaOverrideEmBasisPoints(): void
    {
        $obrigacao = (new Obrigacao())->setTaxaHonorariosBp(1500);

        self::assertSame(1500, $obrigacao->getTaxaHonorariosBp());
    }
}
