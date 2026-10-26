<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * O catálogo pt_BR fica em um arquivo por bloco (translations/<bloco>/messages+intl-icu.pt_BR.yaml).
 * Os testes que liam o arquivo único leem por aqui: o resultado é o mesmo array que o arquivo único dava.
 */
final class CatalogoPtBr
{
    public const NOME_DO_ARQUIVO = 'messages+intl-icu.pt_BR.yaml';

    /** @return array<string, mixed> os blocos de topo, já fundidos */
    public static function carregar(): array
    {
        $catalogo = [];
        foreach (self::arquivos() as $bloco => $arquivo) {
            $conteudo = Yaml::parseFile($arquivo);
            if (!\is_array($conteudo)) {
                continue;
            }
            foreach ($conteudo as $chave => $valor) {
                $catalogo[$chave] = $valor;
            }
        }

        return $catalogo;
    }

    /** @return array<string, string> pasta do bloco => caminho do arquivo, em ordem alfabética */
    public static function arquivos(): array
    {
        $arquivos = [];
        foreach (glob(self::raiz() . '/*/' . self::NOME_DO_ARQUIVO) ?: [] as $arquivo) {
            $arquivos[basename(\dirname($arquivo))] = $arquivo;
        }
        ksort($arquivos);

        return $arquivos;
    }

    /** Para teste unitário que monta o próprio Translator com o catálogo inteiro. */
    public static function registrarEm(Translator $tradutor): void
    {
        foreach (self::arquivos() as $arquivo) {
            $tradutor->addResource('yaml', $arquivo, 'pt_BR', 'messages+intl-icu');
        }
    }

    public static function raiz(): string
    {
        return \dirname(__DIR__, 2) . '/translations';
    }
}
