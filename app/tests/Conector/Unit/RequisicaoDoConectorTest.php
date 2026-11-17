<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ContextoDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A requisição do `/mcp` em curso (spec mcp-remoto-oauth 4.1 e 6.2): falha fechada sem a abertura do portão, abrir
 * duas vezes recusa, e o `reset()` zera a chamada e as anotações (a requisição seguinte do mesmo processo começa vazia).
 */
#[CoversClass(RequisicaoDoConector::class)]
#[CoversClass(ChamadaDoConector::class)]
final class RequisicaoDoConectorTest extends TestCase
{
    #[TestDox('fechada: atual() lança e não há anotação')]
    public function testFechadaLanca(): void
    {
        $requisicao = new RequisicaoDoConector();

        self::assertFalse($requisicao->estaAberta());
        self::assertNull($requisicao->anotacao('ferramenta'));

        $this->expectException(\LogicException::class);
        $requisicao->atual();
    }

    #[TestDox('aberta: atual() devolve a chamada do portão; abrir de novo lança e mantém a primeira')]
    public function testAbertaUmaVezSo(): void
    {
        $requisicao = new RequisicaoDoConector();
        $chamada = self::chamada();
        $requisicao->abrir($chamada);

        self::assertTrue($requisicao->estaAberta());
        self::assertSame($chamada, $requisicao->atual());
        self::assertTrue($chamada->temEscopo('mcp:leitura'));
        self::assertFalse($chamada->temEscopo('mcp:escrita'));

        try {
            $requisicao->abrir(self::chamada());
            self::fail('abrir duas vezes deveria lançar');
        } catch (\LogicException) {
            self::assertSame($chamada, $requisicao->atual());
        }
    }

    #[TestDox('o reset limpa a chamada e as anotações; depois dele, atual() volta a lançar')]
    public function testResetLimpa(): void
    {
        $requisicao = new RequisicaoDoConector();
        $requisicao->abrir(self::chamada());
        $requisicao->anotar('ferramenta', 'quem_sou_eu');
        $requisicao->anotar('ids', ['pasta' => [12]]);

        self::assertSame('quem_sou_eu', $requisicao->anotacao('ferramenta'));
        self::assertSame(['ferramenta' => 'quem_sou_eu', 'ids' => ['pasta' => [12]]], $requisicao->anotacoes());

        $requisicao->reset();

        self::assertFalse($requisicao->estaAberta());
        self::assertSame([], $requisicao->anotacoes());
        $requisicao->abrir(self::chamada());
        self::assertTrue($requisicao->estaAberta(), 'depois do reset a requisição seguinte pode abrir');

        $requisicao->reset();
        $this->expectException(\LogicException::class);
        $requisicao->atual();
    }

    private static function chamada(): ChamadaDoConector
    {
        $pessoa = new User();
        $escritorio = new Tenant();

        return new ChamadaDoConector(
            new ContextoDoConector($pessoa, $escritorio, new UserTenant($pessoa, $escritorio)),
            concessaoId: 7,
            consentimentoId: 3,
            hostDoCliente: 'claude.ai',
            escopos: ['mcp:leitura'],
            conexaoValeAte: new \DateTimeImmutable('2026-11-23 10:00:00'),
        );
    }
}
