<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As duas metades de D8, provadas separadamente: **recusar o impossível** e **nunca corrigir o
 * legítimo**.
 *
 * ## Por que este teste ataca o VO e não uma rota
 *
 * `ServirFotoControllerTest` documenta que o roteador do Symfony normaliza `../..` **antes** do
 * controller. Um teste funcional de travessia passa verde com ou sem guarda — ele prova outra
 * barreira, não esta. A única forma de provar a guarda é chamar o construtor com o valor
 * malicioso na mão, que é o que se faz aqui.
 */
#[CoversClass(ChaveDeArquivo::class)]
#[CoversClass(NovoArquivo::class)]
final class ChaveDeArquivoTest extends TestCase
{
    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant(1),
            CategoriaDeArquivo::PASTA_DOCUMENTO,
            $nome,
        );
    }

    // ---------------------------------------------------------------- recusa

    /** @return iterable<string, array{string}> */
    public static function nomesImpossiveis(): iterable
    {
        yield 'vazio'                   => [''];
        yield 'ponto sozinho'           => ['.'];
        yield 'dois pontos sozinhos'    => ['..'];
        yield 'travessia relativa'      => ['../../etc/passwd'];
        yield 'travessia no meio'       => ['docs/../../../etc/passwd'];
        yield 'dois pontos embutidos'   => ['arquivo..pdf'];
        yield 'barra'                   => ['chat/arquivo.pdf'];
        yield 'barra invertida'         => ['..\\windows\\system32'];
        yield 'barra invertida simples' => ['pasta\\arquivo.pdf'];
        yield 'caminho absoluto'        => ['/etc/passwd'];
        yield 'byte nulo'               => ["arquivo.pdf\0.png"];
        yield 'nova linha'              => ["arquivo\n.pdf"];
        yield 'tabulação'               => ["arquivo\t.pdf"];
        yield 'retorno de carro'        => ["arquivo\r.pdf"];
        yield 'DEL'                     => ["arquivo\x7F.pdf"];
    }

    #[DataProvider('nomesImpossiveis')]
    #[TestDox('nome impossível é RECUSADO')]
    public function testNomeImpossivelEhRecusado(string $nome): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);
        $this->chave($nome);
    }

    // ------------------------------------------------ não-normalização (D8)

    /**
     * @return iterable<string, array{string}>
     *
     * Estes formatos não são hipóteses: são o acervo. Medido em `saas_ux`, `pasta_documento` tem
     * 165 chaves terminadas em `.` e 3 com espaço nas bordas; a E0 contou 184 fora do padrão em
     * produção.
     */
    public static function nomesLegitimosQueParecemSujos(): iterable
    {
        yield 'termina em ponto (165 em produção)'  => ['3fa85f6457174562b3fc2c963f66afa6.'];
        yield 'espaço à esquerda'                   => [' contrato.pdf'];
        yield 'espaço à direita'                    => ['contrato.pdf '];
        yield 'espaço nos dois lados (3 em produção)' => [' contrato assinado.pdf '];
        yield 'só espaço'                           => [' '];
        yield 'espaço no meio'                      => ['Petição Inicial.pdf'];
        yield 'acento em NFC'                       => ["peti\u{00E7}ao.pdf"];
        yield 'acento em NFD'                       => ["petic\u{0327}ao.pdf"];
        yield 'emoji'                               => ['contrato-✅.pdf'];
        yield 'sem extensão'                        => ['3fa85f6457174562b3fc2c963f66afa6'];
        yield 'maiúsculas'                          => ['CONTRATO.PDF'];
        yield 'ponto no começo (oculto)'            => ['.gitkeep'];
        yield 'muitos pontos'                       => ['a.b.c.d.pdf'];
    }

    #[DataProvider('nomesLegitimosQueParecemSujos')]
    #[TestDox('nome legítimo atravessa o construtor BYTE A BYTE, sem normalização')]
    public function testNomeLegitimoNaoEhNormalizado(string $nome): void
    {
        $chave = $this->chave($nome);

        self::assertSame($nome, $chave->nome, 'o construtor não pode alterar um único byte');
        self::assertSame(
            strlen($nome),
            strlen($chave->nome),
            'nem o comprimento em bytes pode mudar — trim() e normalização Unicode mudariam',
        );
    }

    #[TestDox('NFC e NFD do mesmo texto continuam sendo chaves DIFERENTES')]
    public function testUnicodeNaoEhNormalizado(): void
    {
        $nfc = $this->chave("pe\u{00E7}a.pdf");
        $nfd = $this->chave("pec\u{0327}a.pdf");

        self::assertNotSame($nfc->nome, $nfd->nome);
        self::assertFalse(
            $nfc->ehIgualA($nfd),
            'normalizar Unicode faria dois arquivos distintos colidirem, e um sobrescreveria o outro',
        );
    }

    #[TestDox('a chave com espaço nas bordas NÃO é igual à versão trimada')]
    public function testEspacoNasBordasEhSignificativo(): void
    {
        self::assertFalse($this->chave(' contrato.pdf ')->ehIgualA($this->chave('contrato.pdf')));
    }

    // ------------------------------------------------------------- igualdade

    #[TestDox('a igualdade considera escopo, categoria e nome')]
    public function testIgualdadeConsideraOsTresCampos(): void
    {
        $base = $this->chave('x.pdf');

        self::assertTrue($base->ehIgualA($this->chave('x.pdf')));

        self::assertFalse($base->ehIgualA(new ChaveDeArquivo(
            EscopoDeArquivo::deTenant(2), CategoriaDeArquivo::PASTA_DOCUMENTO, 'x.pdf',
        )), 'tenant diferente é arquivo diferente, mesmo que o disco local não veja (R1)');

        self::assertFalse($base->ehIgualA(new ChaveDeArquivo(
            EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::KANBAN_ANEXO, 'x.pdf',
        )));
    }

    // ------------------------------------------------------- NovoArquivo (D8)

    #[TestDox('NovoArquivo cunha nome opaco de 128 bits')]
    public function testNovoArquivoCunhaNomeOpaco(): void
    {
        $novo = new NovoArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'pdf');

        $chave = $novo->cunharChave();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $chave->nome);
        self::assertSame(
            32,
            strlen(explode('.', $chave->nome)[0]),
            '32 hex = 16 bytes = 128 bits, igual ao random_bytes(16) de hoje. Não reduzir.',
        );
    }

    #[TestDox('cem cunhagens não repetem nenhum nome')]
    public function testCunhagemNaoRepete(): void
    {
        $novo  = new NovoArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'pdf');
        $nomes = [];

        for ($i = 0; $i < 100; $i++) {
            $nomes[] = $novo->cunharChave()->nome;
        }

        self::assertCount(100, array_unique($nomes));
    }

    /**
     * A extensão vazia é o que produziu as 165 chaves terminadas em `.` do acervo, via
     * `salvarConteudo(..., extensao: '')`. Arquivo NOVO não nasce mais assim — e isso não
     * conflita com D8, que fala do nome de chave LEGADA, não da extensão de um arquivo por criar.
     */
    #[TestDox('extensão vazia vira bin, em vez de gerar mais uma chave terminada em ponto')]
    public function testExtensaoVaziaViraBin(): void
    {
        $novo = new NovoArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, '');

        self::assertSame('bin', $novo->extensao);
        self::assertStringEndsWith('.bin', $novo->cunharChave()->nome);
    }

    /** @return iterable<string, array{string, string}> */
    public static function extensoesNormalizadas(): iterable
    {
        yield 'com ponto na frente' => ['.pdf', 'pdf'];
        yield 'maiúscula'           => ['PDF', 'pdf'];
        yield 'com espaço'          => [' pdf ', 'pdf'];
        yield 'só ponto'            => ['.', 'bin'];
    }

    #[DataProvider('extensoesNormalizadas')]
    #[TestDox('a extensão de arquivo NOVO é saneada')]
    public function testExtensaoEhSaneada(string $entrada, string $esperado): void
    {
        $novo = new NovoArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $entrada);

        self::assertSame($esperado, $novo->extensao);
    }

    /**
     * @return iterable<string, array{string}>
     *
     * Estes NÃO são valores inventados: saem de `pathinfo($nomeOriginal, PATHINFO_EXTENSION)`
     * sobre os `nome_original` reais de `pasta_documento`. Uma versão anterior deste código
     * RECUSAVA todos eles, e a revisão mediu: 18 arquivos do acervo cairiam. O único chamador
     * que deriva extensão de dado do usuário é o sync do Drive
     * (`ReconciliadorDePasta.php:440`) — recusar ali é impedir o arquivo do cliente de entrar.
     */
    public static function extensoesQueNaoSaoExtensao(): iterable
    {
        yield 'frase inteira'        => ['açaí - 02 junho 2025'];
        yield 'observação do usuário' => ['pdf canvelado por atraso - refeito'];
        yield 'barra de largura total' => ['208／2024-1'];
        yield 'nome sem ponto'       => ['inicial ivete x silvio'];
        yield 'com barra'            => ['pd/f'];
        yield 'com travessia'        => ['./../x'];
        yield 'longa demais'         => [str_repeat('a', 17)];
        yield 'com byte nulo'        => ["pdf\0"];
        yield 'vazia'                => [''];
    }

    #[DataProvider('extensoesQueNaoSaoExtensao')]
    #[TestDox('extensão que não serve vira bin — o arquivo entra, em vez de ser recusado')]
    public function testExtensaoImprestavelViraBin(string $extensao): void
    {
        $novo = new NovoArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $extensao);

        self::assertSame('bin', $novo->extensao);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.bin$/', $novo->cunharChave()->nome);
    }

    #[TestDox('o storage nunca recusa um arquivo por causa da extensão')]
    public function testExtensaoNuncaLanca(): void
    {
        foreach (self::extensoesQueNaoSaoExtensao() as [$extensao]) {
            new NovoArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $extensao);
        }

        self::assertTrue(true, 'nenhuma das extensões reais do acervo pode lançar');
    }

    // ------------------------------------------------------------- escopo

    #[TestDox('escopo de tenant exige id positivo')]
    public function testEscopoExigeTenantPositivo(): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);
        EscopoDeArquivo::deTenant(0);
    }

    #[TestDox('escopo global não tem tenant e não finge ter')]
    public function testEscopoGlobal(): void
    {
        $global = EscopoDeArquivo::global();

        self::assertTrue($global->ehGlobal());
        self::assertNull($global->tenantIdOuNull());
        self::assertFalse($global->ehIgualA(EscopoDeArquivo::deTenant(1)));
    }
}
