<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\RespostaDePushInterpretada;
use App\Inteligencia\Enum\TipoDePonto;
use App\Inteligencia\Exception\RespostaInvalidaException;

/**
 * Transforma o texto do modelo no resultado estruturado do resumo do Push — a mesma receita do
 * Designer (`gerarPushIA`): recorta do primeiro "{" ao último "}", decodifica, valida o `resumo`,
 * limita a 5 pontos, derruba tipo desconhecido para `info` e tira travessões ("semTraco").
 *
 * Não corrige nem completa o que o modelo disse: resposta sem `resumo` é inválida, e o texto
 * integral vai junto na exceção para ficar em `texto_bruto`.
 */
final class InterpretadorDeRespostaDePush
{
    public const MAXIMO_DE_PONTOS = 5;

    /** Tamanho da coluna `quem_age`. */
    private const MAXIMO_QUEM_AGE = 120;

    /**
     * @throws RespostaInvalidaException
     */
    public function interpretar(string $texto): RespostaDePushInterpretada
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

        return new RespostaDePushInterpretada($resumo, $pontos, $quem === '' ? null : $quem);
    }

    /** Regra "semTraco" do Designer: travessão/meia-risca e hífen solto viram vírgula. */
    private function limpar(string $texto): string
    {
        $texto = (string) preg_replace('/\s*[—–]\s*/u', ', ', $texto);
        $texto = (string) preg_replace('/\s+-\s+/u', ', ', $texto);
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);

        return trim($texto);
    }
}
