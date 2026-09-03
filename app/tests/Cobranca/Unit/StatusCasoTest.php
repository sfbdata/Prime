<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Enum\StatusCaso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `ehCobravel()` decide se um caso recebe movimento de cobrança — dívida, pagamento, acordo. É a régua
 * que os importadores da contábil consultam, e ela vale dinheiro: em 09/2026 o escritório judicializou
 * 54 casos da TOP LIFE I e o importe, que então perguntava "é ativo?" em vez de "não está encerrado?",
 * concluiu "unidade sem cobrança" e ia recriar 2.609 obrigações já existentes.
 *
 * Spec: `docs/specs/cobranca-importe-enxerga-caso-judicializado.md`.
 */
#[CoversClass(StatusCaso::class)]
final class StatusCasoTest extends TestCase
{
    #[TestDox('Só o ENCERRADO não é cobrável — judicializado é cobrança viva (SPEC §16)')]
    #[DataProvider('statusEExpectativa')]
    public function testEhCobravel(StatusCaso $status, bool $esperado): void
    {
        self::assertSame($esperado, $status->ehCobravel());
    }

    /** @return iterable<string, array{StatusCaso, bool}> */
    public static function statusEExpectativa(): iterable
    {
        yield 'ativo cobra' => [StatusCaso::Ativo, true];
        yield 'judicializado TAMBÉM cobra' => [StatusCaso::Judicializado, true];
        yield 'encerrado NÃO cobra' => [StatusCaso::Encerrado, false];
    }

    #[TestDox('cobraveis() devolve os valores que as consultas usam no IN, e o encerrado fica fora')]
    public function testCobraveisDevolveOsValoresParaOIn(): void
    {
        // É desta lista que `CasoCobrancaRepository` monta o `IN (:cobraveis)`. São VALORES do enum
        // (string), não instâncias — o DQL compara contra a coluna.
        self::assertSame(['ativo', 'judicializado'], StatusCaso::cobraveis());
    }

    #[TestDox('🔒 cobraveis() deriva de ehCobravel() — não é uma segunda lista escrita à mão')]
    public function testCobraveisDerivaDeEhCobravel(): void
    {
        // O ponto da frente: UMA definição de "caso que recebe movimento". Se alguém trocar
        // `cobraveis()` por um literal, esta asserção continua verde — mas ela trava a outra metade,
        // que é as duas ficarem inconsistentes entre si.
        $derivado = array_values(array_map(
            static fn (StatusCaso $s): string => $s->value,
            array_filter(StatusCaso::cases(), static fn (StatusCaso $s): bool => $s->ehCobravel()),
        ));

        self::assertSame($derivado, StatusCaso::cobraveis());
        self::assertNotContains(StatusCaso::Encerrado->value, StatusCaso::cobraveis());
    }

    #[TestDox('Todo status do enum tem decisão explícita de cobrabilidade (sem fail-open)')]
    public function testTodoStatusTemDecisaoExplicita(): void
    {
        // `ehCobravel()` é um `match` exaustivo de propósito. Com `!== Encerrado`, um status novo
        // entraria como cobrável por OMISSÃO — num método que decide se dívida é gravada. Este teste
        // é o que faz o `match` estourar em vez de passar batido quando o enum crescer.
        foreach (StatusCaso::cases() as $status) {
            $status->ehCobravel();
        }

        self::assertCount(3, StatusCaso::cases(), 'status novo no enum exige decidir a cobrabilidade dele aqui');
    }
}
