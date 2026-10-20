<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Chips de categoria na área vazia da aba Documentos do objeto (desenho Objeto 1A). O desenho mostra
 * 4 chips; a fonte é o enum `CategoriaDocumentoCobranca`, que tem 6, e valem os do enum, na ordem dele.
 * Arranjo testado por FILHO DIRETO: os chips moram dentro da área vazia do gerenciador, logo abaixo da
 * dica, e não "em algum lugar da página".
 */
#[CoversClass(ObjetoController::class)]
final class DocumentosObjetoChipsCategoriaTest extends CobrancaWebTestCase
{
    #[TestDox('A área vazia dos documentos traz um chip por categoria do enum, depois da dica')]
    public function testAreaVaziaTrazOsChipsDoEnum(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()?->getId());

        self::assertResponseIsSuccessful();
        $vazio = $crawler->filter('#tab-documentos #fileManager #fmArquivosVazio.fm-vazio');
        self::assertCount(1, $vazio);

        $filhos = $vazio->children()->each(static fn ($no): string => (string) $no->attr('class'));
        self::assertSame(['fm-vazio-icone', 'fm-vazio-titulo', 'fm-vazio-dica', 'fm-vazio-categorias'], $filhos);

        $chips = $crawler->filter('#fmArquivosVazio > .fm-vazio-categorias > span.fm-vazio-categoria')
            ->each(static fn ($no): string => trim($no->text()));
        $esperado = array_map(
            static fn (CategoriaDocumentoCobranca $c): string => $c->rotulo(),
            CategoriaDocumentoCobranca::cases(),
        );
        self::assertSame($esperado, $chips);
        self::assertContains('Boleto', $chips);
        self::assertContains('Comprovante', $chips);
    }
}
