<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\SeloDaTrilhaDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O selo da trilha do Ponto (E9): HMAC-SHA256 determinístico sobre o JSON canônico da linha.
 */
#[CoversClass(SeloDaTrilhaDoPonto::class)]
final class SeloDaTrilhaDoPontoTest extends TestCase
{
    private const CHAVE = 'chave-de-32-bytes-para-o-teste!!';

    #[TestDox('Determinístico: a mesma linha dá o mesmo selo, e é o HMAC-SHA256 do JSON canônico')]
    public function testDeterministico(): void
    {
        $linha = self::linha();

        self::assertSame(SeloDaTrilhaDoPonto::calcular(self::CHAVE, $linha), SeloDaTrilhaDoPonto::calcular(self::CHAVE, $linha));
        self::assertSame(hash_hmac('sha256', SeloDaTrilhaDoPonto::mensagem($linha), self::CHAVE), SeloDaTrilhaDoPonto::calcular(self::CHAVE, $linha));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SeloDaTrilhaDoPonto::calcular(self::CHAVE, $linha));
        self::assertSame(
            '{"acao":"batida_registrada","autor_id":5,"autor_nome":"Ana Souza","detalhe":{"a":1,"b":{"x":"ç","y":[2,1]}},"em":"2026-03-10T08:01:02","selo_anterior":null,"sequencia":1,"tenant_id":9}',
            SeloDaTrilhaDoPonto::mensagem($linha),
            'chaves em ordem em todos os níveis, listas na ordem, acento e barra sem escape, hora ISO em segundos',
        );
    }

    #[TestDox('A ordem das chaves do detalhe e o formato da hora (espaço ou T) não mudam o selo: é o que volta do banco')]
    public function testCanonico(): void
    {
        $outra = self::linha();
        $outra['detalhe'] = ['b' => ['y' => [2, 1], 'x' => 'ç'], 'a' => 1];
        $outra['em'] = '2026-03-10T08:01:02';

        self::assertSame(SeloDaTrilhaDoPonto::calcular(self::CHAVE, self::linha()), SeloDaTrilhaDoPonto::calcular(self::CHAVE, $outra));
    }

    #[TestDox('Qualquer campo mexido muda o selo')]
    #[DataProvider('mexidas')]
    public function testCampoMexidoMudaOSelo(string $campo, mixed $valor): void
    {
        $mexida = self::linha();
        $mexida[$campo] = $valor;

        self::assertNotSame(SeloDaTrilhaDoPonto::calcular(self::CHAVE, self::linha()), SeloDaTrilhaDoPonto::calcular(self::CHAVE, $mexida));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function mexidas(): iterable
    {
        yield 'selo anterior' => ['selo_anterior', str_repeat('a', 64)];
        yield 'escritório' => ['tenant_id', 10];
        yield 'sequência' => ['sequencia', 2];
        yield 'hora' => ['em', '2026-03-10 08:01:03'];
        yield 'autor' => ['autor_id', 6];
        yield 'nome do autor' => ['autor_nome', 'Ana S.'];
        yield 'ação' => ['acao', 'justificativa_abonada'];
        yield 'detalhe' => ['detalhe', ['a' => 2, 'b' => ['x' => 'ç', 'y' => [2, 1]]]];
        yield 'ordem de uma lista' => ['detalhe', ['a' => 1, 'b' => ['x' => 'ç', 'y' => [1, 2]]]];
    }

    #[TestDox('Outra chave, outro selo')]
    public function testOutraChave(): void
    {
        self::assertNotSame(SeloDaTrilhaDoPonto::calcular(self::CHAVE, self::linha()), SeloDaTrilhaDoPonto::calcular('outra', self::linha()));
    }

    /**
     * @return array{selo_anterior: ?string, tenant_id: int, sequencia: int, em: string, autor_id: ?int,
     *     autor_nome: ?string, acao: string, detalhe: array<string, mixed>}
     */
    private static function linha(): array
    {
        return [
            'selo_anterior' => null,
            'tenant_id'     => 9,
            'sequencia'     => 1,
            'em'            => '2026-03-10 08:01:02',
            'autor_id'      => 5,
            'autor_nome'    => 'Ana Souza',
            'acao'          => 'batida_registrada',
            'detalhe'       => ['a' => 1, 'b' => ['x' => 'ç', 'y' => [2, 1]]],
        ];
    }
}
