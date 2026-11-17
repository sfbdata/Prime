<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\FerramentaDoConector;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * As ferramentas do conector de I.A. pelo código (spec mcp-remoto-oauth 6.2 regras 8 e 10, 6.3 item 1, 12.14): toda
 * classe que implementa {@see FerramentaDoConector} tem nome do catálogo fechado, um só por classe, o mesmo `title` de
 * {@see CatalogoFechadoDoConector::TITULOS} e a entrada fechada (objeto, `additionalProperties: false`, sem `oneOf`).
 */
#[CoversNothing]
final class FerramentasDoConectorArquiteturaTest extends TestCase
{
    private const CONECTOR = __DIR__ . '/../../src/Conector';

    #[TestDox('toda ferramenta do conector está no catálogo, uma vez só, com o título do catálogo e a entrada fechada')]
    public function testFerramentasPeloCatalogo(): void
    {
        $vistas = [];
        foreach (self::ferramentas() as $classe) {
            $ferramenta = $classe->newInstanceWithoutConstructor();
            \assert($ferramenta instanceof FerramentaDoConector);
            $nome = $ferramenta->nome();

            self::assertTrue(CatalogoFechadoDoConector::existe($nome), $classe->getName() . ': ' . $nome . ' fora da lista fechada (6.3, item 1)');
            self::assertArrayNotHasKey($nome, $vistas, $nome . ' em duas classes');
            self::assertSame(CatalogoFechadoDoConector::TITULOS[$nome], $ferramenta->titulo(), $nome . ': titulo() diferente do catálogo');

            $esquema = $ferramenta->esquemaDeEntrada();
            self::assertSame('object', $esquema['type'] ?? null, $nome);
            self::assertFalse($esquema['additionalProperties'] ?? true, $nome . ': entrada sem additionalProperties false (6.2, regra 10)');
            self::assertStringNotContainsString('"oneOf"', (string) json_encode($esquema), $nome);
            $vistas[$nome] = true;
        }

        self::assertArrayHasKey('quem_sou_eu', $vistas, 'a quem_sou_eu (PR 5) sumiu');
    }

    /** @return list<\ReflectionClass<object>> */
    private static function ferramentas(): array
    {
        $classes = [];
        foreach ((new Finder())->files()->in(self::CONECTOR)->name('*.php') as $arquivo) {
            $classe = 'App\\Conector\\' . str_replace(['/', '.php'], ['\\', ''], $arquivo->getRelativePathname());
            if (!class_exists($classe)) {
                continue;
            }
            $reflexao = new \ReflectionClass($classe);
            if ($reflexao->isInstantiable() && $reflexao->implementsInterface(FerramentaDoConector::class)) {
                $classes[] = $reflexao;
            }
        }

        return $classes;
    }
}
