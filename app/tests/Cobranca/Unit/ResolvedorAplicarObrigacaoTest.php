<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedorConfigEncargos::class)]
final class ResolvedorAplicarObrigacaoTest extends TestCase
{
    #[Test]
    public function overrideDeJurosVenceABaseSemZerarOsHerdados(): void
    {
        $base = new ConfigEncargos(taxaJurosMensalBp: 100, taxaMultaBp: 200, taxaHonorariosBp: 2000);
        $obrigacao = (new Obrigacao())->setTaxaJurosMensalBp(150);

        $efetiva = (new ResolvedorConfigEncargos())->aplicarObrigacao($base, $obrigacao);

        self::assertSame(150, $efetiva->taxaJurosMensalBp, 'juros próprio');
        self::assertSame(200, $efetiva->taxaMultaBp, 'multa herdada intacta');
        self::assertSame(2000, $efetiva->taxaHonorariosBp, 'honorários herdados intactos');
    }

    #[Test]
    public function honorariosProprioVenceOCaso(): void
    {
        $base = new ConfigEncargos(taxaHonorariosBp: 2000);
        $obrigacao = (new Obrigacao())->setTaxaHonorariosBp(1500);

        $efetiva = (new ResolvedorConfigEncargos())->aplicarObrigacao($base, $obrigacao);

        self::assertSame(1500, $efetiva->taxaHonorariosBp);
    }

    #[Test]
    public function nullHerdaTudoDaBase(): void
    {
        $base = new ConfigEncargos(taxaJurosMensalBp: 100, baseMulta: BaseEncargo::Composta, taxaHonorariosBp: 2000);

        $efetiva = (new ResolvedorConfigEncargos())->aplicarObrigacao($base, new Obrigacao());

        self::assertSame(100, $efetiva->taxaJurosMensalBp);
        self::assertSame(BaseEncargo::Composta, $efetiva->baseMulta);
        self::assertSame(2000, $efetiva->taxaHonorariosBp);
    }
}
