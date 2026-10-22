<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TipoDeDemanda::class)]
#[Group('tarefa')]
final class TipoDeDemandaTest extends TestCase
{
    #[TestDox('Judicial e Administrativo passam pelas 8 caixas, na ordem da esteira')]
    public function testJudicialEAdministrativoPassamPorTodas(): void
    {
        self::assertSame(EtapaDaPasta::cases(), TipoDeDemanda::Judicial->sequencia());
        self::assertSame(EtapaDaPasta::cases(), TipoDeDemanda::Administrativo->sequencia());
    }

    #[TestDox('Consultoria pula Documentação e Acompanhamento')]
    public function testConsultoriaPulaDuas(): void
    {
        self::assertSame([
            EtapaDaPasta::Comercial,
            EtapaDaPasta::Abertura,
            EtapaDaPasta::Controladoria,
            EtapaDaPasta::Juridico,
            EtapaDaPasta::Protocolo,
            EtapaDaPasta::Encerramento,
        ], TipoDeDemanda::Consultoria->sequencia());
    }

    #[TestDox('posicaoDe conta a partir de 1 na sequência do tipo; null se o tipo não passa pela etapa')]
    public function testPosicaoDe(): void
    {
        self::assertSame(1, TipoDeDemanda::Judicial->posicaoDe(EtapaDaPasta::Comercial));
        self::assertSame(5, TipoDeDemanda::Judicial->posicaoDe(EtapaDaPasta::Juridico));
        self::assertSame(4, TipoDeDemanda::Consultoria->posicaoDe(EtapaDaPasta::Juridico));
        self::assertSame(6, TipoDeDemanda::Consultoria->posicaoDe(EtapaDaPasta::Encerramento));
        self::assertNull(TipoDeDemanda::Consultoria->posicaoDe(EtapaDaPasta::Documentacao));
        self::assertNull(TipoDeDemanda::Consultoria->posicaoDe(EtapaDaPasta::Acompanhamento));
    }

    #[TestDox('proximaDepoisDe segue a sequência do tipo; null na última e na etapa fora da sequência')]
    public function testProximaDepoisDe(): void
    {
        self::assertSame(EtapaDaPasta::Abertura, TipoDeDemanda::Judicial->proximaDepoisDe(EtapaDaPasta::Comercial));
        self::assertSame(EtapaDaPasta::Documentacao, TipoDeDemanda::Judicial->proximaDepoisDe(EtapaDaPasta::Controladoria));
        self::assertSame(EtapaDaPasta::Juridico, TipoDeDemanda::Consultoria->proximaDepoisDe(EtapaDaPasta::Controladoria));
        self::assertSame(EtapaDaPasta::Encerramento, TipoDeDemanda::Consultoria->proximaDepoisDe(EtapaDaPasta::Protocolo));
        self::assertNull(TipoDeDemanda::Judicial->proximaDepoisDe(EtapaDaPasta::Encerramento));
        self::assertNull(TipoDeDemanda::Consultoria->proximaDepoisDe(EtapaDaPasta::Encerramento));
        self::assertNull(TipoDeDemanda::Consultoria->proximaDepoisDe(EtapaDaPasta::Acompanhamento));
    }
}
