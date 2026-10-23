<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\Scheduler\CadeiasDoComercial;
use App\Tests\Shared\CadeiaRecorrenteConferida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CadeiasDoComercial::class)]
final class CadeiasDoComercialTest extends TestCase
{
    #[TestDox('Automações a cada 900 s e expansor a cada 60 s, os dois redespachados para a fila comercial')]
    public function testIntervalosETransport(): void
    {
        self::assertSame([
            [VarrerAutomacoes::class, 900, 'comercial'],
            [ExpandirCampanhas::class, 60, 'comercial'],
        ], CadeiaRecorrenteConferida::voltas(new CadeiasDoComercial()));
    }
}
