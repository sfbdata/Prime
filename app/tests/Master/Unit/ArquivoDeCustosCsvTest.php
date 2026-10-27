<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Master\Exception\CustoRecusado;
use App\Master\Service\ArquivoDeCustosCsv;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ArquivoDeCustosCsvTest extends TestCase
{
    private const CATEGORIAS = ['armazenamento' => 'Armazenamento', 'banco_de_dados' => 'Banco de dados', 'outros' => 'Outros'];

    #[TestDox('exporta com BOM, ponto e vírgula, valor com vírgula e rótulos')]
    public function testGerar(): void
    {
        $l = new LancamentoDeCusto('2026-10', CategoriaDeCusto::BancoDeDados, 123456, OrigemDoCusto::Medido, 'AWS "RDS"', 'ok', new \DateTimeImmutable());

        $csv = ArquivoDeCustosCsv::gerar([$l], self::CATEGORIAS, ['medido' => 'Medido']);

        self::assertStringStartsWith("\u{FEFF}\"Mês\";\"Categoria\"", $csv);
        self::assertStringContainsString("\"2026-10\";\"Banco de dados\";\"AWS \"\"RDS\"\"\";\"1234,56\";\"Medido\";\"ok\"\r\n", $csv);
    }

    #[TestDox('texto que começa com fórmula sai como texto (apóstrofo)')]
    public function testFormula(): void
    {
        $l = new LancamentoDeCusto('2026-10', CategoriaDeCusto::Outros, 100, OrigemDoCusto::Estimado, '=CMD()', null, new \DateTimeImmutable());

        self::assertStringContainsString('"\'=CMD()"', ArquivoDeCustosCsv::gerar([$l], self::CATEGORIAS, []));
    }

    #[TestDox('lê o que exporta: ida e volta')]
    public function testIdaEVolta(): void
    {
        $l = new LancamentoDeCusto('2026-09', CategoriaDeCusto::Armazenamento, 99900, OrigemDoCusto::Medido, 'B2', 'obs', new \DateTimeImmutable());

        $lido = ArquivoDeCustosCsv::ler(ArquivoDeCustosCsv::gerar([$l], self::CATEGORIAS, ['medido' => 'Medido']), '2026-10', self::CATEGORIAS);

        self::assertSame(0, $lido['ignoradas']);
        self::assertSame('2026-09', $lido['linhas'][0]['mes']);
        self::assertSame(CategoriaDeCusto::Armazenamento, $lido['linhas'][0]['categoria']);
        self::assertSame(99900, $lido['linhas'][0]['valorCentavos']);
        self::assertSame(OrigemDoCusto::Importado, $lido['linhas'][0]['origem']);
        self::assertSame('B2', $lido['linhas'][0]['fornecedor']);
    }

    #[TestDox('mês inválido usa o escolhido, categoria desconhecida vira Outros, valor zero e linha curta são ignorados')]
    public function testRegras(): void
    {
        $csv = "Mês;Categoria;Fornecedor;Valor\n"
            . "xx;Coisa nova;Fulano;10,50\n"
            . "2026-08;banco de dados;;1.000,00\n"
            . "2026-08;Outros;Zero;0\n"
            . "2026-08;Outros\n";

        $lido = ArquivoDeCustosCsv::ler($csv, '2026-10', self::CATEGORIAS);

        self::assertCount(2, $lido['linhas']);
        self::assertSame(2, $lido['ignoradas']);
        self::assertSame('2026-10', $lido['linhas'][0]['mes']);
        self::assertSame(CategoriaDeCusto::Outros, $lido['linhas'][0]['categoria']);
        self::assertSame(1050, $lido['linhas'][0]['valorCentavos']);
        self::assertSame(CategoriaDeCusto::BancoDeDados, $lido['linhas'][1]['categoria']);
        self::assertNull($lido['linhas'][1]['fornecedor']);
        self::assertSame(100000, $lido['linhas'][1]['valorCentavos']);
    }

    #[TestDox('aceita tabulação e Windows-1252')]
    public function testTabulacaoEWindows1252(): void
    {
        $csv = mb_convert_encoding("Mês\tCategoria\tFornecedor\tValor\n2026-08\tOutros\tCafé\t5,00\n", 'Windows-1252', 'UTF-8');

        $lido = ArquivoDeCustosCsv::ler($csv, '2026-10', self::CATEGORIAS);

        self::assertSame('Café', $lido['linhas'][0]['fornecedor']);
        self::assertSame(500, $lido['linhas'][0]['valorCentavos']);
    }

    #[TestDox('recusa arquivo grande demais ou com linhas demais')]
    public function testLimites(): void
    {
        try {
            ArquivoDeCustosCsv::ler(str_repeat('a', ArquivoDeCustosCsv::TAMANHO_MAXIMO + 1), '2026-10', []);
            self::fail('devia recusar o tamanho');
        } catch (CustoRecusado) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(CustoRecusado::class);
        ArquivoDeCustosCsv::ler("Mês;Categoria;Fornecedor;Valor\n" . str_repeat("2026-08;Outros;X;1\n", ArquivoDeCustosCsv::LINHAS_MAXIMAS + 1), '2026-10', []);
    }
}
