<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\DestinatariosEmCsv;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;
use App\Comercial\UseCase\ExportarDestinatariosUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/** "Exportar destinatários" (F3e; spec comercial-marcas.md, regra 6): o CSV, o escape de fórmula e a auditoria. */
#[CoversClass(ExportarDestinatariosUseCase::class)]
#[CoversClass(DestinatariosEmCsv::class)]
final class ExportarDestinatariosUseCaseTest extends TestCase
{
    private const BOM = "\u{FEFF}";

    #[TestDox('Completo: BOM, "E-mail;Enviado;Leitura", Enviado sim só no que saiu, Leitura "não rastreada"; auditoria sem e-mails')]
    public function testCompleto(): void
    {
        $campanhas = $this->campanhas(existe: true);
        $campanhas->expects(self::once())->method('auditar')
            ->with(7, 'camp_csv', ['linhas' => 4, 'formato' => 'completo'], 42, 'master@exemplo.com', self::isInstanceOf(\DateTimeImmutable::class));
        $relatorio = $this->relatorio([
            ['email' => 'a@exemplo.com', 'situacao' => 'enviado'], ['email' => 'b@exemplo.com', 'situacao' => 'falhou'],
            ['email' => 'c@exemplo.com', 'situacao' => 'incerto'], ['email' => 'd@exemplo.com', 'situacao' => null],
        ]);

        $csv = $this->useCase($campanhas, $relatorio)->executar(7, 'completo', 42, 'master@exemplo.com');

        self::assertNotNull($csv);
        self::assertSame(['destinatarios-7.csv', 4], [$csv->nomeDoArquivo, $csv->linhas]);
        self::assertSame(
            self::BOM . "E-mail;Enviado;Leitura\na@exemplo.com;sim;não rastreada\nb@exemplo.com;sim;não rastreada\nc@exemplo.com;sim;não rastreada\nd@exemplo.com;não;não rastreada\n",
            $csv->conteudo,
        );
    }

    #[TestDox('Só e-mails (do menu): a coluna E-mail; formato desconhecido vira completo')]
    public function testSoEmails(): void
    {
        $campanhas = $this->campanhas(existe: true);
        $campanhas->expects(self::exactly(2))->method('auditar');
        $relatorio = $this->relatorio([['email' => 'a@exemplo.com', 'situacao' => 'enviado']]);

        $csv = $this->useCase($campanhas, $relatorio)->executar(7, 'emails', 42, null);
        self::assertSame(self::BOM . "E-mail\na@exemplo.com\n", $csv?->conteudo);
        $csv = $this->useCase($campanhas, $relatorio)->executar(7, 'xlsx', 42, null);
        self::assertStringStartsWith(self::BOM . "E-mail;Enviado;Leitura\n", (string) $csv?->conteudo);
    }

    #[TestDox('Injeção de fórmula: =, +, -, @, tab e CR ganham apóstrofo; ; e aspas vão entre aspas')]
    public function testEscapeDeFormula(): void
    {
        self::assertSame("'=SOMA(A1)", ExportarDestinatariosUseCase::celula('=SOMA(A1)'));
        self::assertSame('"\'=HYPERLINK(""x"")"', ExportarDestinatariosUseCase::celula('=HYPERLINK("x")'));
        foreach (['+1', '-1', '@soma', "\tx", "\rx"] as $perigoso) {
            self::assertStringStartsWith("'", ltrim(ExportarDestinatariosUseCase::celula($perigoso), '"'), $perigoso);
        }
        self::assertSame('"a;b@exemplo.com"', ExportarDestinatariosUseCase::celula('a;b@exemplo.com'));
        self::assertSame('ana@exemplo.com', ExportarDestinatariosUseCase::celula('ana@exemplo.com'));
        self::assertSame('', ExportarDestinatariosUseCase::celula(''));
    }

    #[TestDox('O e-mail que começa com fórmula sai escapado no arquivo')]
    public function testEscapeNoArquivo(): void
    {
        $relatorio = $this->relatorio([['email' => '=1+1@exemplo.com', 'situacao' => 'enviado']]);

        $csv = $this->useCase($this->campanhas(existe: true), $relatorio)->executar(7, 'emails', 42, null);

        self::assertSame(self::BOM . "E-mail\n'=1+1@exemplo.com\n", $csv?->conteudo);
    }

    #[TestDox('Campanha que não existe: null, sem ler os destinatários e sem auditoria')]
    public function testInexistente(): void
    {
        $campanhas = $this->campanhas(existe: false);
        $campanhas->expects(self::never())->method('auditar');
        $relatorio = $this->createMock(RelatorioDeCampanhasRepository::class);
        $relatorio->expects(self::never())->method('destinatariosParaExportar');

        self::assertNull($this->useCase($campanhas, $relatorio)->executar(7, 'completo', 42, null));
    }

    private function campanhas(bool $existe): CampanhaDeEmailRepository&MockObject
    {
        $campanhas = $this->createMock(CampanhaDeEmailRepository::class);
        $campanhas->method('porId')->willReturn($existe ? ['id' => 7] : null);

        return $campanhas;
    }

    /** @param list<array{email: string, situacao: ?string}> $destinatarios */
    private function relatorio(array $destinatarios): RelatorioDeCampanhasRepository
    {
        $relatorio = $this->createStub(RelatorioDeCampanhasRepository::class);
        $relatorio->method('destinatariosParaExportar')->willReturn($destinatarios);

        return $relatorio;
    }

    private function useCase(CampanhaDeEmailRepository $campanhas, RelatorioDeCampanhasRepository $relatorio): ExportarDestinatariosUseCase
    {
        $relogio = $this->createStub(ClockInterface::class);
        $relogio->method('now')->willReturn(new \DateTimeImmutable('2026-10-21 10:00:00'));

        return new ExportarDestinatariosUseCase($campanhas, $relatorio, $relogio);
    }
}
