<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Os nomes das entradas de um .zip da aba Documentos (D5): SANITIZADOS e ÚNICOS por diretório.
 *
 * O nome de um documento (`nome_original`) e o de uma subpasta vêm do usuário — e do Drive. Num
 * .zip, um nome vira caminho na máquina de quem extrai: `../../.bashrc` ou `C:\x` são o ataque
 * clássico ("zip slip"), e `relatório.pdf` duas vezes na mesma pasta faz o segundo sobrescrever o
 * primeiro em silêncio. Então:
 *
 *  - {@see sanitizar()} tira caractere de controle, troca `/` e `\` por `_`, reduz `..` a `.` e
 *    apara ponto/espaço nas bordas (`.` e `..` sozinhos viram "sem nome"). O `LEIA-ME.txt` da raiz
 *    é reservado ANTES dos documentos: um arquivo do usuário com esse nome vira `LEIA-ME (2).txt`,
 *    nunca o contrário;
 *  - {@see reservar()} garante unicidade sem distinguir caixa (Windows extrai sem distinguir):
 *    `nome (2).ext`, `nome (3).ext`… Subpasta não tem extensão: `Pasta (2)`.
 *
 * Serviço puro, de UMA montagem: um objeto por .zip.
 */
final class NomesDeEntradaDoZip
{
    public const SEM_NOME = 'sem nome';

    /** @var array<string, array<string, true>> diretório (caminho já sanitizado, '' = raiz) => nomes tomados, em minúsculas */
    private array $tomados = [];

    public static function sanitizar(string $nome): string
    {
        $limpo = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $nome);
        $limpo = str_replace(['/', '\\'], '_', $limpo);
        $limpo = (string) preg_replace('/\.{2,}/', '.', $limpo);
        $limpo = trim($limpo, " .");

        return $limpo === '' ? self::SEM_NOME : $limpo;
    }

    /**
     * Um nome único dentro de `$diretorio`, derivado de `$nome` — e já tomado para as próximas.
     *
     * @param string $diretorio caminho do diretório no .zip ('' = raiz), já montado por esta classe
     * @param bool   $ehPasta   subpasta: o sufixo vai no fim (`Pasta (2)`), sem procurar extensão
     */
    public function reservar(string $diretorio, string $nome, bool $ehPasta = false): string
    {
        $base              = self::sanitizar($nome);
        [$tronco, $extensao] = $ehPasta ? [$base, ''] : self::separarExtensao($base);

        $candidato = $base;
        $n         = 2;
        while (isset($this->tomados[$diretorio][mb_strtolower($candidato)])) {
            $candidato = sprintf('%s (%d)%s', $tronco, $n++, $extensao);
        }

        $this->tomados[$diretorio][mb_strtolower($candidato)] = true;

        return $candidato;
    }

    /**
     * Tronco e extensão (COM o ponto; '' quando não há). Só conta como extensão um sufixo curto e
     * alfanumérico: em `versão final.rev 2` o `.rev 2` fica no tronco, e `.htaccess` não tem tronco
     * para separar.
     *
     * @return array{string, string}
     */
    public static function separarExtensao(string $nome): array
    {
        $posicao = strrpos($nome, '.');
        if ($posicao === false || $posicao === 0) {
            return [$nome, ''];
        }

        $extensao = substr($nome, $posicao);
        if (preg_match('/^\.[A-Za-z0-9]{1,16}$/', $extensao) !== 1) {
            return [$nome, ''];
        }

        return [substr($nome, 0, $posicao), $extensao];
    }
}
