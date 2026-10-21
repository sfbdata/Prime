<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Shared\Prazo\RegraDoPrazo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `Tarefa::getPrazoInfo` conta os dias como a RegraDoPrazo: dias de calendário, sem a hora gravada no prazo.
 * Antes, o diff entre "hoje 00:00" e um prazo de ontem às 23:59 dava 0 ("Vence hoje"), enquanto a Pasta
 * mostrava "Vencido há 1 dia". O formato do array (dias, cor, texto) não muda.
 */
#[CoversClass(Tarefa::class)]
final class TarefaPrazoInfoRegraDoPrazoTest extends TestCase
{
    private function metaComPrazo(string $prazo): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Meta');
        $meta->setDescricao('Descrição');
        $meta->setPasta(new Pasta());
        $meta->setPrazo(new \DateTimeImmutable($prazo));

        return $meta;
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3: string}> prazo, hoje, dias, texto */
    public static function bordasDoDia(): iterable
    {
        yield 'ontem 23:59, visto hoje 00:00' => ['2026-10-19 23:59:00', '2026-10-20 00:00:00', -1, '1 dia(s) atrasado'];
        yield 'ontem 23:59, visto hoje 00:01' => ['2026-10-19 23:59:00', '2026-10-20 00:01:00', -1, '1 dia(s) atrasado'];
        yield 'ontem 23:59, visto hoje 23:59' => ['2026-10-19 23:59:00', '2026-10-20 23:59:00', -1, '1 dia(s) atrasado'];
        yield 'hoje 00:01, visto hoje 00:00' => ['2026-10-20 00:01:00', '2026-10-20 00:00:00', 0, 'Vence hoje'];
        yield 'hoje 00:01, visto hoje 23:59' => ['2026-10-20 00:01:00', '2026-10-20 23:59:00', 0, 'Vence hoje'];
        yield 'hoje 23:59, visto hoje 00:01' => ['2026-10-20 23:59:00', '2026-10-20 00:01:00', 0, 'Vence hoje'];
        yield 'hoje 23:59, visto hoje 23:59' => ['2026-10-20 23:59:00', '2026-10-20 23:59:00', 0, 'Vence hoje'];
        yield 'amanhã 00:00, visto hoje 00:01' => ['2026-10-21 00:00:00', '2026-10-20 00:01:00', 1, '1 dia'];
        yield 'amanhã 00:00, visto hoje 23:59' => ['2026-10-21 00:00:00', '2026-10-20 23:59:00', 1, '1 dia'];
        yield 'virada: hoje 23:59, visto amanhã 00:00' => ['2026-10-20 23:59:00', '2026-10-21 00:00:00', -1, '1 dia(s) atrasado'];
        yield 'virada: amanhã 00:00, visto amanhã 00:00' => ['2026-10-21 00:00:00', '2026-10-21 00:00:00', 0, 'Vence hoje'];
    }

    #[DataProvider('bordasDoDia')]
    #[TestDox('nas bordas do dia, a mesma contagem e o mesmo veredito da RegraDoPrazo')]
    public function testMesmaContagemDaRegraDoPrazo(string $prazo, string $hoje, int $diasEsperados, string $texto): void
    {
        $agora = new \DateTimeImmutable($hoje);
        $meta  = $this->metaComPrazo($prazo);

        $info = $meta->getPrazoInfo($agora);

        self::assertSame(RegraDoPrazo::diasAte(new \DateTimeImmutable($prazo), $agora), $info['dias']);
        self::assertSame($diasEsperados, $info['dias']);
        self::assertSame($texto, $info['texto']);
        // Mesmo veredito: vencido aqui se e só se a RegraDoPrazo diz "Vencido"; hoje se e só se diz "Hoje".
        $selo = RegraDoPrazo::selo(RegraDoPrazo::diasAte(new \DateTimeImmutable($prazo), $agora));
        self::assertSame(str_starts_with($selo, 'Vencido'), str_contains($info['texto'], 'atrasado'));
        self::assertSame($selo === 'Hoje', $info['texto'] === 'Vence hoje');
    }

    #[TestDox('sem hoje informado, um prazo de ontem às 23:59 já é atrasado (antes dava "Vence hoje")')]
    public function testSemHojeInformadoUsaODiaCorrente(): void
    {
        $prazo = (new \DateTimeImmutable('yesterday'))->setTime(23, 59);
        $meta  = $this->metaComPrazo($prazo->format('Y-m-d H:i:s'));

        $info = $meta->getPrazoInfo();

        self::assertSame(-1, $info['dias']);
        self::assertSame(RegraDoPrazo::diasAte($prazo), $info['dias']);
        self::assertSame('danger', $info['cor']);
        self::assertSame('1 dia(s) atrasado', $info['texto']);
    }

    #[TestDox('o formato do array não muda: chaves dias, cor e texto, e a cor segue as faixas de antes')]
    public function testFormatoECoresMantidos(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-20 15:00:00');

        self::assertSame(['dias' => 4, 'cor' => 'success', 'texto' => '4 dias'], $this->metaComPrazo('2026-10-24 08:00:00')->getPrazoInfo($hoje));
        self::assertSame(['dias' => 3, 'cor' => 'warning', 'texto' => '3 dias'], $this->metaComPrazo('2026-10-23 23:59:00')->getPrazoInfo($hoje));
        self::assertSame(['dias' => 0, 'cor' => 'danger', 'texto' => 'Vence hoje'], $this->metaComPrazo('2026-10-20 00:00:00')->getPrazoInfo($hoje));
    }
}
