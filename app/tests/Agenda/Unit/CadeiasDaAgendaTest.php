<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Agenda\Scheduler\CadeiasDaAgenda;
use App\Tests\Shared\CadeiaRecorrenteConferida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CadeiasDaAgenda::class)]
final class CadeiasDaAgendaTest extends TestCase
{
    #[TestDox('Push a cada 60 s e Google Agenda a cada 300 s, os dois redespachados para a fila async')]
    public function testIntervalosETransport(): void
    {
        self::assertSame([
            [VarrerLembretesDePush::class, 60, 'async'],
            [VarrerGoogleAgenda::class, 300, 'async'],
        ], CadeiaRecorrenteConferida::voltas(new CadeiasDaAgenda()));
    }
}
