<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EscopoDeAuditoriaDePlataforma::class)]
final class EscopoDeAuditoriaDePlataformaTest extends TestCase
{
    #[TestDox('liga só durante a ação, devolve o resultado dela e desliga no fim')]
    public function testLigaSoDuranteAAcao(): void
    {
        $escopo = new EscopoDeAuditoriaDePlataforma();
        self::assertFalse($escopo->estaLigado());

        $resultado = $escopo->executar(static fn (): bool => $escopo->estaLigado());

        self::assertTrue($resultado);
        self::assertFalse($escopo->estaLigado());
    }

    #[TestDox('desliga mesmo quando a ação lança (finally)')]
    public function testDesligaQuandoAAcaoLanca(): void
    {
        $escopo = new EscopoDeAuditoriaDePlataforma();

        try {
            $escopo->executar(static function (): never {
                throw new \RuntimeException('falhou');
            });
            self::fail('a exceção deveria subir');
        } catch (\RuntimeException) {
        }

        self::assertFalse($escopo->estaLigado());
    }

    #[TestDox('aninhado: continua ligado até a ação de fora terminar')]
    public function testAninhado(): void
    {
        $escopo = new EscopoDeAuditoriaDePlataforma();

        $escopo->executar(static function () use ($escopo): void {
            $escopo->executar(static fn (): null => null);
            self::assertTrue($escopo->estaLigado());
        });

        self::assertFalse($escopo->estaLigado());
    }
}
