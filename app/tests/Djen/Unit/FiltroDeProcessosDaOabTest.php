<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\LinhaDoProcessoDaOab;
use App\Djen\Service\FiltroDeProcessosDaOab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FiltroDeProcessosDaOab::class)]
#[CoversClass(LinhaDoProcessoDaOab::class)]
final class FiltroDeProcessosDaOabTest extends TestCase
{
    private const HOJE = '2026-10-26';

    private function linha(string $numero, ?string $ultimo, ?int $pasta = null, string $partes = '', ?string $oab = '49613/DF', ?string $area = null, ?string $fase = null): LinhaDoProcessoDaOab
    {
        return new LinhaDoProcessoDaOab($numero, null, 'TJDFT', $partes, 'Vara Cível', $area, $fase, null, null, $ultimo !== null ? new \DateTimeImmutable($ultimo) : null, $oab, $pasta, $pasta !== null ? 'NUP-' . $pasta : null, 1);
    }

    /** @return list<LinhaDoProcessoDaOab> */
    private function conjunto(): array
    {
        return [
            $this->linha('07022222220268070001', self::HOJE, 1, 'Ana x Banco', '49613/DF', 'Cível', 'Ação protocolada'),
            $this->linha('07022222220268070002', '2026-10-20', null, 'Bia x Loja', '10/SP', 'Família'),
            $this->linha('07022222220268070003', '2026-09-01', 2, 'Caio x INSS', '49613/DF', 'Previdenciário'),
            $this->linha('07022222220268070004', null, null, '', null),
        ];
    }

    #[Test]
    public function numeroGanhaAMascaraDoCnj(): void
    {
        self::assertSame('0702222-22.2026.8.07.0001', $this->linha('07022222220268070001', null)->numeroComMascara());
        self::assertSame('123', $this->linha('123', null)->numeroComMascara(), 'fora do padrão, como veio');
    }

    #[Test]
    public function indicadoresContamMovidosParadosNuncaESemPasta(): void
    {
        $r = (new FiltroDeProcessosDaOab())->indicadores($this->conjunto(), new \DateTimeImmutable(self::HOJE));

        self::assertSame(['processos' => 4, 'movidos7' => 2, 'parados30' => 1, 'nuncaCapturados' => 1, 'semPasta' => 2], $r);
    }

    /**
     * @param list<string> $esperados sufixos dos números
     * @param array<string, string> $criterios
     */
    #[Test]
    #[DataProvider('casos')]
    public function filtrarAplicaOsCriterios(array $criterios, array $esperados): void
    {
        $r = (new FiltroDeProcessosDaOab())->filtrar($this->conjunto(), $criterios, new \DateTimeImmutable(self::HOJE));

        self::assertSame($esperados, array_map(static fn (LinhaDoProcessoDaOab $l): string => substr($l->numero, -1), $r));
    }

    /** @return iterable<string, array{array<string, string>, list<string>}> */
    public static function casos(): iterable
    {
        yield 'sem critério' => [[], ['1', '2', '3', '4']];
        yield 'busca por parte sem acento' => [['busca' => 'banco'], ['1']];
        yield 'busca pelo número com máscara' => [['busca' => '0702222-22.2026.8.07.0003'], ['3']];
        yield 'oab' => [['oab' => '49613/DF'], ['1', '3']];
        yield 'movidos hoje' => [['mov' => 'h'], ['1']];
        yield 'últimos 7 dias' => [['mov' => '7'], ['1', '2']];
        yield 'parados há mais de 30' => [['mov' => 'p30'], ['3']];
        yield 'nunca capturados' => [['mov' => 'nc'], ['4']];
        yield 'área' => [['area' => 'Família'], ['2']];
        yield 'fase' => [['fase' => 'Ação protocolada'], ['1']];
        yield 'com pasta' => [['pasta' => 'c'], ['1', '3']];
        yield 'sem pasta' => [['pasta' => 's'], ['2', '4']];
    }
}
