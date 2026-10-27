<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\DTO\ExtratoEmCsv;
use App\Recompensa\DTO\LancamentoDoExtratoOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtratoEmCsv::class)]
final class ExtratoEmCsvTest extends TestCase
{
    private const CABECALHO = ['Data', 'Usuário', 'E-mail', 'Tipo', 'Valor', 'Observação'];
    private const TIPOS = ['credito' => 'Crédito manual', 'estorno' => 'Estorno'];

    private function linha(int $valor, ?string $nome = 'Ana', ?string $email = 'ana@exemplo.com', ?string $obs = null): LancamentoDoExtratoOutput
    {
        return new LancamentoDoExtratoOutput(1, new \DateTimeImmutable('2026-10-26 09:05:00'), 7, $nome, $email, 'bonus', $valor, $obs);
    }

    /** @return list<string> */
    private function linhas(string $csv): array
    {
        return explode("\n", rtrim($csv, "\n"));
    }

    #[TestDox('BOM UTF-8, separador ";", tudo entre aspas, valor em reais com sinal e o tipo pelo sinal')]
    public function testFormato(): void
    {
        $csv = ExtratoEmCsv::gerar([$this->linha(1050), $this->linha(-5)], self::CABECALHO, self::TIPOS);

        self::assertStringStartsWith("\u{FEFF}\"Data\";\"Usuário\"", $csv);
        $l = $this->linhas($csv);
        self::assertSame('"26/10/2026 09:05";"Ana";"ana@exemplo.com";"Crédito manual";"10,50";""', $l[1]);
        self::assertSame('"26/10/2026 09:05";"Ana";"ana@exemplo.com";"Estorno";"-0,05";""', $l[2]);
    }

    #[TestDox('aspas duplas são dobradas e o ";" dentro do texto não quebra a coluna')]
    public function testAspas(): void
    {
        $l = $this->linhas(ExtratoEmCsv::gerar([$this->linha(100, 'Ana "Anita"; Souza', obs: 'a;b')], self::CABECALHO, self::TIPOS));

        self::assertSame('"26/10/2026 09:05";"Ana ""Anita""; Souza";"ana@exemplo.com";"Crédito manual";"1,00";"a;b"', $l[1]);
    }

    #[TestDox('injeção de fórmula: texto que começa com = + - @ (ou tab, CR) ganha apóstrofo; o valor numérico não')]
    public function testInjecaoDeFormula(): void
    {
        foreach (['=1+1', '+cmd', '-2+3', '@SOMA(A1)', "\tx", "\rx"] as $perigoso) {
            $csv = ExtratoEmCsv::gerar([$this->linha(-100, $perigoso, $perigoso, $perigoso)], self::CABECALHO, self::TIPOS);
            $l = $this->linhas($csv);
            self::assertSame(3, substr_count($csv, "\"'" . $perigoso . '"'), 'nome, e-mail e observação: ' . json_encode($perigoso));
            self::assertStringContainsString('"-1,00"', $l[1], 'o estorno segue número');
        }
    }

    #[TestDox('indicador apagado (LGPD) sai com nome e e-mail vazios; sem lançamentos há só o cabeçalho')]
    public function testSemIndicadorEVazio(): void
    {
        $l = $this->linhas(ExtratoEmCsv::gerar([$this->linha(100, null, null)], self::CABECALHO, self::TIPOS));
        self::assertSame('"26/10/2026 09:05";"";"";"Crédito manual";"1,00";""', $l[1]);
        self::assertCount(1, $this->linhas(ExtratoEmCsv::gerar([], self::CABECALHO, self::TIPOS)));
    }
}
