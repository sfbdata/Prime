<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ConexaoLeituraTest` prova o comportamento de `ConexaoLeitura` com um DSN construído à
 * mão (`new ConexaoLeitura('')`) — isso testa a CLASSE, não a FIAÇÃO. Este teste cobre o
 * caminho real: o serviço como o CONTAINER efetivamente entrega quando `DATABASE_URL_LEITURA`
 * está ausente do ambiente (o caso comum em qualquer ambiente onde a variável não foi
 * configurada — dev, test, ou prod antes do dono cadastrar a variável).
 *
 * Existe porque `%env(default::DATABASE_URL_LEITURA)%` (sem o prefixo `string:`) escondia um
 * `TypeError`: o `EnvVarProcessor` do Symfony devolve `null` para o fallback vazio de
 * `default::`, não `''` — e o construtor de `ConexaoLeitura` declara `string $dsn` não-nulável.
 * O container estourava ANTES de `conexao()` rodar, tornando o `RuntimeException` com a
 * mensagem "configure DATABASE_URL_LEITURA" inalcançável pelo caminho real, apesar de
 * `ConexaoLeituraTest::testDsnVazioFalhaComMensagemClara` passar (ele nunca passa pelo
 * `EnvVarProcessor` — constrói a string vazia à mão).
 */
final class ConexaoLeituraServicoTest extends KernelTestCase
{
    public function testServicoDoContainerFalhaComMensagemClaraQuandoAVariavelEstaAusente(): void
    {
        if (($valor = getenv('DATABASE_URL_LEITURA')) !== false && trim($valor) !== '') {
            self::markTestSkipped(
                'Este teste pressupõe DATABASE_URL_LEITURA ausente do ambiente — está definida.',
            );
        }

        self::bootKernel();
        $conexao = static::getContainer()->get(ConexaoLeitura::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DATABASE_URL_LEITURA/');

        $conexao->conexao();
    }
}
