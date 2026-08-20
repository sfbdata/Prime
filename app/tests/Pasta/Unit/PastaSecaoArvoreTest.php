<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\PastaSecao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PastaSecao::class)]
final class PastaSecaoArvoreTest extends TestCase
{
    private function secao(string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        return (new PastaSecao())->setNome($nome)->setPai($pai);
    }

    public function testSecaoSemPaiEstaNoNivelUm(): void
    {
        self::assertSame(1, $this->secao('RAIZ')->getProfundidade());
    }

    public function testProfundidadeContaACadeiaInteira(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $b);

        self::assertSame(3, $c->getProfundidade());
    }

    public function testAlturaDeFolhaEhUm(): void
    {
        self::assertSame(1, $this->secao('FOLHA')->getAltura());
    }

    public function testAlturaContaORamoMaisFundo(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $b);
        // ramo curto, para provar que a altura pega o MAIOR
        $this->secao('D', $a);

        self::assertSame(3, $a->getAltura());
    }

    public function testDescendeDeEnxergaAvo(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $b);

        self::assertTrue($c->descendeDe($a));
    }

    public function testNaoDescendeDeIrma(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $a);

        self::assertFalse($b->descendeDe($c));
    }

    public function testNaoDescendeDeSiMesma(): void
    {
        $a = $this->secao('A');

        self::assertFalse($a->descendeDe($a));
    }

    #[TestDox('ciclo gravado por fora dos guards (ex.: desfazer da auditoria) não estoura a memória em getAltura()')]
    public function testCicloNoPaiNaoEstouraAMemoriaEmGetAltura(): void
    {
        // Um ciclo NUNCA deveria existir — setPai() sozinho não impede porque quem grava o guard é
        // o UseCase, não a entidade. É exatamente o que DesfazerAlteracaoAuditLogUseCase faz: chama
        // o setter direto, sem UseCase e sem guard, e é o caminho que provou o estouro de memória
        // (getFilhas() de A contém B, getFilhas() de B contém A — um foreach sem teto nunca para).
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $a->setPai($b);

        self::assertGreaterThan(0, $a->getAltura(), 'tem de RETORNAR um número, não estourar a pilha/memória');
    }
}
