<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Produto\DTO\ClassificacaoDaRota;
use App\Produto\Enum\Produto;
use App\Produto\Service\CatalogoDeProdutos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Toda rota do sistema tem dono no CatalogoDeProdutos (spec multiproduto-contratacao-e-voto, §3).
 *
 * A guarda do produto (PR 6) fecha a rota que não casa com nada (cai no Expediente). Este teste percorre o router
 * inteiro para que isso nunca aconteça por esquecimento: rota nova sem classificação quebra aqui, com o nome dela.
 * Também confere que as listas do catálogo não citam rota que não existe e que "pública" no catálogo é pública
 * de verdade no security.yaml (e o contrário: caminho aberto sem login nunca exige produto).
 */
#[CoversClass(CatalogoDeProdutos::class)]
final class ProdutoDaRotaArquiteturaTest extends KernelTestCase
{
    #[TestDox('Toda rota real tem classificação explícita (nada cai no padrão por esquecimento)')]
    public function testTodaRotaTemClassificacaoExplicita(): void
    {
        $semMapa = [];
        foreach (array_keys($this->rotas()) as $nome) {
            if (!CatalogoDeProdutos::classificar($nome)->explicita) {
                $semMapa[] = $nome;
            }
        }

        self::assertSame([], $semMapa, 'Rotas sem produto no CatalogoDeProdutos: classifique em ROTAS, NUCLEO, PLATAFORMA ou PUBLICAS.');
    }

    #[TestDox('Os nomes exatos do catálogo e as rotas iniciais existem no router')]
    public function testNomesExatosExistem(): void
    {
        $rotas = $this->rotas();
        $exatos = [
            ...CatalogoDeProdutos::publicasExatas(),
            ...array_keys(CatalogoDeProdutos::ROTAS_EXATAS),
            ...array_map(static fn (Produto $p): string => CatalogoDeProdutos::rotaInicial($p), Produto::cases()),
        ];

        $inexistentes = array_values(array_filter($exatos, static fn (string $n): bool => !isset($rotas[$n])));

        self::assertSame([], $inexistentes);
    }

    #[TestDox('A rota inicial de cada produto abre com o próprio produto')]
    public function testRotaInicialAbreComOProduto(): void
    {
        foreach (Produto::ferramentas() as $produto) {
            $c = CatalogoDeProdutos::classificar(CatalogoDeProdutos::rotaInicial($produto));

            self::assertContains($produto, $c->produtos, $produto->value);
        }
    }

    #[TestDox('Todo prefixo do catálogo casa com pelo menos uma rota real')]
    public function testPrefixosSemRotaMorta(): void
    {
        $nomes = array_keys($this->rotas());
        $prefixos = [
            ...array_keys(CatalogoDeProdutos::ROTAS),
            ...CatalogoDeProdutos::NUCLEO,
            ...CatalogoDeProdutos::PLATAFORMA,
            ...CatalogoDeProdutos::PUBLICAS_PREFIXOS,
        ];
        // Só existem em dev (WebProfilerBundle e a prévia das páginas de erro).
        $soEmDev = ['_profiler', '_wdt', '_preview_error'];

        $mortos = [];
        foreach (array_diff($prefixos, $soEmDev) as $prefixo) {
            $casa = array_filter($nomes, static fn (string $n): bool => str_starts_with($n, $prefixo));
            if ([] === $casa) {
                $mortos[] = $prefixo;
            }
        }

        self::assertSame([], $mortos);
    }

    #[TestDox('Rota pública no catálogo tem caminho PUBLIC_ACCESS no security.yaml')]
    public function testPublicaNoCatalogoEhPublicaNoFirewall(): void
    {
        $abertos = $this->caminhosPublicos();
        $fechadas = [];
        foreach ($this->rotas() as $nome => $rota) {
            if (ClassificacaoDaRota::PUBLICA !== CatalogoDeProdutos::classificar($nome)->tipo) {
                continue;
            }
            if (!$this->casaAlgum($this->caminhoDeExemplo($rota), $abertos)) {
                $fechadas[] = $nome.' '.$rota->getPath();
            }
        }

        self::assertSame([], $fechadas);
    }

    #[TestDox('Caminho aberto sem login no security.yaml nunca exige produto')]
    public function testCaminhoAbertoNuncaExigeProduto(): void
    {
        $abertos = $this->caminhosPublicos();
        $exigem = [];
        foreach ($this->rotas() as $nome => $rota) {
            if (!$this->casaAlgum($this->caminhoDeExemplo($rota), $abertos)) {
                continue;
            }
            if (CatalogoDeProdutos::classificar($nome)->exigeProduto()) {
                $exigem[] = $nome.' '.$rota->getPath();
            }
        }

        self::assertSame([], $exigem, 'Caminho PUBLIC_ACCESS com rota de produto: o visitante sem escritório levaria 404. Ponha em PUBLICAS.');
    }

    /** @return array<string, Route> */
    private function rotas(): array
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        return $router->getRouteCollection()->all();
    }

    /** @return list<string> as expressões de caminho com PUBLIC_ACCESS no access_control. */
    private function caminhosPublicos(): array
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($dir);
        $config = Yaml::parseFile($dir.'/config/packages/security.yaml');
        $regras = $config['security']['access_control'] ?? [];

        $caminhos = [];
        foreach ($regras as $regra) {
            $papeis = (array) ($regra['roles'] ?? []);
            if (in_array('PUBLIC_ACCESS', $papeis, true) && isset($regra['path'])) {
                $caminhos[] = (string) $regra['path'];
            }
        }

        return $caminhos;
    }

    /** Troca cada `{parametro}` por um valor de exemplo para casar com as expressões do access_control. */
    private function caminhoDeExemplo(Route $rota): string
    {
        return (string) preg_replace('/\{[^}]+\}/', 'abc123', $rota->getPath());
    }

    /** @param list<string> $expressoes */
    private function casaAlgum(string $caminho, array $expressoes): bool
    {
        foreach ($expressoes as $expressao) {
            if (1 === preg_match('#'.str_replace('#', '\#', $expressao).'#', $caminho)) {
                return true;
            }
        }

        return false;
    }
}
