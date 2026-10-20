<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaParaAnaliseSemProcesso;
use App\Pasta\DTO\RelatorioSemProcessoOutput;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\Service\AnalisadorDePastasSemProcesso;
use App\Shared\Service\GeradorDePdfComDompdf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A folha do relatório (desenho 1.2.3, `spHTML` L4538-4555): título, cartões, ordem das seções,
 * resumo para despacho e a diferença da Evolução (L4546, `dl`).
 */
#[CoversClass(RelatorioSemProcessoOutput::class)]
#[CoversClass(GeradorDePdfComDompdf::class)]
final class RelatorioSemProcessoOutputTest extends TestCase
{
    private static function pasta(int $id, bool $marcada = false, ?string $processo = null, string $texto = ''): PastaParaAnaliseSemProcesso
    {
        return new PastaParaAnaliseSemProcesso(
            $id,
            (string) $id,
            'CLIENTE ' . $id,
            'Ana',
            null,
            $processo,
            null,
            $marcada,
            $texto !== '' ? [['rotulo' => 'Meta', 'texto' => $texto]] : [],
            [],
            0,
        );
    }

    /** @param list<array{mes: string, resumo: ResumoSemProcesso}> $historico */
    private static function relatorio(array $historico = []): RelatorioSemProcessoOutput
    {
        $hoje     = new \DateTimeImmutable('2026-10-05 10:05');
        $analises = (new AnalisadorDePastasSemProcesso())->analisar([
            self::pasta(1180),
            self::pasta(1181, texto: 'contestação e audiência'),
            self::pasta(1182, marcada: true),
            self::pasta(1183, marcada: true, texto: 'audiência, contestação e recurso'),
            self::pasta(1184, processo: '0701134-57.2025.8.07.0007'),
            self::pasta(1185, marcada: true, processo: '0701134-57.2025.8.07.0008'),
        ], [], $hoje);

        return new RelatorioSemProcessoOutput($hoje, $analises, ResumoSemProcesso::deAnalises($analises), $historico, 0);
    }

    #[TestDox('Título do desenho com a data do dia')]
    public function testTitulo(): void
    {
        self::assertSame('Relatório geral · Processos das pastas · 05/10/2026', self::relatorio()->titulo());
    }

    #[TestDox('Cartões (L4542): pastas, sem processo, deveriam, administrativas (admin + rever) e rever cadastro (rever + inconsistente)')]
    public function testCartoes(): void
    {
        $valores = array_column(self::relatorio()->cartoes(), 'valor', 'rotulo');

        self::assertSame(['Pastas' => 6, 'Sem processo' => 4, 'Deveriam ter processo' => 1, 'Administrativas' => 2, 'Rever cadastro' => 2], $valores);
    }

    #[TestDox('Seção de revisão: rever primeiro, depois inconsistente; despacho só com resumo')]
    public function testOrdemDasSecoesEDespacho(): void
    {
        $r = self::relatorio();

        self::assertSame(['1183', '1185'], array_map(static fn ($a): string => $a->numero, $r->daCategoria('rever', 'inconsistente')));
        self::assertSame(['1180', '1181', '1183', '1185'], array_map(static fn ($a): string => $a->numero, $r->paraDespacho()));
    }

    #[TestDox('Evolução: hoje menos o último envio; sem histórico, zero')]
    public function testDelta(): void
    {
        $semHistorico = self::relatorio();
        $comHistorico = self::relatorio([
            ['mes' => '08/2026', 'resumo' => new ResumoSemProcesso(9, 9, 9, 9, 9)],
            ['mes' => '09/2026', 'resumo' => new ResumoSemProcesso(6, 3, 1, 1, 0)],
        ]);

        self::assertSame(0, $semHistorico->delta('sem'));
        self::assertNull($semHistorico->ultimoEnvio());
        self::assertSame(-2, $comHistorico->delta('sem'));
        self::assertSame(-2, $comHistorico->delta('deveria'));
        self::assertSame(1, $comHistorico->delta('rever'));
        self::assertSame(0, $comHistorico->delta('classificar'));
    }

    #[TestDox('Nome do PDF: título com data não ganha outra (pdfBaixar); sem data, ganha a de hoje')]
    public function testNomeDoArquivo(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-05');

        self::assertSame('BlueJus Relatório geral Processos das pastas 05.10.2026.pdf', GeradorDePdfComDompdf::nomeDoArquivo(self::relatorio()->titulo(), $hoje));
        self::assertSame('BlueJus Extrato de pagamentos 05.10.2026.pdf', GeradorDePdfComDompdf::nomeDoArquivo('Extrato de pagamentos', $hoje));
    }
}
