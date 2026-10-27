<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Idioma do sistema (spec docs/specs/idioma-do-sistema.md, §4 "Guardas de teste"): o catálogo passa no
 * `lint:translations`, e toda chave que um template (`'chave'|trans`) ou um JS (`BJ_T.t('chave')`) usa existe no
 * português, a fonte. Chave sem texto apareceria crua na tela.
 */
#[CoversNothing]
final class CatalogoDeTraducaoTest extends KernelTestCase
{
    private const APP = __DIR__ . '/../../..';

    #[TestDox('O catálogo passa no lint:translations')]
    public function testLint(): void
    {
        self::bootKernel();
        $comando = new CommandTester((new Application(self::$kernel))->find('lint:translations'));

        // Sem enabled_locales o comando não acha arquivo nenhum e sai com 0: o locale vai explícito.
        self::assertSame(0, $comando->execute(['--locale' => ['pt_BR']]), $comando->getDisplay());
        self::assertStringContainsString('messages', $comando->getDisplay(), 'o lint leu o catálogo messages');
    }

    #[TestDox('Toda chave usada nos templates e no JS tem texto em português')]
    public function testChavesUsadasExistemEmPortugues(): void
    {
        self::bootKernel();
        $tradutor = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $tradutor);
        $catalogo = $tradutor->getCatalogue('pt_BR');

        $faltam = array_values(array_filter(self::chavesUsadas(), static fn (string $chave): bool => !$catalogo->has($chave)));

        self::assertSame([], $faltam, 'chave usada sem texto em translations/<bloco>/messages+intl-icu.pt_BR.yaml');
    }

    /** @return list<string> */
    private static function chavesUsadas(): array
    {
        $chaves = [];
        foreach ((new Finder())->files()->in(self::APP . '/templates')->name('*.twig') as $arquivo) {
            preg_match_all("/'([a-z][a-z0-9_]*(?:\\.[a-z0-9_]+)+)'\\s*\\|\\s*trans\\b/", $arquivo->getContents(), $m);
            array_push($chaves, ...$m[1]);
        }
        foreach ((new Finder())->files()->in(self::APP . '/public/js')->name('*.js') as $arquivo) {
            preg_match_all("/BJ_T\\.t\\(\\s*'([a-z][a-z0-9_.]+)'/", $arquivo->getContents(), $m);
            array_push($chaves, ...$m[1]);
        }

        return array_values(array_unique($chaves));
    }
}
