<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Pasta\Service\LeitorDeDatas;
use App\Pasta\Service\PrazosDosRegistros;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O marco `PRAZOS_CALENDARIO_DESDE` (docs/specs/prazo-feriado-calendario.md, seção 3): vazio desliga o calendário
 * para todo registro, antigo ou novo; definido, só vale para registro criado a partir dele.
 */
#[CoversClass(PrazosDosRegistros::class)]
final class PrazosDosRegistrosMarcoTest extends TestCase
{
    private function prazos(string $desde): PrazosDosRegistros
    {
        return new PrazosDosRegistros($this->createStub(EntityManagerInterface::class), new DiasUteisDoEscritorio(new CalendarioDoSistema()), $desde);
    }

    /** @return list<array{tipo: string, titulo: string, data: string, hora: string, estimado: bool, frase: string}> */
    private function ler(PrazosDosRegistros $prazos, string $texto, \DateTimeImmutable $criadoEm): array
    {
        return LeitorDeDatas::ler($texto, $criadoEm, $prazos->calendarioDoRegistro($criadoEm, 'AL'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function desligados(): iterable
    {
        yield 'vazio' => [''];
        yield 'só espaços' => ['   '];
        yield 'inválido' => ['21/10/2026'];
        yield 'lixo' => ['amanhã'];
        yield 'data que não existe' => ['2026-02-30'];
    }

    #[DataProvider('desligados')]
    #[TestDox('marco "$desde": desligado, registro antigo e registro criado agora dão o mesmo que hoje')]
    public function testMarcoDesligado(string $desde): void
    {
        $prazos = $this->prazos($desde);
        $agora = new \DateTimeImmutable();

        self::assertNull($prazos->calendarioDoRegistro(new \DateTimeImmutable('2020-01-01'), 'AL'));
        self::assertNull($prazos->calendarioDoRegistro($agora, 'AL'));
        self::assertNull($prazos->calendarioDoRegistro(new \DateTimeImmutable('2100-01-01'), 'AL'));
        // Sexta-feira Santa e corridos no sábado: os dois casos que só mudam no modo novo.
        self::assertSame('2026-04-06', $this->ler($prazos, 'Prazo de 5 dias para manifestação.', new \DateTimeImmutable('2026-03-30 10:00'))[0]['data']);
        self::assertSame('2026-04-18', $this->ler($prazos, 'Prazo de 10 dias corridos.', new \DateTimeImmutable('2026-04-08 10:00'))[0]['data']);
        $hojeSemCalendario = LeitorDeDatas::ler('Prazo de 10 dias corridos.', $agora);
        self::assertSame($hojeSemCalendario, $this->ler($prazos, 'Prazo de 10 dias corridos.', $agora));
    }

    #[TestDox('marco inválido desliga e avisa no log; vazio e válido não avisam')]
    public function testMarcoInvalidoAvisaNoLog(): void
    {
        $avisos = static function (string $desde): array {
            $handler = new TestHandler();
            new PrazosDosRegistros(self::createStub(EntityManagerInterface::class), new DiasUteisDoEscritorio(new CalendarioDoSistema()), $desde, new Logger('teste', [$handler]));

            return $handler->getRecords();
        };

        $registros = $avisos('21/10/2026');
        self::assertCount(1, $registros);
        self::assertSame('WARNING', $registros[0]->level->getName());
        self::assertSame('21/10/2026', $registros[0]->context['valor']);
        self::assertSame([], $avisos(''));
        self::assertSame([], $avisos('2026-10-22'));
    }

    #[TestDox('registro criado antes do marco: cálculo antigo, mesmo relido depois')]
    public function testRegistroAntesDoMarco(): void
    {
        $prazos = $this->prazos('2026-04-01');

        self::assertNull($prazos->calendarioDoRegistro(new \DateTimeImmutable('2026-03-31 23:59:59'), 'AL'));
        self::assertSame('2026-04-06', $this->ler($prazos, 'Prazo de 5 dias para manifestação.', new \DateTimeImmutable('2026-03-30 10:00'))[0]['data']);
    }

    #[TestDox('registro criado no dia do marco ou depois: calendário do sistema com a UF do escritório')]
    public function testRegistroDepoisDoMarco(): void
    {
        $prazos = $this->prazos('2026-03-30');

        self::assertNotNull($prazos->calendarioDoRegistro(new \DateTimeImmutable('2026-03-30 00:00'), 'AL'));
        self::assertSame('2026-04-07', $this->ler($prazos, 'Prazo de 5 dias para manifestação.', new \DateTimeImmutable('2026-03-30 10:00'))[0]['data']);
        self::assertSame('2026-04-20', $this->ler($prazos, 'Prazo de 10 dias corridos.', new \DateTimeImmutable('2026-04-08 10:00'))[0]['data']);
        self::assertSame('2026-09-18', $this->ler($prazos, 'Prazo de 2 dias para manifestação.', new \DateTimeImmutable('2026-09-15 10:00'))[0]['data']);
    }
}
