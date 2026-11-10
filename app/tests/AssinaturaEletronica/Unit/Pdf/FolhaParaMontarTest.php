<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\FolhaParaMontar;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Pdf\SemPaginasDepoisDoConteudo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Só a folha branca de rascunho sai sem manifesto (`if (!(d.limpa && !d.cod))`, dc:417): nas outras, passar
 * {@see SemPaginasDepoisDoConteudo} é erro de quem monta a folha, e o construtor recusa.
 */
#[CoversClass(FolhaParaMontar::class)]
final class FolhaParaMontarTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function folhasComManifesto(): iterable
    {
        yield 'folha final comum' => [[]];
        yield 'folha branca com código' => [['folhaBranca' => true]];
        yield 'rascunho comum' => [['modo' => ModoDaFolha::Rascunho, 'codigo' => null]];
    }

    /** @param array<string, mixed> $mudancas */
    #[DataProvider('folhasComManifesto')]
    #[TestDox('Folha que leva manifesto recusa o SemPaginasDepoisDoConteudo')]
    public function testRecusaSemManifesto(array $mudancas): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Só a folha branca de rascunho sai sem o manifesto.');

        FolhaDeTeste::folha(mudancas: [...$mudancas, 'paginasDepois' => new SemPaginasDepoisDoConteudo()]);
    }

    #[TestDox('Folha branca de rascunho aceita o SemPaginasDepoisDoConteudo')]
    public function testFolhaBrancaDeRascunhoSemManifesto(): void
    {
        $folha = FolhaDeTeste::folha(mudancas: [
            'folhaBranca' => true,
            'modo' => ModoDaFolha::Rascunho,
            'codigo' => null,
            'paginasDepois' => new SemPaginasDepoisDoConteudo(),
        ]);

        self::assertSame(0, $folha->paginasDepois->quantas());
    }
}
