<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\FerramentaDoConector;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\DataFixtures\PermissionFixture;
use App\Master\Service\CatalogoDeRecursos;
use App\Pasta\Entity\AbaDaPasta;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * As ferramentas do conector de I.A. pelo código (spec mcp-remoto-oauth 6.2 regras 8 e 10, 6.3 item 1, 12.14): toda
 * classe que implementa {@see FerramentaDoConector} tem nome do catálogo fechado, um só por classe, o mesmo `title` de
 * {@see CatalogoFechadoDoConector::TITULOS} e a entrada fechada (objeto, `additionalProperties: false`, sem `oneOf`).
 *
 * E a tabela de aba e chave da 6.6 ({@see TabelaDeAbaEChave}, 12.9): uma entrada por ferramenta do catálogo; toda ação
 * que o esquema de uma ferramenta registrada declara (`acao.enum`, `partes.items.enum` ou o campo da tabela) tem linha;
 * cada módulo existe no catálogo de permissões, cada aba é uma {@see AbaDaPasta} e cada chave está no catálogo do
 * Master. A guarda e o segredo das listas não leem sessão nem mexem no filtro do Doctrine.
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

    #[TestDox('6.6: a tabela de aba e chave tem uma entrada por ferramenta do catálogo, na ordem dele, e nenhuma vazia')]
    public function testTabelaCobreOCatalogo(): void
    {
        self::assertSame(CatalogoFechadoDoConector::nomes(), array_keys(TabelaDeAbaEChave::LINHAS));
        foreach (TabelaDeAbaEChave::LINHAS as $ferramenta => $linhas) {
            self::assertNotSame([], $linhas, $ferramenta . ' sem linha');
        }
    }

    #[TestDox('6.6: toda ação do esquema de cada ferramenta registrada tem linha na tabela; sem campo de ação, a linha única')]
    public function testAcoesDoEsquemaNaTabela(): void
    {
        foreach (self::ferramentas() as $classe) {
            $ferramenta = $classe->newInstanceWithoutConstructor();
            \assert($ferramenta instanceof FerramentaDoConector);
            $nome = $ferramenta->nome();
            $acoes = self::acoesDoEsquema($ferramenta->esquemaDeEntrada(), TabelaDeAbaEChave::campoDasAcoes($nome));

            if ($acoes === null) {
                self::assertSame([TabelaDeAbaEChave::UNICA], TabelaDeAbaEChave::acoes($nome), $nome . ': sem campo de ação no esquema, a tabela tem só a linha única');

                continue;
            }
            self::assertNotSame([], $acoes, $nome . ': lista de ações vazia no esquema');
            foreach ($acoes as $acao) {
                self::assertIsString($acao, $nome);
                self::assertNotNull(TabelaDeAbaEChave::linha($nome, $acao), sprintf('%s: a ação "%s" do esquema não tem linha na tabela da 6.6', $nome, $acao));
            }
        }
    }

    #[TestDox('6.6: cada módulo da tabela existe no catálogo de permissões, cada aba é AbaDaPasta e cada chave está no catálogo do Master')]
    public function testValoresDaTabela(): void
    {
        $codigos = array_column((new \ReflectionClassConstant(PermissionFixture::class, 'PERMISSIONS'))->getValue(), 'code');
        foreach (TabelaDeAbaEChave::LINHAS as $ferramenta => $linhas) {
            foreach (array_keys($linhas) as $acao) {
                $linha = TabelaDeAbaEChave::linha($ferramenta, $acao);
                self::assertNotNull($linha);
                $onde = $ferramenta . ': ' . $acao;
                foreach ($linha->modulos as $modulo) {
                    self::assertContains('modules.' . $modulo . '.view', $codigos, $onde . ': módulo fora da PermissionFixture');
                }
                foreach ($linha->abas as $aba) {
                    self::assertInstanceOf(AbaDaPasta::class, $aba, $onde);
                }
                foreach ($linha->chaves as $chave) {
                    self::assertTrue(CatalogoDeRecursos::existe($chave), $onde . ': chave ' . $chave . ' fora do catálogo do Master');
                }
                self::assertFalse($linha->chavesSoComPasta && $linha->chaves === [], $onde . ': chave só com pasta sem chave');
            }
        }
    }

    #[TestDox('a guarda da ação e o segredo das listas não leem sessão, Request nem TenantContext, e não mexem no filtro do Doctrine')]
    public function testGuardaSemSessao(): void
    {
        foreach (['Service/GuardaDaAcaoDoConector.php', 'Service/SegredoNasListas.php', 'Ferramenta/TabelaDeAbaEChave.php'] as $relativo) {
            $codigo = (string) file_get_contents(self::CONECTOR . '/' . $relativo);
            foreach (['Security', 'RequestStack', 'TenantContext', 'getFilters(', 'SemFiltroDoEscritorio'] as $proibido) {
                self::assertStringNotContainsString($proibido, self::semComentarios($codigo), $relativo . ' usa ' . $proibido);
            }
        }
    }

    /**
     * As ações do campo dado no esquema: `enum` do campo ou, se for lista, `items.enum`. Null quando o campo não existe.
     *
     * @param array<string, mixed> $esquema
     *
     * @return list<mixed>|null
     */
    private static function acoesDoEsquema(array $esquema, string $campo): ?array
    {
        $propriedade = $esquema['properties'][$campo] ?? null;
        if (!\is_array($propriedade)) {
            return null;
        }
        $enum = $propriedade['enum'] ?? $propriedade['items']['enum'] ?? null;
        self::assertIsArray($enum, 'o campo ' . $campo . ' do esquema precisa de enum (acao.enum ou partes.items.enum)');

        return array_values($enum);
    }

    private static function semComentarios(string $codigo): string
    {
        $limpo = '';
        foreach (token_get_all($codigo) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= \is_array($token) ? $token[1] : $token;
        }

        return $limpo;
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
