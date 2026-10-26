<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * O catálogo pt_BR é um arquivo por bloco, para sessões em paralelo não conflitarem no mesmo arquivo.
 * O domínio continua "messages+intl-icu": nenhum trans() muda, o Symfony funde as pastas.
 */
final class CatalogoPorBlocoTest extends TestCase
{
    #[TestDox('Não volta o arquivo único na raiz de translations')]
    public function testSemArquivoUnico(): void
    {
        self::assertFileDoesNotExist(CatalogoPtBr::raiz() . '/' . CatalogoPtBr::NOME_DO_ARQUIVO);
        self::assertSame([], glob(CatalogoPtBr::raiz() . '/messages+intl-icu.*') ?: []);
    }

    #[TestDox('Cada arquivo tem um só bloco de topo, com o nome da própria pasta')]
    public function testPastaIgualAoBloco(): void
    {
        $arquivos = CatalogoPtBr::arquivos();
        self::assertNotEmpty($arquivos);

        foreach ($arquivos as $pasta => $arquivo) {
            $conteudo = Yaml::parseFile($arquivo);
            self::assertIsArray($conteudo, $pasta);
            self::assertSame([$pasta], array_keys($conteudo), "translations/$pasta deve ter só o bloco '$pasta' (bloco repetido em dois arquivos se fundiria em silêncio)");
        }
    }

    #[TestDox('Só há pastas de bloco com o arquivo do domínio, sem arquivo solto')]
    public function testSemPastaSemArquivo(): void
    {
        $pastas = array_map('basename', glob(CatalogoPtBr::raiz() . '/*', GLOB_ONLYDIR) ?: []);
        sort($pastas);

        self::assertSame(array_keys(CatalogoPtBr::arquivos()), $pastas);
    }

    #[TestDox('O catálogo fundido tem todos os blocos')]
    public function testFundido(): void
    {
        self::assertSame(array_keys(CatalogoPtBr::arquivos()), array_keys(CatalogoPtBr::carregar()));
    }
}
