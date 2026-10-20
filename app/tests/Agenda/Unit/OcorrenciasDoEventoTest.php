<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Service\OcorrenciasDoEvento;
use App\Agenda\Entity\Evento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(OcorrenciasDoEvento::class)]
final class OcorrenciasDoEventoTest extends TestCase
{
    #[TestDox('Evento simples aparece só se cruza o período')]
    public function testSimples(): void
    {
        $e = $this->evento('2030-03-10 09:00', '2030-03-10 10:00');

        self::assertCount(1, $this->ocorrencias($e, '2030-03-01', '2030-03-31'));
        self::assertSame([], $this->ocorrencias($e, '2030-04-01', '2030-04-30'));
    }

    #[TestDox('Semanal gera uma ocorrência por semana dentro do período, mantendo a duração')]
    public function testSemanal(): void
    {
        $e = $this->evento('2030-02-25 09:00', '2030-02-25 10:30', 'semanal');
        $o = $this->ocorrencias($e, '2030-03-01', '2030-03-31');

        self::assertSame(['2030-03-04', '2030-03-11', '2030-03-18', '2030-03-25'], array_map(static fn (array $x): string => $x['inicio']->format('Y-m-d'), $o));
        self::assertSame('10:30', $o[0]['fim']->format('H:i'));
    }

    #[TestDox('Fim da recorrência corta as ocorrências')]
    public function testFimDaRecorrencia(): void
    {
        $e = $this->evento('2030-03-01 09:00', '2030-03-01 10:00', 'diario');
        $e->setFimRecorrencia(new \DateTimeImmutable('2030-03-03 23:59'));

        self::assertCount(3, $this->ocorrencias($e, '2030-03-01', '2030-03-31'));
    }

    #[TestDox('Diário criado há anos continua aparecendo no período (o limite de 366 conta só dentro dele)')]
    public function testDiarioAntigo(): void
    {
        $e = $this->evento('2025-01-01 09:00', '2025-01-01 10:00', 'diario');

        self::assertCount(31, $this->ocorrencias($e, '2030-03-01', '2030-03-31'));
    }

    /** @return list<array{inicio: \DateTimeImmutable, fim: \DateTimeImmutable}> */
    private function ocorrencias(Evento $e, string $de, string $ate): array
    {
        return (new OcorrenciasDoEvento())->noPeriodo($e, new \DateTimeImmutable($de), (new \DateTimeImmutable($ate))->setTime(23, 59, 59));
    }

    private function evento(string $inicio, string $fim, ?string $recorrencia = null): Evento
    {
        $e = new Evento();
        $e->setDataInicio(new \DateTimeImmutable($inicio));
        $e->setDataFim(new \DateTimeImmutable($fim));
        if (null !== $recorrencia) {
            $e->setRecorrente(true);
            $e->setTipoRecorrencia($recorrencia);
        }

        return $e;
    }
}
