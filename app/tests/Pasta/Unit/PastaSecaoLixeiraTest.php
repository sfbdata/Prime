<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A lápide da lixeira nas entidades (D7): marcar e restaurar recusam a segunda chamada (o autor e
 * a data da exclusão de verdade não podem ser sobrescritos); a subárvore recebe o mesmo carimbo;
 * o restaurar compara carimbo por VALOR (o que vem do banco é outro objeto) e respeita a trava
 * anti-ciclo (`LIMITE_SEGURANCA`) em vez de estourar a pilha.
 */
#[CoversClass(PastaSecao::class)]
#[CoversClass(PastaDocumento::class)]
final class PastaSecaoLixeiraTest extends TestCase
{
    private User $autor;

    protected function setUp(): void
    {
        $this->autor = (new User())->setEmail('autor@test.com');
    }

    private function documento(PastaSecao $secao): PastaDocumento
    {
        $d = (new PastaDocumento())->setSecao($secao);
        $secao->getDocumentos()->add($d);

        return $d;
    }

    #[TestDox('marcar duas vezes é recusado; restaurar o que não está na lixeira também')]
    public function testMarcarERestaurarRecusamOEstadoErrado(): void
    {
        $secao = (new PastaSecao())->setNome('A');
        $doc   = new PastaDocumento();
        $em    = new \DateTimeImmutable('2026-10-07 10:00:00');

        self::assertFalse($secao->estaNaLixeira());
        self::assertFalse($doc->estaNaLixeira());

        $secao->marcarExcluido($this->autor, $em);
        $doc->marcarExcluido($this->autor, $em);
        self::assertSame($em, $secao->getExcluidoEm());
        self::assertSame($this->autor, $doc->getExcluidoPor());

        try {
            $secao->marcarExcluido((new User())->setEmail('outro@test.com'), new \DateTimeImmutable());
            self::fail('a segunda marcação sobrescreveria o autor e a data de verdade');
        } catch (\LogicException) {
            self::assertSame($this->autor, $secao->getExcluidoPor());
        }

        $secao->restaurar();
        $doc->restaurar();
        self::assertFalse($secao->estaNaLixeira());
        self::assertNull($secao->getExcluidoPor());
        self::assertNull($doc->getExcluidoEm());

        $this->expectException(\LogicException::class);
        $doc->restaurar();
    }

    #[TestDox('marcarArvoreExcluida: mesmo carimbo em todo nível; conta só a descendência nas subpastas e todos os arquivos')]
    public function testArvoreInteiraComOMesmoCarimbo(): void
    {
        $mae   = (new PastaSecao())->setNome('MAE');
        $filha = (new PastaSecao())->setNome('FILHA')->setPai($mae);
        $neta  = (new PastaSecao())->setNome('NETA')->setPai($filha);
        $d1    = $this->documento($mae);
        $d2    = $this->documento($neta);
        $em    = new \DateTimeImmutable('2026-10-07 10:00:00');

        $contagem = $mae->marcarArvoreExcluida($this->autor, $em);

        self::assertSame(['subpastas' => 2, 'arquivos' => 2], $contagem);
        foreach ([$mae, $filha, $neta, $d1, $d2] as $item) {
            self::assertSame($em, $item->getExcluidoEm());
        }
    }

    #[TestDox('restaurarArvore compara o carimbo por valor e deixa na lixeira o que tem outro carimbo')]
    public function testRestaurarArvorePorValorDoCarimbo(): void
    {
        $mae    = (new PastaSecao())->setNome('MAE');
        $filha  = (new PastaSecao())->setNome('FILHA')->setPai($mae);
        $doc    = $this->documento($filha);
        $antigo = $this->documento($mae);
        $antigo->marcarExcluido($this->autor, new \DateTimeImmutable('2026-09-01 00:00:00'));
        $mae->marcarArvoreExcluida($this->autor, new \DateTimeImmutable('2026-10-07 10:00:00'));

        // Outro objeto, mesmo valor — como o carimbo lido do banco.
        $contagem = $mae->restaurarArvore(new \DateTimeImmutable('2026-10-07 10:00:00'));

        self::assertSame(['subpastas' => 1, 'arquivos' => 1], $contagem);
        self::assertFalse($mae->estaNaLixeira());
        self::assertFalse($filha->estaNaLixeira());
        self::assertFalse($doc->estaNaLixeira());
        self::assertTrue($antigo->estaNaLixeira(), 'carimbo de outra ação: fica');
    }

    #[TestDox('ciclo gravado por fora dos guards (a.pai = b, b.pai = a): marcar e restaurar param em LIMITE_SEGURANCA')]
    public function testCicloNaoEstouraAPilha(): void
    {
        $a = (new PastaSecao())->setNome('A');
        $b = (new PastaSecao())->setNome('B')->setPai($a);
        $a->setPai($b);
        $em = new \DateTimeImmutable('2026-10-07 10:00:00');

        $marcados = $a->marcarArvoreExcluida($this->autor, $em);
        self::assertTrue($a->estaNaLixeira());
        self::assertTrue($b->estaNaLixeira());
        self::assertSame(PastaSecao::LIMITE_SEGURANCA - 1, $marcados['subpastas'], 'cada nível do laço conta uma vez até a trava');

        // No restaurar o laço se fecha sozinho: `a`, já restaurada, não tem mais carimbo e não
        // casa de novo — `b` entra uma vez e a volta para em `a`.
        $restaurados = $a->restaurarArvore($em);
        self::assertFalse($a->estaNaLixeira());
        self::assertFalse($b->estaNaLixeira());
        self::assertSame(1, $restaurados['subpastas']);
    }
}
