<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\LeitorDeDatas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorDeDatas::class)]
final class LeitorDeDatasTest extends TestCase
{
    private const BASE = '2026-10-16';

    /** @return list<array{tipo: string, titulo: string, data: string, hora: string, estimado: bool, frase: string}> */
    private static function ler(string $texto): array
    {
        return LeitorDeDatas::ler($texto, new \DateTimeImmutable(self::BASE));
    }

    #[TestDox('"Prazo 16/10/2026 e prazo 17/10/2026" vira dois prazos com a frase como título (o caso do registro do dono)')]
    public function testDoisPrazosNumaFrase(): void
    {
        $r = self::ler('Prazo 16/10/2026 e prazo 17/10/2026');

        self::assertSame(['2026-10-16', '2026-10-17'], array_column($r, 'data'));
        self::assertSame(['prazo', 'prazo'], array_column($r, 'tipo'));
        self::assertSame('Prazo 16/10/2026 e prazo 17/10/2026', $r[0]['titulo']);
        self::assertFalse($r[0]['estimado']);
    }

    #[TestDox('sem ano vale o ano da data-base; se já passou, o seguinte')]
    public function testSemAno(): void
    {
        self::assertSame('2026-11-15', self::ler('Prazo 15/11')[0]['data']);
        self::assertSame('2027-03-01', self::ler('Prazo 01/03')[0]['data']);
    }

    #[TestDox('audiência com data por extenso e hora vira tipo audiência, título "Audiência" e hora')]
    public function testAudienciaPorExtenso(): void
    {
        $r = self::ler('Audiência de conciliação em 20 de novembro de 2026, às 14h30.');

        self::assertCount(1, $r);
        self::assertSame(['audiencia', 'Audiência', '2026-11-20', '14:30'], [$r[0]['tipo'], $r[0]['titulo'], $r[0]['data'], $r[0]['hora']]);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function tipos(): iterable
    {
        yield 'perícia' => ['Perícia marcada para 10/12/2026', 'Perícia'];
        yield 'contestação' => ['Contestar até 05/11/2026', 'Prazo para contestação'];
        yield 'recurso' => ['Apelação até 03/11/2026', 'Prazo recursal'];
        yield 'manifestação' => ['Manifestar sobre o laudo até 04/11/2026', 'Prazo para manifestação'];
        yield 'protocolo' => ['Protocolar petição 06/11/2026', 'Protocolo'];
        yield 'pagamento' => ['Boleto vence 07/11/2026', 'Vencimento de pagamento'];
        yield 'reunião' => ['Reunião com o cliente 08/11/2026 às 10:00', 'Reunião'];
    }

    #[DataProvider('tipos')]
    #[TestDox('a palavra da frase decide o título do prazo')]
    public function testTipos(string $texto, string $titulo): void
    {
        self::assertSame($titulo, self::ler($texto)[0]['titulo']);
    }

    #[TestDox('frase sem palavra de prazo não vira nada, mesmo com data')]
    public function testSemPalavraDePrazo(): void
    {
        self::assertSame([], self::ler('Cliente ligou em 20/11/2026 perguntando do caso.'));
    }

    #[TestDox('"prazo de 15 dias úteis" vira data estimada contada em dias úteis; "corridos" conta corridos')]
    public function testPrazoEmDias(): void
    {
        $uteis = self::ler('Intimados, com prazo de 15 dias úteis.');
        self::assertTrue($uteis[0]['estimado']);
        self::assertSame('2026-11-09', $uteis[0]['data'], '16/10 + 15 úteis, pulando fins de semana e o feriado de 02/11');
        self::assertSame('2026-10-26', self::ler('Prazo de 10 dias corridos.')[0]['data']);
    }

    #[TestDox('"amanhã" e "hoje" valem')]
    public function testAmanhaHoje(): void
    {
        self::assertSame('2026-10-17', self::ler('Prazo vence amanhã.')[0]['data']);
        self::assertSame('2026-10-16', self::ler('Prazo vence hoje.')[0]['data']);
    }

    #[TestDox('contexto passado ("realizada", "protocolado em") é ignorado')]
    public function testPassado(): void
    {
        self::assertSame([], self::ler('Audiência realizada 10/10/2026.'));
        self::assertSame([], self::ler('Recurso protocolado em 01/10/2026.'));
    }

    #[TestDox('remarcação usa a data depois do "para"')]
    public function testRemarcacao(): void
    {
        $r = self::ler('Audiência de 20/11/2026 redesignada para 05/12/2026.');

        self::assertCount(1, $r);
        self::assertSame('2026-12-05', $r[0]['data']);
    }

    #[TestDox('datas inválidas e números colados (CNJ, CPF) não viram data')]
    public function testInvalidos(): void
    {
        self::assertSame([], self::ler('Prazo 31/02/2026'));
        self::assertSame([], self::ler('Prazo do processo 0700012-34.2026.8.07.0001'));
    }

    #[TestDox('parágrafos do editor separam frases: o tipo de um não contamina o outro')]
    public function testParagrafos(): void
    {
        $r = self::ler(LeitorDeDatas::textoDoHtml('<p>Prazo 15/11/2026</p><p>Audiência 20/11/2026</p>'));

        self::assertSame(['prazo', 'audiencia'], array_column($r, 'tipo'));
    }

    #[TestDox('dia útil: fim de semana, feriado fixo e recesso de 20/12 a 20/01 não contam')]
    public function testDiaUtil(): void
    {
        self::assertFalse(LeitorDeDatas::diaUtil(new \DateTimeImmutable('2026-10-17')));
        self::assertFalse(LeitorDeDatas::diaUtil(new \DateTimeImmutable('2026-11-02')));
        self::assertFalse(LeitorDeDatas::diaUtil(new \DateTimeImmutable('2026-12-22')));
        self::assertTrue(LeitorDeDatas::diaUtil(new \DateTimeImmutable('2026-10-19')));
    }
}
