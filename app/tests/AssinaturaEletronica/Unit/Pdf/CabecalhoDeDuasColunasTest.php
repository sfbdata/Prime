<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\CabecalhoDeDuasColunas;
use App\AssinaturaEletronica\Pdf\Diagramacao\ColunasDaFolha;
use App\AssinaturaEletronica\Pdf\Fonte;
use App\AssinaturaEletronica\Pdf\MedidorDeTexto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O alto das duas colunas (dc:415): o texto lateral desce de 40 até caber em 1180, no mínimo 18, de 1 em 1; o título
 * desce de 17,5 até caber entre a logo e a margem, no mínimo 12, de 0,5 em 0,5.
 */
#[CoversClass(CabecalhoDeDuasColunas::class)]
final class CabecalhoDeDuasColunasTest extends TestCase
{
    /** Sem logo, o título vai de XL + 16 até XR. */
    private const ESPACO_DO_TITULO = ColunasDaFolha::XR - ColunasDaFolha::XL - 16;

    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
    }

    #[TestDox('Lateral: curta fica em 40; comprida desce de 1 em 1 até caber em 1180; longa demais para em 18')]
    public function testLateral(): void
    {
        $media = 'CONTRATO DE PRESTAÇÃO DE SERVIÇOS ADVOCATÍCIOS E OUTRAS AVENÇAS';
        $longa = str_repeat('CONTRATO DE PRESTAÇÃO DE SERVIÇOS ', 4);
        self::assertGreaterThan(1180, self::lateral($media, 40), 'Premissa: em 40 não cabe.');
        self::assertGreaterThan(1180, self::lateral($longa, 18), 'Premissa: nem em 18 cabe.');

        self::assertSame(40.0, CabecalhoDeDuasColunas::tamanhoDaLateral('Contrato'));

        $tamanho = CabecalhoDeDuasColunas::tamanhoDaLateral($media);
        self::assertSame(floor($tamanho), $tamanho, 'Passos de 1.');
        self::assertGreaterThan(18, $tamanho);
        self::assertLessThanOrEqual(1180, self::lateral($media, $tamanho));
        self::assertGreaterThan(1180, self::lateral($media, $tamanho + 1), 'É o maior tamanho que cabe.');

        self::assertSame(18.0, CabecalhoDeDuasColunas::tamanhoDaLateral($longa));
    }

    #[TestDox('Título: curto fica em 17,5; comprido desce de 0,5 em 0,5 até caber; longo demais para em 12')]
    public function testTitulo(): void
    {
        $medio = 'CONTRATO DE PRESTAÇÃO DE SERVIÇOS ADVOCATÍCIOS E HONORÁRIOS ENTRE AS PARTES';
        $longo = str_repeat('CONTRATO DE PRESTAÇÃO DE SERVIÇOS ', 5);
        self::assertGreaterThan(self::ESPACO_DO_TITULO, self::titulo($medio, 17.5), 'Premissa: em 17,5 não cabe.');
        self::assertGreaterThan(self::ESPACO_DO_TITULO, self::titulo($longo, 12), 'Premissa: nem em 12 cabe.');

        self::assertSame(17.5, CabecalhoDeDuasColunas::tamanhoDoTitulo('CONTRATO', self::ESPACO_DO_TITULO));

        $tamanho = CabecalhoDeDuasColunas::tamanhoDoTitulo($medio, self::ESPACO_DO_TITULO);
        self::assertSame(0.0, fmod($tamanho * 2, 1.0), 'Passos de 0,5.');
        self::assertGreaterThan(12, $tamanho);
        self::assertLessThanOrEqual(self::ESPACO_DO_TITULO, self::titulo($medio, $tamanho));
        self::assertGreaterThan(self::ESPACO_DO_TITULO, self::titulo($medio, $tamanho + .5), 'É o maior tamanho que cabe.');

        self::assertSame(12.0, CabecalhoDeDuasColunas::tamanhoDoTitulo($longo, self::ESPACO_DO_TITULO));
    }

    private static function lateral(string $texto, float $tamanho): float
    {
        return MedidorDeTexto::largura($texto, Fonte::Sans, $tamanho);
    }

    private static function titulo(string $texto, float $tamanho): float
    {
        return MedidorDeTexto::largura($texto, Fonte::SansNegrito, $tamanho);
    }
}
