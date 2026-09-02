<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Command\AcervoNomesParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Leitor dos nomes de pasta no formato `NUP - CLIENTE - AÇÃO`, usado em TRÊS lugares: a
 * reconciliação do Drive (que cria pasta no sistema a partir do nome da pasta de lá) e os dois
 * comandos de mapeamento do acervo.
 *
 * Não tinha teste nenhum. Estes primeiros CARACTERIZAM o comportamento — descrevem o que o leitor
 * faz hoje, não o que deveria fazer —, porque a regra do projeto é cobrir antes de refatorar código
 * sem cobertura, e porque um leitor com três consumidores não se muda no escuro.
 */
#[CoversClass(AcervoNomesParser::class)]
final class AcervoNomesParserTest extends TestCase
{
    private AcervoNomesParser $sut;

    protected function setUp(): void
    {
        $this->sut = new AcervoNomesParser();
    }

    /** @return array{nup: string, cliente: string, parte_contraria: string, acao: string} */
    private function campos(string $linha): array
    {
        $r = $this->sut->parsear($linha);
        $item = $r['alta'][0] ?? $r['revisao'][0] ?? null;

        self::assertNotNull($item, sprintf('a linha "%s" não foi lida', $linha));

        return $item;
    }

    #[Test]
    #[TestDox('Nome simples: separa número, cliente e ação')]
    public function nomeSimplesSeparaAsTresPartes(): void
    {
        $campos = $this->campos('1180 - GLEISSON BRUNO GABRIEL - OBRIGACAO DE FAZER');

        self::assertSame('1180', $campos['nup']);
        self::assertSame('GLEISSON BRUNO GABRIEL', $campos['cliente']);
        self::assertSame('OBRIGACAO DE FAZER', $campos['acao']);
    }

    #[Test]
    #[TestDox('Litígio com "x": separa cliente, parte contrária e ação')]
    public function litigioSeparaAParteContraria(): void
    {
        $campos = $this->campos('1000 - FULANO DE TAL x BELTRANO SOUZA - ACAO DE COBRANCA');

        self::assertSame('1000', $campos['nup']);
        self::assertSame('FULANO DE TAL', $campos['cliente']);
        self::assertSame('BELTRANO SOUZA', $campos['parte_contraria']);
        self::assertSame('ACAO DE COBRANCA', $campos['acao']);
    }

    #[Test]
    #[TestDox('Sem ação: o cliente fica inteiro e a ação vazia')]
    public function semAcaoOClienteFicaInteiro(): void
    {
        $campos = $this->campos('1100 - CLIENTE SOZINHO');

        self::assertSame('1100', $campos['nup']);
        self::assertSame('CLIENTE SOZINHO', $campos['cliente']);
        self::assertSame('', $campos['acao']);
    }

    #[Test]
    #[TestDox('Número grudado no nome é normalizado antes de separar')]
    public function numeroGrudadoEhNormalizado(): void
    {
        $campos = $this->campos('1128-CLIENTE COLADO - ACAO QUALQUER');

        self::assertSame('1128', $campos['nup']);
        self::assertSame('CLIENTE COLADO', $campos['cliente']);
        self::assertSame('ACAO QUALQUER', $campos['acao']);
    }

    #[Test]
    #[TestDox('Cliente COM hífen: o nome fica inteiro e só o último pedaço é a ação')]
    public function clienteComHifenFicaInteiro(): void
    {
        // É o formato das pastas judicializadas pela cobrança desde 01/09 — o identificador tem um
        // hífen dentro (`CREDOR - DEVEDOR`). Com a regra do PRIMEIRO separador, o nome era cortado e
        // o devedor ia parar na ação.
        $campos = $this->campos('1263 - APLC TOP LIFE 1 - SALVADOR PAULO DE OLIVEIRA - ACAO MONITORIA');

        self::assertSame('1263', $campos['nup']);
        self::assertSame('APLC TOP LIFE 1 - SALVADOR PAULO DE OLIVEIRA', $campos['cliente']);
        self::assertSame('ACAO MONITORIA', $campos['acao']);
    }
}
