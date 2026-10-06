<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Enum\TipoDePonto;
use App\Inteligencia\Exception\RespostaInvalidaException;

/**
 * Transforma a resposta de um agente da pasta no resultado estruturado — mesma receita do
 * interpretador do Push (recorta do primeiro "{" ao último "}", exige `resumo`, derruba tipo
 * desconhecido para `info`, tira travessões), com dois a mais: até 8 pontos e o `texto` integral
 * da análise (formato do Designer), que vai para `texto_da_analise`.
 *
 * Não corrige nem completa o que o modelo disse: sem `resumo` é inválida (o texto integral vai
 * junto na exceção para `texto_bruto`); `texto` ausente fica nulo — não se escreve análise por ele.
 */
final class InterpretadorDeRespostaDoAgente
{
    public const MAXIMO_DE_PONTOS = 8;

    /** Teto do `texto_da_analise`: acima disto é resposta fora de controle, não análise. */
    public const TAMANHO_MAXIMO_DO_TEXTO = 20000;

    /** Tamanho da coluna `quem_age`. */
    private const MAXIMO_QUEM_AGE = 120;

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

        $resumo = isset($dados['resumo']) && is_string($dados['resumo']) ? $this->limpar($dados['resumo']) : '';
        if ($resumo === '') {
            throw new RespostaInvalidaException($texto, 'resposta inválida: sem resumo');
        }

        $pontos = [];
        foreach (is_array($dados['pontos'] ?? null) ? $dados['pontos'] : [] as $ponto) {
            if (!is_array($ponto) || !isset($ponto['texto']) || !is_string($ponto['texto'])) {
                continue;
            }
            $textoDoPonto = $this->limpar($ponto['texto']);
            if ($textoDoPonto === '') {
                continue;
            }
            $pontos[] = [
                'tipo' => TipoDePonto::deString($ponto['tipo'] ?? null)->value,
                'texto' => $textoDoPonto,
            ];
            if (count($pontos) === self::MAXIMO_DE_PONTOS) {
                break;
            }
        }

        $quem = isset($dados['quem']) && is_string($dados['quem']) ? $this->limpar($dados['quem']) : '';
        if ($quem !== '') {
            $quem = mb_substr($quem, 0, self::MAXIMO_QUEM_AGE);
        }

        $analise = isset($dados['texto']) && is_string($dados['texto']) ? $this->limparTexto($dados['texto']) : '';

        return new ResultadoDaAnalise($resumo, $pontos, $quem === '' ? null : $quem, $analise === '' ? null : $analise);
    }

    /** Regra "semTraco" do Designer: travessão/meia-risca e hífen solto viram vírgula. */
    private function limpar(string $texto): string
    {
        $texto = (string) preg_replace('/\s*[—–]\s*/u', ', ', $texto);
        $texto = (string) preg_replace('/\s+-\s+/u', ', ', $texto);
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);

        return trim($texto);
    }

    /** O texto integral preserva as quebras de linha (títulos e itens "• "); o resto é a mesma limpeza. */
    private function limparTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = (string) preg_replace('/[^\P{C}\n]+/u', '', $texto); // controle, menos \n
        $texto = (string) preg_replace('/[ \t]*[—–][ \t]*/u', ', ', $texto);
        $texto = (string) preg_replace('/[ \t]+-[ \t]+/u', ', ', $texto);
        $texto = (string) preg_replace('/[ \t]+/u', ' ', $texto);
        $texto = (string) preg_replace('/\n{3,}/u', "\n\n", $texto);
        $texto = trim($texto);

        if (mb_strlen($texto) > self::TAMANHO_MAXIMO_DO_TEXTO) {
            $texto = mb_substr($texto, 0, self::TAMANHO_MAXIMO_DO_TEXTO) . '…';
        }

        return $texto;
    }
}
