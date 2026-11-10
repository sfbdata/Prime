<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Service\ProjecaoPublicaDaValidacao;
use App\AssinaturaEletronica\UseCase\ValidarCodigoUseCase;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * A validação pública do código BJA (etapa 3, bloco 2; E3-7, E3-8, desenho dc:457): o código aparado e em maiúsculas,
 * a igualdade exata pelo repositório e a projeção que sai para a página e para a aba Validar. O tradutor é o de
 * identidade: o rótulo aparece como a chave da tela, o que prova qual rótulo foi escolhido (o texto é conferido no
 * funcional).
 */
#[CoversClass(ValidarCodigoUseCase::class)]
#[CoversClass(ProjecaoPublicaDaValidacao::class)]
final class ValidarCodigoUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const CODIGO = 'BJA-AB23-CD45-EF67';

    /** @var list<string> os códigos que chegaram ao repositório, na ordem */
    private array $procurados = [];

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->documentos->method('porCodigo')->willReturnCallback(function (string $codigo): ?DocumentoParaAssinar {
            $this->procurados[] = $codigo;

            return $this->achar(static fn (DocumentoParaAssinar $d): bool => $d->getCodigo() === $codigo);
        });
    }

    /** @return iterable<string, array{string, string}> */
    public static function digitados(): iterable
    {
        yield 'espaços e minúsculas' => ["  bja-ab23-cd45-ef67 \n", 'BJA-AB23-CD45-EF67'];
        yield 'espaço não separável nas pontas' => ["\u{00A0}BJA-AB23-CD45-EF67\u{00A0}", 'BJA-AB23-CD45-EF67'];
        yield 'espaço no meio fica' => ['BJA-AB23 -CD45-EF67', 'BJA-AB23 -CD45-EF67'];
        yield 'texto que não é UTF-8' => ["\xff\xfe", ''];
    }

    #[DataProvider('digitados')]
    #[TestDox('normalizar: $_dataName')]
    public function testNormalizar(string $digitado, string $esperado): void
    {
        self::assertSame($esperado, ValidarCodigoUseCase::normalizar($digitado));
    }

    #[TestDox('achado: o código aparado e em maiúsculas chega ao repositório e a projeção sai com os cinco campos')]
    public function testAchado(): void
    {
        $this->enviadoComCodigo();

        $resultado = $this->validar()->executar(' bja-ab23-cd45-ef67 ');

        self::assertSame([self::CODIGO], $this->procurados);
        self::assertNotNull($resultado);
        self::assertSame(['codigo', 'nome', 'situacao', 'hash', 'signatarios'], array_keys($resultado));
        self::assertSame(self::CODIGO, $resultado['codigo']);
        self::assertSame('Contrato de honorários', $resultado['nome']);
        self::assertSame('assinatura_eletronica_tela.js.situacao.aguardando', $resultado['situacao']);
        self::assertSame(str_repeat('f', 64), $resultado['hash'], 'E3-7: o hash do envio, o impresso no PDF');
    }

    #[TestDox('signatários: o nome digitado e a data de quem assinou; o rótulo da situação de quem não assinou')]
    public function testSignatarios(): void
    {
        $documento = $this->enviadoComCodigo();
        [$ana] = $documento->getSignatarios();
        $documento->assinar($ana, 'Ana Maria Lima', self::CPF_ANA, 'chave/rubrica.png', 'simples', '203.0.113.7', 'Navegador', new \DateTimeImmutable('2026-11-09 09:30:15'));

        $resultado = $this->validar()->executar(self::CODIGO);

        self::assertSame([
            ['nome' => 'Ana Maria Lima', 'assinadoEm' => (new \DateTimeImmutable('2026-11-09 09:30:15'))->format(\DATE_ATOM), 'situacao' => 'assinatura_eletronica_tela.js.sig.assinado'],
            ['nome' => 'Bia Souza', 'assinadoEm' => null, 'situacao' => 'assinatura_eletronica_tela.js.sig.pendente'],
        ], $resultado['signatarios'] ?? null);
    }

    #[TestDox('nunca CPF, e-mail, id nem escritório na projeção (spec 3.6)')]
    public function testSemDadoPessoal(): void
    {
        $documento = $this->enviadoComCodigo();
        [$ana] = $documento->getSignatarios();
        $documento->assinar($ana, 'Ana Maria Lima', self::CPF_ANA, 'chave/rubrica.png', 'simples', '203.0.113.7', 'Navegador', new \DateTimeImmutable('2026-11-09 09:30:00'));

        $json = (string) json_encode($this->validar()->executar(self::CODIGO), \JSON_UNESCAPED_UNICODE);

        foreach ([self::CPF_ANA, 'ana@exemplo.com', 'bia@exemplo.com', '203.0.113.7', 'rubrica', (string) $this->tenant->getName(), '"id"', '"email"', '"cpf"'] as $proibido) {
            self::assertStringNotContainsString($proibido, $json, $proibido);
        }
    }

    #[TestDox('Aguardando com o prazo passado aparece como Expirado, como na lista')]
    public function testExpirado(): void
    {
        $this->enviadoComCodigo();
        $this->relogio->modify('+90 days');

        self::assertSame('assinatura_eletronica_tela.js.situacao.expirado', $this->validar()->executar(self::CODIGO)['situacao'] ?? null);
    }

    #[TestDox('na Lixeira continua valendo, com a situação que o documento tem (o envio cancelado)')]
    public function testNaLixeira(): void
    {
        $this->enviadoComCodigo()->moverParaLixeira(null, new \DateTimeImmutable('2026-11-09 09:45:00'));

        self::assertSame('assinatura_eletronica_tela.js.situacao.cancelado', $this->validar()->executar(self::CODIGO)['situacao'] ?? null);
    }

    #[TestDox('não achado: no formato do selo, procura e devolve nulo')]
    public function testNaoAchado(): void
    {
        $this->enviadoComCodigo();

        self::assertNull($this->validar()->executar('BJA-ZZZZ-ZZZZ-ZZZZ'));
        self::assertNull($this->validar()->executar(' bja-2345-6789-abcd '));

        self::assertSame(['BJA-ZZZZ-ZZZZ-ZZZZ', 'BJA-2345-6789-ABCD'], $this->procurados);
    }

    /** @return iterable<string, array{string}> */
    public static function foraDoFormato(): iterable
    {
        yield 'vazio' => ['   '];
        yield 'qualquer texto' => ['qualquer'];
        yield 'longo demais' => [str_repeat('A', 65)];
        yield 'um símbolo a mais' => [self::CODIGO . 'X'];
        yield 'grupo curto' => ['BJA-AB23-CD45-EF6'];
        yield 'zero, fora do alfabeto' => ['BJA-0000-0000-0000'];
        yield 'um, fora do alfabeto' => ['BJA-1111-AB23-CD45'];
        yield 'I e O, fora do alfabeto' => ['BJA-IIII-OOOO-AB23'];
        yield 'outro prefixo' => ['BJB-AB23-CD45-EF67'];
        yield 'sem os hífens' => ['BJAAB23CD45EF67'];
        yield 'espaço no meio' => ['BJA-AB23 -CD45-EF67'];
        yield 'quebra de linha no meio' => ["BJA-AB23-CD45-EF67\nBJA-AB23-CD45-EF67"];
        yield 'aspas e sinais de tag' => ['BJA-"<>"-CD45-EF67'];
        yield 'texto que não é UTF-8' => ["\xff\xfe"];
    }

    #[DataProvider('foraDoFormato')]
    #[TestDox('fora do formato BJA-XXXX-XXXX-XXXX ($_dataName): nulo, sem ir ao repositório')]
    public function testForaDoFormatoNaoVaiAoBanco(string $digitado): void
    {
        $this->enviadoComCodigo();

        self::assertNull($this->validar()->executar($digitado));
        self::assertSame([], $this->procurados);
    }

    private function validar(): ValidarCodigoUseCase
    {
        return new ValidarCodigoUseCase($this->documentos, new ProjecaoPublicaDaValidacao(new IdentityTranslator(), $this->relogio));
    }

    private function enviadoComCodigo(): DocumentoParaAssinar
    {
        $documento = $this->documentoEnviado();
        $documento->definirCodigo(self::CODIGO);

        return $documento;
    }
}
