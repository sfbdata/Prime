<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Exception\CaminhosDoLoteInvalidosException;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\NeutralizadorDeConteudo;

/**
 * A árvore de nomes do "Importar pastas em lote" (spec importar-pastas-em-lote, etapa 2), numerada para o modelo.
 *
 * Recebe os caminhos relativos dos ARQUIVOS (o `webkitRelativePath` do navegador, "Clientes/MARIA/rg.pdf") e deriva
 * as pastas de todos os prefixos ("Clientes", "Clientes/MARIA"). Os nós saem em ordem estável, a pasta antes do que
 * ela contém, numerados a partir de 1. Só os primeiros {@see TETO_DE_LINHAS} vão ao modelo; o resto marca `truncado`.
 *
 * O texto de cada linha é o caminho cortado em {@see TAMANHO_DA_LINHA} caracteres, mascarado (CPF, CNPJ, telefone e
 * e-mail no nome do arquivo) e neutralizado (o nome do arquivo é dado, nunca instrução). O caminho ORIGINAL de cada
 * número fica só aqui, em memória, para devolver à tela: nada disto é gravado nem logado.
 */
final class ArvoreDoLote
{
    public const TETO_DE_LINHAS = 2000;
    public const TETO_DE_CAMINHOS = 50000;
    public const TAMANHO_DA_LINHA = 200;

    /**
     * @param array<int, array{caminho: string, pasta: bool}> $nos      número (1..N) => caminho original e se é pasta
     * @param list<string>                                     $linhas   o texto enviado, uma linha por nó
     */
    private function __construct(
        public readonly array $nos,
        public readonly array $linhas,
        public readonly bool $truncado,
        public readonly int $totalDeCaminhos,
        public readonly int $totalDePastas,
    ) {
    }

    /**
     * @param array<mixed> $caminhos
     *
     * @throws CaminhosDoLoteInvalidosException lista vazia, grande demais, ou caminho vazio/não texto
     */
    public static function montar(array $caminhos, MascaradorDeDadosPessoais $mascarador): self
    {
        if ($caminhos === [] || count($caminhos) > self::TETO_DE_CAMINHOS) {
            throw new CaminhosDoLoteInvalidosException();
        }

        [$partesPorChave, $ehPasta] = self::nosDosCaminhos($caminhos);
        uasort($partesPorChave, [self::class, 'compararPartes']);

        $nos = [];
        $linhas = [];
        $numero = 0;
        foreach (array_keys($partesPorChave) as $chave) {
            $chave = (string) $chave;
            if (++$numero > self::TETO_DE_LINHAS) {
                break;
            }
            $nos[$numero] = ['caminho' => $chave, 'pasta' => $ehPasta[$chave]];
            $linhas[] = $numero . ' | ' . ($ehPasta[$chave] ? 'PASTA' : 'ARQUIVO') . ' | ' . self::textoDaLinha($chave, $ehPasta[$chave], $mascarador);
        }

        return new self($nos, $linhas, count($partesPorChave) > self::TETO_DE_LINHAS, count($caminhos), count(array_filter($ehPasta)));
    }

    /**
     * Cada caminho e todos os seus prefixos, sem repetir: chave => segmentos, e chave => é pasta.
     *
     * @param array<mixed> $caminhos
     *
     * @return array{0: array<string, list<string>>, 1: array<string, bool>}
     */
    private static function nosDosCaminhos(array $caminhos): array
    {
        $partesPorChave = [];
        $ehPasta = [];
        foreach ($caminhos as $caminho) {
            if (!is_string($caminho)) {
                throw new CaminhosDoLoteInvalidosException();
            }
            $partes = array_values(array_filter(explode('/', $caminho), static fn (string $p): bool => trim($p) !== ''));
            if ($partes === []) {
                throw new CaminhosDoLoteInvalidosException();
            }
            $ultima = count($partes) - 1;
            for ($i = 0; $i <= $ultima; ++$i) {
                $prefixo = array_slice($partes, 0, $i + 1);
                $chave = implode('/', $prefixo);
                $partesPorChave[$chave] = $prefixo;
                // Pasta vence: um nome que é prefixo de outro caminho é pasta, mesmo se veio também como arquivo.
                $ehPasta[$chave] = ($ehPasta[$chave] ?? false) || $i < $ultima;
            }
        }

        return [$partesPorChave, $ehPasta];
    }

    /** O que vai dentro de `<arvore_do_lote>`, uma linha por nó. */
    public function texto(): string
    {
        return implode("\n", $this->linhas);
    }

    public function enviados(): int
    {
        return count($this->linhas);
    }

    /** @return array{caminho: string, pasta: bool}|null */
    public function no(int $numero): ?array
    {
        return $this->nos[$numero] ?? null;
    }

    /**
     * Segmento a segmento; quando um é prefixo do outro, o mais curto (a pasta) vem antes do seu conteúdo.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function compararPartes(array $a, array $b): int
    {
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; ++$i) {
            $comparacao = strcmp($a[$i], $b[$i]);
            if ($comparacao !== 0) {
                return $comparacao;
            }
        }

        return count($a) <=> count($b);
    }

    private static function textoDaLinha(string $caminho, bool $pasta, MascaradorDeDadosPessoais $mascarador): string
    {
        $texto = mb_substr($caminho . ($pasta ? '/' : ''), 0, self::TAMANHO_DA_LINHA);

        return NeutralizadorDeConteudo::neutralizar($mascarador->mascarar($texto));
    }
}
