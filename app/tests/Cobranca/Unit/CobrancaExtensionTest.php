<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Twig\CobrancaExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Apresentação do módulo: dinheiro em centavos (`|centavos`) e o eixo temporal das listas do objeto
 * (`|tempo_relativo`, Ajuste 10, spec §4.7). O `$hoje` injetável blinda a comparação por DATA (não por
 * instante): sem isso, "vence hoje" viraria "em 1 dia" dependendo da hora em que a página é aberta.
 */
#[CoversClass(CobrancaExtension::class)]
final class CobrancaExtensionTest extends TestCase
{
    #[Test]
    #[DataProvider('casosDeTempoRelativo')]
    #[TestDox('tempo_relativo descreve a distância até hoje em português')]
    public function tempoRelativoDescreveADistanciaAteHoje(string $data, string $esperado): void
    {
        $hoje = new \DateTimeImmutable('2026-07-16');

        self::assertSame($esperado, (new CobrancaExtension())->tempoRelativo(new \DateTimeImmutable($data), $hoje));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function casosDeTempoRelativo(): iterable
    {
        yield 'vencida ha muito' => ['2026-03-10', 'há 128 dias'];
        yield 'vencida ontem' => ['2026-07-15', 'há 1 dia'];      // singular
        yield 'vence hoje' => ['2026-07-16', 'hoje'];
        yield 'vence amanha' => ['2026-07-17', 'em 1 dia'];      // singular
        yield 'vence longe' => ['2026-08-10', 'em 25 dias'];
    }

    #[Test]
    #[TestDox('tempo_relativo compara DATAS, não instantes: vencimento de hoje é "hoje" a qualquer hora')]
    public function tempoRelativoComparaDatasENaoInstantes(): void
    {
        // O vencimento é meia-noite; se `$hoje` fosse "agora" (com hora), o diff daria -1 dia e a
        // obrigação que vence hoje apareceria como "há 1 dia" à tarde. O default é `today`.
        $sut = new CobrancaExtension();

        self::assertSame('hoje', $sut->tempoRelativo(new \DateTimeImmutable('today')));
        self::assertSame('há 1 dia', $sut->tempoRelativo(new \DateTimeImmutable('yesterday')));
        self::assertSame('em 1 dia', $sut->tempoRelativo(new \DateTimeImmutable('tomorrow')));
    }

    #[Test]
    #[TestDox('centavos: inteiro do domínio vira dinheiro em pt-BR')]
    public function centavosFormataEmReais(): void
    {
        $sut = new CobrancaExtension();

        self::assertSame('R$ 1.234,56', $sut->centavos(123456));
        self::assertSame('R$ 0,00', $sut->centavos(null));
    }

    #[Test]
    #[TestDox('centavos_curto: mesmo dinheiro SEM o "R$" — as colunas de encargos não repetem o símbolo')]
    public function centavosCurtoFormataSemOSimboloDeMoeda(): void
    {
        $sut = new CobrancaExtension();

        self::assertSame('1.234,56', $sut->centavosCurto(123456));
        self::assertSame('13,60', $sut->centavosCurto(1360));
        self::assertSame('0,00', $sut->centavosCurto(0));
        self::assertSame('0,00', $sut->centavosCurto(null));
        // Negativo é possível numa correção que devolve valor: o sinal não pode sumir na formatação.
        self::assertSame('-13,60', $sut->centavosCurto(-1360));
    }

    #[Test]
    #[TestDox('centavos e centavos_curto não podem divergir: um é o outro com o prefixo')]
    public function centavosEhOCurtoComPrefixo(): void
    {
        $sut = new CobrancaExtension();

        // Duas aritméticas separadas divergiriam em silêncio no arredondamento — e são as mesmas
        // colunas de dinheiro da mesma linha da tela.
        foreach ([0, 1, 99, 1360, 123456, -500] as $centavos) {
            self::assertSame('R$ ' . $sut->centavosCurto($centavos), $sut->centavos($centavos));
        }
    }
}
