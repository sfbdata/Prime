<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\CatalogoDeDocumentos;
use App\Pasta\Service\OrganizacaoSugeridaPorFase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Organização sugerida para esta fase" (DOC-80) — a lista de bj-docsug.js L109-112, literal.
 */
#[CoversClass(OrganizacaoSugeridaPorFase::class)]
final class OrganizacaoSugeridaPorFaseTest extends TestCase
{
    #[TestDox('cumprimento de sentença: base + prazos, cálculos, cumprimento e pagamentos + encerramento (o exemplo do desenho)')]
    public function testCumprimento(): void
    {
        self::assertSame(
            [
                '01 Processo', '02 Petições', '03 Decisões', '04 Documentos das partes', '05 Provas',
                '06 Prazos', '07 Cálculos', '08 Cumprimento de sentença', '09 Pagamentos',
                'Encerramento',
            ],
            OrganizacaoSugeridaPorFase::para('cumprimento'),
        );
    }

    #[TestDox('fase inicial não tem extra próprio: recebe "06 Prazos", como o `|| [\'06 Prazos\']` do JS')]
    public function testFaseSemExtraRecebePrazos(): void
    {
        self::assertSame(
            ['01 Processo', '02 Petições', '03 Decisões', '04 Documentos das partes', '05 Provas', '06 Prazos', 'Encerramento'],
            OrganizacaoSugeridaPorFase::para('inicial'),
        );
        self::assertSame(OrganizacaoSugeridaPorFase::para('inicial'), OrganizacaoSugeridaPorFase::para('fase-que-nao-existe'));
    }

    #[TestDox('perícia e recurso têm os extras do desenho')]
    public function testExtras(): void
    {
        self::assertContains('06 Perícia', OrganizacaoSugeridaPorFase::para('pericia'));
        self::assertSame(['06 Prazos', '07 Recursos'], \array_slice(OrganizacaoSugeridaPorFase::para('recurso'), 5, 2));
    }

    #[TestDox('sem fase (pasta sem catálogo) não há sugestão nenhuma — lista vazia, não a lista genérica')]
    public function testSemFase(): void
    {
        self::assertSame([], OrganizacaoSugeridaPorFase::para(null));
        self::assertSame([], OrganizacaoSugeridaPorFase::para(''));
        self::assertSame([], OrganizacaoSugeridaPorFase::para('   '));
    }

    #[TestDox('porFase cobre todas as fases do catálogo, e cada lista começa pela base e termina em Encerramento')]
    public function testPorFase(): void
    {
        $porFase = OrganizacaoSugeridaPorFase::porFase();

        self::assertSame(array_keys(CatalogoDeDocumentos::NOMES_DAS_FASES), array_keys($porFase));
        foreach ($porFase as $fase => $pastas) {
            self::assertSame(OrganizacaoSugeridaPorFase::BASE, \array_slice($pastas, 0, 5), $fase);
            self::assertSame('Encerramento', $pastas[\count($pastas) - 1], $fase);
        }
    }
}
