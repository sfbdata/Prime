<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\PropostasDoConector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O hash que prende os dados à proposta e a forma do resumo comparado (spec mcp-remoto-oauth 6.7, itens 1 e 3).
 */
#[CoversClass(PropostasDoConector::class)]
final class PropostasDoConectorTest extends TestCase
{
    #[TestDox('a ordem das chaves dos objetos não muda o hash (o banco pode devolvê-las em outra ordem); a ordem das listas muda')]
    public function testHashIgnoraOrdemDasChaves(): void
    {
        $a = ['pasta_id' => 1, 'itens' => [['id' => 7, 'nome' => 'rg.jpg'], ['id' => 8, 'nome' => 'cpf.pdf']], 'valor' => 10.0];
        $b = ['valor' => 10.0, 'itens' => [['nome' => 'rg.jpg', 'id' => 7], ['nome' => 'cpf.pdf', 'id' => 8]], 'pasta_id' => 1];
        $outraOrdem = ['pasta_id' => 1, 'itens' => [['id' => 8, 'nome' => 'cpf.pdf'], ['id' => 7, 'nome' => 'rg.jpg']], 'valor' => 10.0];

        self::assertSame(PropostasDoConector::hashDosDados($a), PropostasDoConector::hashDosDados($b));
        self::assertNotSame(PropostasDoConector::hashDosDados($a), PropostasDoConector::hashDosDados($outraOrdem));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', PropostasDoConector::hashDosDados($a));
    }

    #[TestDox('qualquer valor diferente muda o hash, inclusive o tipo (10 e "10")')]
    public function testHashMudaComOValor(): void
    {
        self::assertNotSame(PropostasDoConector::hashDosDados(['valor' => 10]), PropostasDoConector::hashDosDados(['valor' => '10']));
        self::assertNotSame(PropostasDoConector::hashDosDados(['valor' => 10]), PropostasDoConector::hashDosDados(['valor' => 11]));
    }

    #[TestDox('o resumo é comparado com quebras \n e sem espaço nas pontas; o miolo não muda')]
    public function testNormalizarResumo(): void
    {
        self::assertSame("Excluir:\nrg.jpg  e cpf.pdf", PropostasDoConector::normalizarResumo("  Excluir:\r\nrg.jpg  e cpf.pdf\r\n"));
        self::assertSame("a\nb", PropostasDoConector::normalizarResumo("a\rb"));
    }
}
