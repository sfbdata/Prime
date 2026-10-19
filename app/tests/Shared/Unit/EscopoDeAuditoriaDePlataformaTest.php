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

    #[TestDox('escopo de conta: liga com o id da conta alvo; o de plataforma tem conta nula')]
    public function testEscopoDeConta(): void
    {
        $escopo = new EscopoDeAuditoriaDePlataforma();
        self::assertNull($escopo->contaAlvo());

        self::assertSame([true, 42], $escopo->executarComoConta(42, static fn (): array => [$escopo->estaLigado(), $escopo->contaAlvo()]));
        self::assertSame([true, null], $escopo->executar(static fn (): array => [$escopo->estaLigado(), $escopo->contaAlvo()]));
        self::assertFalse($escopo->estaLigado());
        self::assertNull($escopo->contaAlvo());
    }

    #[TestDox('escopos aninhados: vale o mais interno, e o de fora volta quando o de dentro termina, também com exceção')]
    public function testAninhadoComConta(): void
    {
        $escopo = new EscopoDeAuditoriaDePlataforma();

        $escopo->executarComoConta(7, static function () use ($escopo): void {
            $escopo->executar(static fn (): null => self::assertNull($escopo->contaAlvo()));
            try {
                $escopo->executarComoConta(9, static function (): never {
                    throw new \RuntimeException('falhou');
                });
            } catch (\RuntimeException) {
            }
            self::assertSame(7, $escopo->contaAlvo());
        });

        self::assertFalse($escopo->estaLigado());
    }
}
