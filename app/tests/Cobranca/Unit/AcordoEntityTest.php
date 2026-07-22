<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cobre a identidade externa do acordo (tarefa #7-A, spec `cobranca-importar-linhas-acordo.md`
 * §3.3/§4): `numeroExterno`/`numeroParcelasTotal` e os helpers derivados `estaIncompleto()`/
 * `parcelasFaltantes()`. Sem banco — as parcelas são injetadas via reflection na coleção `parcelas`
 * (lado inverso do `OneToMany mappedBy: 'acordoOrigem'`, só sincronizado pelo Doctrine na
 * hidratação real; em memória, `setAcordoOrigem()` do lado da `Obrigacao` não preenche o inverso).
 */
#[CoversClass(Acordo::class)]
final class AcordoEntityTest extends TestCase
{
    #[Test]
    public function numeroExternoENumeroParcelasTotalSaoNulosPorDefault(): void
    {
        $acordo = new Acordo();

        self::assertNull($acordo->getNumeroExterno());
        self::assertNull($acordo->getNumeroParcelasTotal());
    }

    #[Test]
    public function setNumeroExternoDefineOValor(): void
    {
        $acordo = new Acordo();

        $acordo->setNumeroExterno(28);

        self::assertSame(28, $acordo->getNumeroExterno());
    }

    #[Test]
    public function setNumeroParcelasTotalDefineOValor(): void
    {
        $acordo = new Acordo();

        $acordo->setNumeroParcelasTotal(3);

        self::assertSame(3, $acordo->getNumeroParcelasTotal());
    }

    #[Test]
    public function acordoManualSemNumeroParcelasTotalNuncaEstaIncompleto(): void
    {
        $acordo = new Acordo();

        self::assertFalse($acordo->estaIncompleto());
        self::assertSame(0, $acordo->parcelasFaltantes());
    }

    #[Test]
    public function acordoComTotalMaiorQueParcelasCadastradasEstaIncompleto(): void
    {
        $acordo = $this->acordoComParcelas(1);
        $acordo->setNumeroParcelasTotal(3);

        self::assertTrue($acordo->estaIncompleto());
        self::assertSame(2, $acordo->parcelasFaltantes());
    }

    #[Test]
    public function acordoComTotalIgualAoNumeroDeParcelasNaoEstaIncompleto(): void
    {
        $acordo = $this->acordoComParcelas(3);
        $acordo->setNumeroParcelasTotal(3);

        self::assertFalse($acordo->estaIncompleto());
        self::assertSame(0, $acordo->parcelasFaltantes());
    }

    #[Test]
    public function acordoComMaisParcelasQueOTotalNaoDaFaltantesNegativas(): void
    {
        // Cenário defensivo (não deveria ocorrer na prática): total registrado menor que as
        // parcelas já cadastradas não pode gerar "faltam -2 parcelas".
        $acordo = $this->acordoComParcelas(5);
        $acordo->setNumeroParcelasTotal(3);

        self::assertFalse($acordo->estaIncompleto());
        self::assertSame(0, $acordo->parcelasFaltantes());
    }

    private function acordoComParcelas(int $quantidade): Acordo
    {
        $acordo = new Acordo();
        $parcelas = new ArrayCollection();
        for ($i = 0; $i < $quantidade; $i++) {
            $parcelas->add(new Obrigacao());
        }

        (new \ReflectionProperty(Acordo::class, 'parcelas'))->setValue($acordo, $parcelas);

        return $acordo;
    }
}
