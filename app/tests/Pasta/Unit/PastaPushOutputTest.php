<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PastaPushOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{?string, bool}> */
    public static function tiposDaComunicacao(): iterable
    {
        // Os três tipos medidos em prod (06/10): 363 Intimação, 20 Edital, 4 Lista de distribuição.
        yield 'Intimação conta' => ['Intimação', true];
        yield 'INTIMAÇÃO em caixa-alta conta' => ['INTIMAÇÃO', true];
        yield 'Decisão conta' => ['Decisão', true];
        yield 'Decisão interlocutória conta' => ['Decisão interlocutória', true];
        yield 'Edital não conta' => ['Edital', false];
        yield 'Lista de distribuição não conta' => ['Lista de distribuição', false];
        yield 'Citação não conta (fora da regra do desenho)' => ['Citação', false];
        yield 'Despacho não conta (fora da regra do desenho)' => ['Despacho', false];
        yield 'Sentença não conta (fora da regra do desenho)' => ['Sentença', false];
        yield 'tipo ausente não conta' => [null, false];
        yield 'tipo vazio não conta' => ['', false];
    }

    #[TestDox('Geram prazo: regra do desenho /Intima|Decis/ sobre o tipo — $tipo')]
    #[DataProvider('tiposDaComunicacao')]
    public function testTipoGeraPrazo(?string $tipo, bool $esperado): void
    {
        self::assertSame($esperado, PastaPushOutput::tipoGeraPrazo($tipo));
    }

    #[TestDox('Geram prazo por item: lê o tipo da publicação da lista')]
    public function testGeraPrazoPorItem(): void
    {
        $push = PastaPushOutput::montar(
            [$this->item(1, tipo: 'Intimação'), $this->item(2, tipo: 'Edital'), $this->item(3, tipo: null)],
            ['07011111111111111111'],
            100,
        );

        self::assertSame(
            [true, false, false],
            array_map($push->geraPrazo(...), $push->itens),
        );
    }

    #[TestDox('Geram prazo com lista vazia: nada a marcar e nada quebra')]
    public function testGeraPrazoComListaVazia(): void
    {
        $push = PastaPushOutput::montar([], ['07011111111111111111'], 100);

        self::assertSame([], array_filter($push->itens, $push->geraPrazo(...)));
    }

    #[TestDox('FPN-014c: aceita nota técnica quando o número da publicação (com máscara) é de um processo da pasta, em dígitos; senão não')]
    public function testAceitaNotaTecnicaPeloNumeroEmDigitos(): void
    {
        $item = $this->item(1);

        self::assertTrue(PastaPushOutput::montar([$item], ['07011111111111111111'], 100)->aceitaNotaTecnica($item));
        self::assertTrue(PastaPushOutput::montar([$item], ['0701111-11.1111.1.11.1111'], 100)->aceitaNotaTecnica($item), 'máscara dos dois lados não importa');
        self::assertFalse(PastaPushOutput::montar([$item], ['07022222222222222222'], 100)->aceitaNotaTecnica($item), 'processo de outra pasta: sem bloco de notas');
        self::assertFalse(PastaPushOutput::montar([$item], [], 100)->aceitaNotaTecnica($item));
    }

    #[TestDox('FPN-020: agrupa as notas por publicação da lista, na ordem recebida; publicação fora da lista e nota sem publicação ficam de fora')]
    public function testNotasPorPublicacao(): void
    {
        $item1 = $this->item(1);
        $item2 = $this->item(2);
        $item3 = $this->item(3);

        $push = PastaPushOutput::montar(
            [$item1, $item2, $item3],
            ['07011111111111111111'],
            100,
            [
                $this->nota(10, 1),
                $this->nota(11, 2),
                $this->nota(12, 1),
                $this->nota(13, 99),   // publicação que não está na lista
                $this->nota(14, null), // nota só do processo
            ],
        );

        self::assertSame([10, 12], array_map(static fn ($n): int => $n->id, $push->notasDe($item1)));
        self::assertSame([11], array_map(static fn ($n): int => $n->id, $push->notasDe($item2)));
        self::assertSame([], $push->notasDe($item3));
        self::assertSame(1, $push->notasDe($item1)[0]->publicacaoId);
    }

    #[TestDox('FPN-020: sem notas informadas (a folha impressa), nenhum cartão tem nota pendurada')]
    public function testSemNotasInformadas(): void
    {
        $item = $this->item(1);

        self::assertSame([], PastaPushOutput::montar([$item], ['07011111111111111111'], 100)->notasDe($item));
    }

    private function nota(int $id, ?int $publicacaoId): NotaTecnica
    {
        $tenant   = new Tenant();
        $processo = new Processo();
        (new \ReflectionProperty(Processo::class, 'id'))->setValue($processo, 7);
        $autor = new User();
        $autor->setFullName('Autora');

        $publicacao = null;
        if ($publicacaoId !== null) {
            $publicacao = new PublicacaoDjen();
            (new \ReflectionProperty(PublicacaoDjen::class, 'id'))->setValue($publicacao, $publicacaoId);
        }

        $nota = new NotaTecnica($tenant, $processo, $autor, '<p>Nota ' . $id . '</p>', $publicacao);
        (new \ReflectionProperty(NotaTecnica::class, 'id'))->setValue($nota, $id);

        return $nota;
    }

    private function item(int $id, bool $lida = false, ?string $tipo = 'Intimação'): PublicacaoDjenListaItem
    {
        return PublicacaoDjenListaItem::fromRow([
            'id' => $id,
            'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => $tipo,
            'numeroProcessoComMascara' => '0701111-11.1111.1.11.1111',
            'numeroProcesso' => '07011111111111111111',
            'dataDisponibilizacao' => new \DateTimeImmutable('2026-08-20'),
            'nomeOrgao' => '1ª Vara Cível',
            'lida' => $lida,
            'processoId' => null,
        ]);
    }
}
