<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Pasta\DTO\PastaPushOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PastaPushOutput::class)]
#[Group('pasta')]
final class PastaPushOutputTest extends TestCase
{
    #[TestDox('Pasta sem processo vinculado: temProcesso falso — é o estado vazio de 991 das 1.079 pastas')]
    public function testSemProcessoVinculado(): void
    {
        $push = PastaPushOutput::montar([], [], 100);

        self::assertFalse($push->temProcesso);
        self::assertSame(0, $push->total);
        self::assertNull($push->numeroUnico);
    }

    #[TestDox('Pasta com processo e sem publicação: temProcesso verdadeiro e total zero — o outro estado vazio')]
    public function testComProcessoSemPublicacao(): void
    {
        $push = PastaPushOutput::montar([], ['07011111111111111111'], 100);

        self::assertTrue($push->temProcesso);
        self::assertSame(0, $push->total);
    }

    #[TestDox('Conta o total e as não lidas separadamente')]
    public function testContaTotalENaoLidas(): void
    {
        $push = PastaPushOutput::montar(
            [$this->item(1, lida: false), $this->item(2, lida: true), $this->item(3, lida: false)],
            ['07011111111111111111'],
            100,
        );

        self::assertSame(3, $push->total);
        self::assertSame(2, $push->naoLidas);
    }

    #[TestDox('Com um processo só, guarda o número para o link do módulo; com dois, não inventa um')]
    public function testNumeroUnicoSoComUmProcesso(): void
    {
        self::assertSame(
            '07011111111111111111',
            PastaPushOutput::montar([], ['07011111111111111111'], 100)->numeroUnico,
        );
        self::assertNull(
            PastaPushOutput::montar([], ['07011111111111111111', '07022222222222222222'], 100)->numeroUnico,
        );
    }

    #[TestDox('Número em branco não conta como processo vinculado')]
    public function testNumeroEmBrancoNaoContaComoProcesso(): void
    {
        $push = PastaPushOutput::montar([], ['', '   '], 100);

        self::assertFalse($push->temProcesso);
    }

    #[TestDox('Bater no limite é avisado, para a tela não dizer que aquilo é tudo')]
    public function testAvisaQuandoBateNoLimite(): void
    {
        $tresItens = [$this->item(1), $this->item(2), $this->item(3)];

        self::assertTrue(PastaPushOutput::montar($tresItens, ['07011111111111111111'], 3)->limiteAtingido);
        self::assertFalse(PastaPushOutput::montar($tresItens, ['07011111111111111111'], 4)->limiteAtingido);
    }

    private function item(int $id, bool $lida = false): PublicacaoDjenListaItem
    {
        return PublicacaoDjenListaItem::fromRow([
            'id' => $id,
            'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => 'Intimação',
            'numeroProcessoComMascara' => '0701111-11.1111.1.11.1111',
            'numeroProcesso' => '07011111111111111111',
            'dataDisponibilizacao' => new \DateTimeImmutable('2026-08-20'),
            'nomeOrgao' => '1ª Vara Cível',
            'lida' => $lida,
            'processoId' => null,
        ]);
    }
}
