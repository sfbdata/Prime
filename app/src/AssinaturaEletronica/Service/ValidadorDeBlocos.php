<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\LeitorDeCampos;
use App\AssinaturaEletronica\Exception\AcaoRecusada;

/**
 * Confere a estrutura dos blocos que o navegador montou (contrato da etapa 1, seção 3.3) e devolve só os campos
 * conhecidos de cada um. Não há motor de modelos aqui: o conteúdo é do usuário e sai sempre escapado na tela.
 */
final class ValidadorDeBlocos
{
    public const BLOCOS_MAXIMO = 2000;
    public const TEXTO_MAXIMO = 20000;
    public const PAPEL_MAXIMO = 120;
    /** Sem contagem de páginas (PDF que o contador não leu), vale este teto. */
    public const PAGINAS_SEM_CONTAGEM = 2000;
    public const TIPOS_DE_TEXTO = ['t', 's', 'h', 'l', 'p', 'd', 'n'];

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $blocos     como vieram no `Rascunho`
     * @param bool         $temArquivo se o documento terá PDF guardado depois de salvar
     * @param int|null     $paginas    páginas desse PDF, nulo quando o contador não leu
     *
     * @return list<array<string, int|string>>
     *
     * @throws AcaoRecusada
     */
    public static function normalizar(array $blocos, bool $temArquivo, ?int $paginas): array
    {
        if (!array_is_list($blocos)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos');
        }

        if (\count($blocos) > self::BLOCOS_MAXIMO) {
            throw new AcaoRecusada('assinatura_eletronica.erro.blocos_demais');
        }

        $normalizados = [];
        foreach ($blocos as $indice => $bloco) {
            $normalizados[] = self::bloco($bloco, $indice + 1, $temArquivo, $paginas);
        }

        return $normalizados;
    }

    /**
     * @return array<string, int|string>
     *
     * @throws AcaoRecusada
     */
    private static function bloco(mixed $bloco, int $posicao, bool $temArquivo, ?int $paginas): array
    {
        if (!\is_array($bloco) || !\is_string($bloco['k'] ?? null)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.bloco_invalido', ['posicao' => $posicao]);
        }

        return match (true) {
            \in_array($bloco['k'], self::TIPOS_DE_TEXTO, true) => self::texto($bloco, $posicao),
            $bloco['k'] === 'a' => self::assinatura($bloco, $posicao),
            $bloco['k'] === 'pg' => self::pagina($bloco, $posicao, $temArquivo, $paginas),
            default => throw new AcaoRecusada('assinatura_eletronica.erro.bloco_invalido', ['posicao' => $posicao]),
        };
    }

    /**
     * @param array<mixed> $bloco
     *
     * @return array{k: string, x: string, full: int}
     *
     * @throws AcaoRecusada
     */
    private static function texto(array $bloco, int $posicao): array
    {
        try {
            $texto = LeitorDeCampos::texto($bloco, 'x', aparar: false);
        } catch (AcaoRecusada) {
            throw new AcaoRecusada('assinatura_eletronica.erro.bloco_invalido', ['posicao' => $posicao]);
        }

        LeitorDeCampos::exigirTamanho($texto, self::TEXTO_MAXIMO, 'assinatura_eletronica.erro.bloco_texto_longo', ['posicao' => $posicao]);

        return ['k' => (string) $bloco['k'], 'x' => $texto, 'full' => LeitorDeCampos::booleano($bloco, 'full') ? 1 : 0];
    }

    /**
     * @param array<mixed> $bloco
     *
     * @return array{k: string, i: int, r: string}
     *
     * @throws AcaoRecusada
     */
    private static function assinatura(array $bloco, int $posicao): array
    {
        $indice = $bloco['i'] ?? null;
        if (!\is_int($indice) || $indice < 0) {
            throw new AcaoRecusada('assinatura_eletronica.erro.bloco_invalido', ['posicao' => $posicao]);
        }

        try {
            $papel = LeitorDeCampos::texto($bloco, 'r');
        } catch (AcaoRecusada) {
            throw new AcaoRecusada('assinatura_eletronica.erro.bloco_invalido', ['posicao' => $posicao]);
        }

        LeitorDeCampos::exigirTamanho($papel, self::PAPEL_MAXIMO, 'assinatura_eletronica.erro.bloco_invalido', ['posicao' => $posicao]);

        return ['k' => 'a', 'i' => $indice, 'r' => $papel];
    }

    /**
     * @param array<mixed> $bloco
     *
     * @return array{k: string, n: int}
     *
     * @throws AcaoRecusada
     */
    private static function pagina(array $bloco, int $posicao, bool $temArquivo, ?int $paginas): array
    {
        if (!$temArquivo) {
            throw new AcaoRecusada('assinatura_eletronica.erro.pagina_sem_arquivo');
        }

        $numero = $bloco['n'] ?? null;
        $maximo = $paginas ?? self::PAGINAS_SEM_CONTAGEM;
        if (!\is_int($numero) || $numero < 1 || $numero > $maximo) {
            throw new AcaoRecusada('assinatura_eletronica.erro.pagina_invalida', ['posicao' => $posicao]);
        }

        return ['k' => 'pg', 'n' => $numero];
    }
}
