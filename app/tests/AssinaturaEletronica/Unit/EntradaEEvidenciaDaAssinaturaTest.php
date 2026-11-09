<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\EntradaDaAssinatura;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * O que a tela pública manda ao assinar (nome, CPF, código, modo, rubrica em PNG, aceite) e a evidência tirada da
 * requisição (IP, porta e navegador cortado em 500), contrato da etapa 2, seções 4.3 e 5.2.
 */
#[CoversClass(EntradaDaAssinatura::class)]
#[CoversClass(EvidenciaDaRequisicao::class)]
final class EntradaEEvidenciaDaAssinaturaTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    #[TestDox('entrada válida: espaços do nome colapsados, CPF só com dígitos e o PNG decodificado')]
    public function testEntradaValida(): void
    {
        $entrada = EntradaDaAssinatura::deJson(['nome' => '  Ana   Maria Lima ', 'cpf' => '529.982.247-25', 'codigo' => '123456', 'modo' => 'digitada', 'rubrica' => 'data:image/png;base64,' . self::PNG_1X1, 'aceite' => true]);
        $entrada->exigirNome();
        $entrada->exigirCpf();

        self::assertSame('Ana Maria Lima', $entrada->nome);
        self::assertSame('52998224725', $entrada->cpf);
        self::assertTrue($entrada->codigoBemFormado());
        self::assertTrue($entrada->aceite);
        self::assertSame(base64_decode(self::PNG_1X1, true), $entrada->rubricaPng());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function recusas(): iterable
    {
        $base = ['nome' => 'Ana Lima', 'cpf' => '52998224725', 'codigo' => '123456', 'rubrica' => 'data:image/png;base64,' . self::PNG_1X1, 'aceite' => true];
        yield 'nome de uma palavra' => [['nome' => 'Ana'] + $base, 'assinatura_eletronica.erro.assinar_nome'];
        yield 'CPF com dígito errado' => [['cpf' => '52998224724'] + $base, 'assinatura_eletronica.erro.assinar_cpf'];
        yield 'sem rubrica' => [['rubrica' => ''] + $base, 'assinatura_eletronica.erro.assinar_sem_rubrica'];
        yield 'rubrica que não é PNG' => [['rubrica' => 'data:image/png;base64,' . base64_encode('GIF89a')] + $base, 'assinatura_eletronica.erro.rubrica_invalida'];
        yield 'rubrica fora de data URL' => [['rubrica' => self::PNG_1X1] + $base, 'assinatura_eletronica.erro.rubrica_invalida'];
        yield 'rubrica acima de 200 KB' => [['rubrica' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('a', 205 * 1024))] + $base, 'assinatura_eletronica.erro.rubrica_grande'];
        yield 'modo desconhecido' => [['modo' => 'foto'] + $base, 'assinatura_eletronica.erro.dados_invalidos'];
    }

    /** @param array<string, mixed> $json */
    #[DataProvider('recusas')]
    #[TestDox('recusa: $_dataName')]
    public function testRecusas(array $json, string $chave): void
    {
        try {
            $entrada = EntradaDaAssinatura::deJson($json);
            $entrada->exigirNome();
            $entrada->exigirCpf();
            $entrada->rubricaPng();
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame($chave, $e->getMessage());
        }
    }

    #[TestDox('código fora do formato de 6 dígitos nem é conferido; aceite só com true')]
    public function testCodigoEAceite(): void
    {
        foreach (['12345', '1234567', 'abcdef', ''] as $codigo) {
            self::assertFalse(EntradaDaAssinatura::deJson(['codigo' => $codigo])->codigoBemFormado(), $codigo);
        }
        self::assertFalse(EntradaDaAssinatura::deJson(['aceite' => 'true'])->aceite);
    }

    #[TestDox('evidência: IP da requisição, porta válida e navegador cortado em 500 caracteres')]
    public function testEvidencia(): void
    {
        $evidencia = EvidenciaDaRequisicao::da(Request::create('/assinar/x', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'REMOTE_PORT' => '51234', 'HTTP_USER_AGENT' => str_repeat('N', 600)]));

        self::assertSame('203.0.113.9', $evidencia->ip);
        self::assertSame(51234, $evidencia->porta);
        self::assertSame(500, mb_strlen((string) $evidencia->navegador));

        $semPorta = EvidenciaDaRequisicao::da(Request::create('/assinar/x', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'REMOTE_PORT' => '70000', 'HTTP_USER_AGENT' => '']));
        self::assertNull($semPorta->porta);
        self::assertNull($semPorta->navegador);
    }
}
