<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\DTO\ResumoDeEventosDeDesempenho;
use App\Master\Entity\RegistroDeBackup;
use App\Master\Enum\TipoDeRegistroDeBackup;
use App\Master\Service\ApuracaoDeDesempenho;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Limiares e tons dos 8 indicadores da seção Desempenho (spec master-desempenho §1; regra de cor do JS do desenho).
 */
#[CoversClass(ApuracaoDeDesempenho::class)]
#[CoversClass(ResumoDeEventosDeDesempenho::class)]
final class ApuracaoDeDesempenhoTest extends TestCase
{
    private const AGORA = '2026-10-26 12:00:00';

    private ApuracaoDeDesempenho $apuracao;

    protected function setUp(): void
    {
        $this->apuracao = new ApuracaoDeDesempenho();
    }

    /** @return array<string, array<string, mixed>> */
    private function apurar(ResumoDeEventosDeDesempenho $resumo, ?RegistroDeBackup $backup = null, ?RegistroDeBackup $teste = null): array
    {
        return $this->apuracao->indicadores($resumo, $backup, $teste, new \DateTimeImmutable(self::AGORA));
    }

    #[TestDox('sem nenhuma medição, os indicadores medidos ficam "sem dados" e erros/logins não alarmam')]
    public function testSemDados(): void
    {
        $i = $this->apurar(new ResumoDeEventosDeDesempenho());

        foreach (['disponibilidade', 'p95', 'ultimoBackup', 'restauracao', 'servidor'] as $chave) {
            self::assertSame(ApuracaoDeDesempenho::TOM_SEM_DADOS, $i[$chave]['tom'], $chave);
        }
        self::assertNull($i['disponibilidade']['percentual']);
        self::assertSame(ApuracaoDeDesempenho::TOM_OK, $i['erros']['tom']);
        self::assertSame(ApuracaoDeDesempenho::TOM_NEUTRO, $i['loginsFalhos']['tom']);
    }

    #[TestDox('disponibilidade: abaixo de 99% é crítica; 99% ou mais é ok; arredonda a 1 casa')]
    #[DataProvider('disponibilidades')]
    public function testDisponibilidade(int $pings, int $ok, float $percentual, string $tom): void
    {
        $d = $this->apurar(new ResumoDeEventosDeDesempenho(pings: $pings, pingsOk: $ok))['disponibilidade'];

        self::assertSame($percentual, $d['percentual']);
        self::assertSame($tom, $d['tom']);
        self::assertSame($pings, $d['verificacoes']);
    }

    /** @return iterable<string, array{int, int, float, string}> */
    public static function disponibilidades(): iterable
    {
        yield 'todas ok' => [10, 10, 100.0, 'ok'];
        yield 'exatamente 99' => [100, 99, 99.0, 'ok'];
        yield 'abaixo de 99' => [200, 197, 98.5, 'critico'];
    }

    #[TestDox('P95 de carregamento: até 1500 ms ok, acima de 1500 atenção, acima de 3000 crítico')]
    #[DataProvider('p95s')]
    public function testP95(int $ms, string $tom): void
    {
        $p = $this->apurar(new ResumoDeEventosDeDesempenho(cargasMedidas: 5, p95Ms: $ms))['p95'];

        self::assertSame($tom, $p['tom']);
        self::assertSame($ms, $p['ms']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function p95s(): iterable
    {
        yield 'limite ok' => [1500, 'ok'];
        yield 'atenção' => [1501, 'atencao'];
        yield 'limite atenção' => [3000, 'atencao'];
        yield 'crítico' => [3001, 'critico'];
    }

    #[TestDox('erros > 0 é crítico; falhas de login só alarmam acima de 10; falhas de upload nunca mudam de cor')]
    public function testErrosELogins(): void
    {
        $resumo = new ResumoDeEventosDeDesempenho(erros: 1, logins: 30, loginsFalhos: 10, uploads: 4, uploadsFalhos: 4);
        $i = $this->apurar($resumo);

        self::assertSame('critico', $i['erros']['tom']);
        self::assertSame('neutro', $i['loginsFalhos']['tom'], '10 não passa de 10');
        self::assertSame('neutro', $i['uploadsFalhos']['tom']);
        self::assertSame('critico', $this->apurar(new ResumoDeEventosDeDesempenho(logins: 30, loginsFalhos: 11))['loginsFalhos']['tom']);
    }

    #[TestDox('último backup: até 26 h é ok, acima de 26 h é crítico, e o relógio é o do registro')]
    public function testUltimoBackup(): void
    {
        $recente = $this->registro(TipoDeRegistroDeBackup::Backup, true, '-26 hours');
        $velho = $this->registro(TipoDeRegistroDeBackup::Backup, true, '-27 hours');

        $ok = $this->apurar(new ResumoDeEventosDeDesempenho(), $recente)['ultimoBackup'];
        $atrasado = $this->apurar(new ResumoDeEventosDeDesempenho(), $velho)['ultimoBackup'];

        self::assertSame(26, $ok['horas']);
        self::assertSame('ok', $ok['tom']);
        self::assertSame('critico', $atrasado['tom']);
    }

    #[TestDox('teste de restauração: aprovado é ok, falhou é crítico')]
    public function testRestauracao(): void
    {
        $aprovado = $this->apurar(new ResumoDeEventosDeDesempenho(), null, $this->registro(TipoDeRegistroDeBackup::Restauracao, true, '-1 hour'))['restauracao'];
        $falhou = $this->apurar(new ResumoDeEventosDeDesempenho(), null, $this->registro(TipoDeRegistroDeBackup::Restauracao, false, '-1 hour'))['restauracao'];

        self::assertTrue($aprovado['ok']);
        self::assertSame('ok', $aprovado['tom']);
        self::assertFalse($falhou['ok']);
        self::assertSame('critico', $falhou['tom']);
    }

    private function registro(TipoDeRegistroDeBackup $tipo, bool $ok, string $quando): RegistroDeBackup
    {
        return new RegistroDeBackup($tipo, $ok, null, (new \DateTimeImmutable(self::AGORA))->modify($quando));
    }
}
