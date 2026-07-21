<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
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
        // Guard de regressão do refactor de dinheiro (taxa por-obrigação): os DEZ campos de
        // `ConfigEncargos` têm de herdar da base quando a obrigação não tem NENHUM override próprio.
        // Cada campo entra com um valor DISTINTO e NÃO-DEFAULT: se o overlay esquecesse de herdar um
        // deles, o efetivo cairia no default de `ConfigEncargos` (0/Simples/Principal/Composta) — dez
        // valores iguais ao default não denunciariam nada. `baseHonorarios` usa Principal de propósito
        // porque Composta já É o default (senão a asserção não provaria a herança desse campo).
        $base = new ConfigEncargos(
            taxaJurosMensalBp: 100,
            regimeJuros: RegimeJuros::Composto,
            taxaMultaBp: 200,
            baseMulta: BaseEncargo::Composta,
            taxaCorrecaoBp: 300,
            baseCorrecao: BaseEncargo::Composta,
            taxaHonorariosBp: 2000,
            baseHonorarios: BaseEncargo::Principal,
            carenciaHonorariosDias: 30,
            toleranciaJurosMultaDias: 5,
        );

        $efetiva = (new ResolvedorConfigEncargos())->aplicarObrigacao($base, new Obrigacao());

        self::assertSame(100, $efetiva->taxaJurosMensalBp, 'juros herdado');
        self::assertSame(RegimeJuros::Composto, $efetiva->regimeJuros, 'regime de juros herdado');
        self::assertSame(200, $efetiva->taxaMultaBp, 'multa herdada');
        self::assertSame(BaseEncargo::Composta, $efetiva->baseMulta, 'base da multa herdada');
        self::assertSame(300, $efetiva->taxaCorrecaoBp, 'correção herdada');
        self::assertSame(BaseEncargo::Composta, $efetiva->baseCorrecao, 'base da correção herdada');
        self::assertSame(2000, $efetiva->taxaHonorariosBp, 'honorários herdados');
        self::assertSame(BaseEncargo::Principal, $efetiva->baseHonorarios, 'base dos honorários herdada');
        self::assertSame(30, $efetiva->carenciaHonorariosDias, 'carência de honorários herdada');
        self::assertSame(5, $efetiva->toleranciaJurosMultaDias, 'tolerância de juros/multa herdada');
    }
}
