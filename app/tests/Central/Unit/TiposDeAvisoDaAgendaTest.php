<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Service\TiposDeAvisoDaCentral;
use App\Entity\Notificacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TiposDeAvisoDaCentral::class)]
final class TiposDeAvisoDaAgendaTest extends TestCase
{
    #[TestDox('Aviso da Agenda como o tipo "agenda" do protótipo de 18/10: grupo atenção, ícone de calendário, azul, nível 2')]
    public function testAgenda(): void
    {
        $sut = new TiposDeAvisoDaCentral();

        foreach ([Notificacao::TIPO_EVENTO_CRIADO, Notificacao::TIPO_EVENTO_CANCELADO] as $tipo) {
            self::assertSame(['grupo' => 'atencao', 'rotulo' => 'Agenda', 'icone' => 'bi-calendar-event', 'cor' => '#0a7aad', 'nivel' => 2], $sut->para($tipo));
        }
    }
}
