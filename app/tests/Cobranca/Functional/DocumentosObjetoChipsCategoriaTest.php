<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Chips de exemplo na área vazia da aba Documentos do objeto (desenho Objeto 1A, L424-429). O desenho
 * manda: exatamente os 4 chips, com o texto e a ordem dele (dica visual, não o enum de categoria).
 * Arranjo testado por FILHO DIRETO: os chips moram dentro da área vazia do gerenciador, logo abaixo da
 * dica, e não "em algum lugar da página".
 */
#[CoversClass(ObjetoController::class)]
final class DocumentosObjetoChipsCategoriaTest extends CobrancaWebTestCase
{
    #[TestDox('A área vazia dos documentos traz os 4 chips do desenho, na ordem dele, depois da dica')]
    public function testAreaVaziaTrazOsChipsDoDesenho(): void
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
        self::assertSame(['Boletos', 'Notificações', 'Acordos assinados', 'Comprovantes'], $chips);
    }
}
