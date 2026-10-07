<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As ressalvas que o número sozinho não conta, na `nota` (title do cabeçalho) de cada coluna extra.
 */
#[CoversClass(Extras::class)]
final class ColunasExtrasDoDashboardTest extends TestCase
{
    #[TestDox('Eventos na agenda: a nota diz que o recorrente conta uma vez, pela ocorrência-base')]
    public function testNotaDosEventosExplicaORecorrente(): void
    {
        $nota = Extras::CATALOGO[Extras::EVENTOS_AGENDA]['nota'];

        self::assertStringContainsString('recorrente conta uma vez, pela ocorrência-base', $nota);
    }
}
