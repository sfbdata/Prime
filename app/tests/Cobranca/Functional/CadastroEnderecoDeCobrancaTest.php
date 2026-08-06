<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Service\Importacao\CadastroCondominosAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A célula "Endereço" da fonte pode trazer DOIS endereços: o do condômino e, depois de uma quebra de
 * linha, um rótulo "Endereço de cobrança:" com outro (no dado real, idêntico ao primeiro).
 *
 * 🔴 Isto derrubava a importação INTEIRA. Medido no cadastro real do TOP LIFE 1: **1 linha em 242**
 * (unidade `02-07 (05-03,06-01,06-02,06-03 e 06-04)`) fazia o parse produzir um complemento de 151
 * caracteres, e o banco recusava com `SQLSTATE[22001] value too long for type character varying(120)`.
 * As outras 241 unidades não entravam por causa dela — a transação é única.
 *
 * ⚠️ E não era um caso remoto: o endereço mais longo da AMLI tem **119 caracteres, com o limite em
 * 120**. Passou por um caractere.
 */
#[CoversClass(CadastroCondominosAdapter::class)]
final class CadastroEnderecoDeCobrancaTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/cadastro_endereco_de_cobranca.xlsx';

    #[TestDox('Endereço com "Endereço de cobrança" embutido: usa só o primeiro e cabe no banco')]
    public function testEnderecoDeCobrancaEmbutidoNaoEstouraOsLimites(): void
    {
        $leitura = (new CadastroCondominosAdapter())->ler(self::FIXTURE);

        self::assertCount(2, $leitura->importaveis, 'as duas unidades da fixture têm de ser lidas');

        $comDuplo = $leitura->importaveis[0];
        $endereco = $comDuplo->endereco;

        self::assertNotSame([], $endereco, 'o endereço tem de ser reconhecido, não descartado');

        // Os limites reais das colunas do banco. É exatamente aqui que a importação abortava.
        foreach (['bairro' => 120, 'cidade' => 120, 'complemento' => 120] as $campo => $limite) {
            $valor = $endereco[$campo] ?? '';
            self::assertLessThanOrEqual(
                $limite,
                mb_strlen($valor),
                "o campo {$campo} não pode passar de {$limite} chars — é varchar({$limite}) no banco",
            );
        }

        // E o conteúdo tem de ser o PRIMEIRO endereço, não uma colagem dos dois.
        self::assertSame('Área Rural', $endereco['logradouro']);
        self::assertSame('1', $endereco['numero']);
        self::assertSame('07 7-01', $endereco['complemento'] ?? null, 'o complemento é só o pedaço do meio do 1º endereço');
        self::assertSame('Área Rural de Exemplo do Descoberto', $endereco['bairro']);
        self::assertSame('Santo Antonio Exemplo', $endereco['cidade']);
        self::assertSame('GO', $endereco['uf']);
        self::assertSame('72908899', $endereco['cep']);
    }

    #[TestDox('A unidade vizinha, normal, continua sendo lida — uma linha ruim não derruba o lote')]
    public function testUnidadeNormalNaMesmaPlanilhaContinuaIntacta(): void
    {
        $leitura = (new CadastroCondominosAdapter())->ler(self::FIXTURE);

        $normal = $leitura->importaveis[1];
        self::assertSame('02-08', $normal->unidade);
        self::assertSame('QUADRA 02 CHACARA 08', $normal->endereco['complemento'] ?? null);
        self::assertSame('LAGO EXEMPLO', $normal->endereco['bairro']);
    }
}
