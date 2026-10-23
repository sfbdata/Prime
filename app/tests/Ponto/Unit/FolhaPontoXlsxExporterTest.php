<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\FolhaPontoXlsxExporter;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Achado de auditoria interna F12 e F18 (spec `docs/specs/seguranca-b-ponto.md` §1.2): o texto livre da folha de ponto
 * sai na planilha com o tipo string, igual ao que foi digitado, e nunca como fórmula.
 *
 * A planilha é gerada, gravada e lida de volta do arquivo: o que se prova é o que o programa de planilha recebe.
 */
#[CoversClass(FolhaPontoXlsxExporter::class)]
final class FolhaPontoXlsxExporterTest extends TestCase
{
    /** Os campos de texto livre do escritório, do empregado e do responsável. */
    private const CAMPOS = ['nomeEmpresa', 'enderecoLinha1', 'enderecoLinha2', 'nomeUsuario', 'responsavelAssinatura'];

    /**
     * @param array<string, mixed> $contexto
     */
    private function gerar(array $contexto): Worksheet
    {
        $resposta = (new FolhaPontoXlsxExporter())->exportar([], 'folha.xlsx', $contexto + ['mes' => 10, 'ano' => 2026]);

        ob_start();
        $resposta->sendContent();
        $binario = (string) ob_get_clean();

        $arquivo = tempnam(sys_get_temp_dir(), 'folha_ponto_');
        self::assertIsString($arquivo);
        file_put_contents($arquivo, $binario);

        try {
            return IOFactory::load($arquivo)->getActiveSheet();
        } finally {
            unlink($arquivo);
        }
    }

    /** A célula cujo valor é exatamente o texto, ou falha se não houver. */
    private function celulaCom(Worksheet $aba, string $texto): Cell
    {
        foreach ($aba->getRowIterator() as $linha) {
            foreach ($linha->getCellIterator() as $celula) {
                if ($celula->getValue() === $texto) {
                    return $celula;
                }
            }
        }
        self::fail('nenhuma célula com o texto: ' . $texto);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixos(): iterable
    {
        yield 'igual' => ['='];
        yield 'mais' => ['+'];
        yield 'menos' => ['-'];
        yield 'arroba' => ['@'];
    }

    #[DataProvider('prefixos')]
    #[TestDox('Texto livre que começa com $prefixo sai como string e igual ao digitado')]
    public function testTextoLivreSaiComoString(string $prefixo): void
    {
        $contexto = [];
        foreach (self::CAMPOS as $campo) {
            $contexto[$campo] = $prefixo . 'Texto do campo ' . $campo;
        }

        $aba = $this->gerar($contexto);

        foreach ($contexto as $campo => $texto) {
            $celula = $this->celulaCom($aba, $texto);
            self::assertSame(DataType::TYPE_STRING, $celula->getDataType(), 'o campo ' . $campo . ' não saiu como string');
            self::assertSame($texto, $celula->getValue(), 'o campo ' . $campo . ' mudou');
        }
        $nomeNaLinha = $this->celulaCom($aba, 'Nome: ' . $contexto['nomeUsuario']);
        self::assertSame(DataType::TYPE_STRING, $nomeNaLinha->getDataType());
    }

    #[TestDox('Nome comum sai igual a antes, no cabeçalho e nas assinaturas')]
    public function testNomeComumSaiIgual(): void
    {
        $aba = $this->gerar([
            'nomeEmpresa'           => 'Escritório Silva & Souza',
            'enderecoLinha1'        => 'Rua das Flores, 123',
            'enderecoLinha2'        => 'Brasília/DF',
            'nomeUsuario'           => 'Maria da Silva',
            'responsavelAssinatura' => 'João Souza',
        ]);

        foreach (['Escritório Silva & Souza', 'Rua das Flores, 123', 'Brasília/DF', 'Maria da Silva', 'João Souza', 'Nome: Maria da Silva'] as $texto) {
            self::assertSame(DataType::TYPE_STRING, $this->celulaCom($aba, $texto)->getDataType());
        }
        self::assertSame('Maria da Silva', $aba->getCell('A' . $this->celulaCom($aba, 'João Souza')->getRow())->getValue());
    }
}
