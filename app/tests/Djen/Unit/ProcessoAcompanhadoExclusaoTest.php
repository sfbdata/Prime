<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Entity\ProcessoAcompanhado;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessoAcompanhado::class)]
final class ProcessoAcompanhadoExclusaoTest extends TestCase
{
    private function linha(): ProcessoAcompanhado
    {
        return new ProcessoAcompanhado($this->createStub(Tenant::class), '07022222220268070001');
    }

    #[Test]
    public function excluirParaDeAcompanharEMarcaAData(): void
    {
        $linha = $this->linha();
        $quando = new \DateTimeImmutable('2026-10-27 10:00:00');

        $linha->excluir($quando);

        self::assertFalse($linha->isAcompanhando());
        self::assertSame($quando, $linha->getExcluidoEm());
    }

    #[Test]
    public function incluirDeNovoLimpaAMarcaDeExclusao(): void
    {
        $linha = $this->linha();
        $linha->excluir(new \DateTimeImmutable());

        $linha->limparExclusao();
        $linha->acompanhar();

        self::assertNull($linha->getExcluidoEm());
        self::assertTrue($linha->isAcompanhando());
    }
}
