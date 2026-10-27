<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Enum\TipoDePonto;
use App\Inteligencia\Exception\RespostaInvalidaException;

/**
 * Transforma a resposta do chat da Central no resultado estruturado: recorta do primeiro "{" ao último "}", exige
 * `resposta` (texto não vazio; as quebras de linha ficam), até {@see MAXIMO_DE_PONTOS} pontos (tipo + texto) e tira
 * travessões. `metricas`, `acoes` e `trace` são IGNORADOS nesta versão (ficam só em `texto_bruto`): nada do que o
 * modelo "sugere" vira ação.
 *
 * Sem `resposta` a resposta é inválida e o texto integral vai junto na exceção para `texto_bruto`.
 */
final class InterpretadorDeRespostaDoChat
{
    public const MAXIMO_DE_PONTOS = 6;

    /** Teto da resposta: acima disto é resposta fora de controle. */
    public const TAMANHO_MAXIMO_DA_RESPOSTA = 20000;

    /**
     * @throws RespostaInvalidaException
     */
    public function interpretar(string $texto): ResultadoDaAnalise
    {
        $inicio = strpos($texto, '{');
        $fim = strrpos($texto, '}');
        if ($inicio === false || $fim === false || $fim < $inicio) {
            throw new RespostaInvalidaException($texto, 'resposta inválida: sem objeto JSON');
        }

        $dados = json_decode(substr($texto, $inicio, $fim - $inicio + 1), true);
        if (!is_array($dados)) {
            throw new RespostaInvalidaException($texto, 'resposta inválida: JSON malformado');
        }

        $resposta = isset($dados['resposta']) && is_string($dados['resposta']) ? $this->limparTexto($dados['resposta']) : '';
        if ($resposta === '') {
            throw new RespostaInvalidaException($texto, 'resposta inválida: sem resposta');
        }

        $pontos = [];
        foreach (is_array($dados['pontos'] ?? null) ? $dados['pontos'] : [] as $ponto) {
            if (!is_array($ponto) || !isset($ponto['texto']) || !is_string($ponto['texto'])) {
                continue;
            }
            $textoDoPonto = $this->limparLinha($ponto['texto']);
            if ($textoDoPonto === '') {
                continue;
            }
            $pontos[] = ['tipo' => TipoDePonto::deString($ponto['tipo'] ?? null)->value, 'texto' => $textoDoPonto];
            if (count($pontos) === self::MAXIMO_DE_PONTOS) {
                break;
            }
        }

        return new ResultadoDaAnalise($resposta, $pontos, null);
    }

    /** Uma linha só: travessão, meia-risca e hífen solto viram vírgula; meia-risca entre dígitos vira " a ". */
    private function limparLinha(string $texto): string
    {
        $texto = $this->semControle($texto, true);
        $texto = $this->semTravessao($texto);
        $texto = (string) preg_replace('/\s+-\s+/u', ', ', $texto);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** A resposta preserva as quebras de linha; o resto é a mesma limpeza. */
    private function limparTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = $this->semControle($texto, false);
        $texto = $this->semTravessao($texto);
        $texto = (string) preg_replace('/[ \t]+-[ \t]+/u', ', ', $texto);
        $texto = (string) preg_replace('/[ \t]+/u', ' ', $texto);
        $texto = (string) preg_replace('/ ?\n ?/u', "\n", $texto);
        $texto = (string) preg_replace('/\n{3,}/u', "\n\n", $texto);
        $texto = trim($texto);

        if (mb_strlen($texto) > self::TAMANHO_MAXIMO_DA_RESPOSTA) {
            $texto = mb_substr($texto, 0, self::TAMANHO_MAXIMO_DA_RESPOSTA) . '…';
        }

        return $texto;
    }

    /**
     * Tira controle (Cc, menos \n) e formato invisível (Cf: bidi, zero-width, BOM); o \t vira espaço (não cola as
     * palavras). Só ZWNJ (U+200C) e ZWJ (U+200D) ficam: o persa, o árabe e os emojis compostos dependem deles.
     * `$tambemNovaLinha` = a linha única também perde o \n.
     */
    private function semControle(string $texto, bool $tambemNovaLinha): string
    {
        $texto = (string) preg_replace('/\t/u', ' ', $texto);

        $texto = (string) preg_replace('/(?![\x{200C}\x{200D}])\p{Cf}/u', '', $texto);

        return (string) preg_replace($tambemNovaLinha ? '/\p{Cc}+/u' : '/[^\P{Cc}\n]+/u', $tambemNovaLinha ? ' ' : '', $texto);
    }

    /** Meia-risca entre dígitos vira " a " (10 a 20); o resto do travessão vira vírgula. */
    private function semTravessao(string $texto): string
    {
        $texto = (string) preg_replace('/(?<=\d)[ \t]*\x{2013}[ \t]*(?=\d)/u', ' a ', $texto);

        return (string) preg_replace('/[ \t]*[\x{2014}\x{2013}][ \t]*/u', ', ', $texto);
    }
}
