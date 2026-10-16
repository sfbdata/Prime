<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Fontes\ArquivoNaoReconhecido;
use App\Master\Fontes\LeitorDeCsvDeFontes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorDeCsvDeFontes::class)]
final class LeitorDeCsvDeFontesTest extends TestCase
{
    #[TestDox('Tabela do modelo: estados (população e advogados) e municípios (população)')]
    public function testTabela(): void
    {
        $csv = "\u{FEFF}tipo;codigo_ibge;uf;nome;populacao;advogados\r\n"
            . "estado;53;DF;Distrito Federal;2.982.818;54568\r\n"
            . "estado;;AL;Alagoas;;16.456\r\n"
            . "municipio;2704302;AL;Maceió;957916;\r\n"
            . "estado;;;Sem código;1;1\r\n"
            . "estado;99;;UF inexistente;1;1\r\n"
            . "municipio;9912345;;Município de UF inexistente;10;\r\n"
            . "municipio;27043;;Código curto;10;\r\n";

        $t = (new LeitorDeCsvDeFontes())->tabela($csv);

        self::assertSame(['pop' => 2982818, 'adv' => 54568], $t['uf']['53']);
        self::assertSame(['adv' => 16456], $t['uf']['27']);
        self::assertSame(['2704302' => 957916], $t['mun']['27']);
        self::assertSame([53, 27], array_keys($t['uf']), 'códigos inválidos ficam de fora');
        self::assertSame([27], array_keys($t['mun']));
        self::assertSame(2, $t['estados']);
        self::assertSame(1, $t['municipios']);
    }

    #[TestDox('Quadro da OAB do modelo: 14 colunas por seccional; vazio vira nulo (mantém o anterior)')]
    public function testQuadroOab(): void
    {
        $csv = implode(';', LeitorDeCsvDeFontes::CABECALHO_OAB) . "\n"
            . "SP;396.761;1770;17786;237;208797;187964;;;;;;;;\n"
            . "XX;1;2;3;4;5;6;7;8;9;10;11;12;13;14\n";

        $q = (new LeitorDeCsvDeFontes())->quadroOab($csv);

        self::assertSame(['SP'], array_keys($q));
        self::assertSame([396761, 1770, 17786, 237, 208797, 187964], array_slice($q['SP'], 0, 6));
        self::assertNull($q['SP'][6]);
    }

    #[TestDox('Arquivo fora do modelo é recusado com a mensagem do desenho')]
    public function testForaDoModelo(): void
    {
        $this->expectException(ArquivoNaoReconhecido::class);
        $this->expectExceptionMessage('Tabela não reconhecida. Use o modelo baixado pelo menu.');

        (new LeitorDeCsvDeFontes())->tabela("a;b;c\n1;2;3\n");
    }
}
