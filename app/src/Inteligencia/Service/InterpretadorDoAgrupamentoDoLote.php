<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\AgrupamentoDoLoteOutput;
use App\Inteligencia\DTO\ArvoreDoLote;
use App\Inteligencia\Exception\RespostaInvalidaException;

/**
 * Confere a resposta do "Sugerir agrupamento com IA" contra a árvore enviada (spec importar-pastas-em-lote, etapa 2)
 * e a traduz de números para caminhos. É aqui que "pasta = caso" vira garantia técnica:
 *
 *  - caso com nó inexistente, ou que é ARQUIVO, sai;
 *  - caso dentro de outro caso escolhido sai: fica o de fora (se o de fora vem depois, ele tira os de dentro);
 *    o mesmo nó repetido fica uma vez só;
 *  - cliente vazio, que não é texto ou com mais de {@see TAMANHO_DO_CLIENTE} caracteres sai;
 *  - `mesmoClienteQue` que não aponta para outro caso que ficou vira null;
 *  - no máximo {@see TETO_DE_CASOS} casos.
 *
 * `soltos` é calculado aqui, não copiado do modelo: todo arquivo enviado que não ficou dentro de um caso. Assim nenhum
 * arquivo some. JSON quebrado ou sem a lista `casos` invalida a resposta inteira.
 */
final class InterpretadorDoAgrupamentoDoLote
{
    public const TETO_DE_CASOS = 500;
    public const TAMANHO_DO_CLIENTE = 255;
    private const TAMANHO_DO_TEXTO = 300;
    private const TETO_DE_DUVIDAS = 500;

    /**
     * @throws RespostaInvalidaException
     */
    public function interpretar(string $texto, ArvoreDoLote $arvore): AgrupamentoDoLoteOutput
    {
        $dados = $this->decodificar($texto);
        $escolhidos = $this->escolher($dados['casos'], $arvore);

        $casos = [];
        foreach ($escolhidos as $numero => $escolhido) {
            $mesmo = self::numero($escolhido['mesmo']);
            $casos[] = [
                'pasta' => (string) $arvore->no($numero)['caminho'],
                'cliente' => $escolhido['cliente'],
                'mesmoClienteQue' => $mesmo !== null && $mesmo !== $numero && isset($escolhidos[$mesmo])
                    ? (string) $arvore->no($mesmo)['caminho']
                    : null,
                'motivo' => $escolhido['motivo'],
            ];
        }

        $soltos = [];
        foreach ($arvore->nos as $no) {
            if (!$no['pasta'] && !$this->dentroDeAlgum($no['caminho'], array_keys($escolhidos), $arvore)) {
                $soltos[] = $no['caminho'];
            }
        }

        return new AgrupamentoDoLoteOutput($casos, $soltos, $this->duvidas($dados['duvidas'] ?? null, $arvore));
    }

    /**
     * Do primeiro "{" ao último "}". A resposta traz nomes: não vai junto na exceção (nem para `texto_bruto`).
     *
     * @return array{casos: list<mixed>, duvidas?: mixed}
     */
    private function decodificar(string $texto): array
    {
        $inicio = strpos($texto, '{');
        $fim = strrpos($texto, '}');
        if ($inicio === false || $fim === false || $fim < $inicio) {
            throw new RespostaInvalidaException('', 'resposta inválida: sem objeto JSON');
        }
        $dados = json_decode(substr($texto, $inicio, $fim - $inicio + 1), true);
        if (!is_array($dados) || !isset($dados['casos']) || !is_array($dados['casos']) || !array_is_list($dados['casos'])) {
            throw new RespostaInvalidaException('', 'resposta inválida: sem a lista de casos');
        }

        return $dados;
    }

    /**
     * Os casos que passam nas regras, na ordem da resposta: número => cliente, `mesmoClienteQue` cru e motivo.
     *
     * @param list<mixed> $lista
     *
     * @return array<int, array{cliente: string, mesmo: mixed, motivo: string}>
     */
    private function escolher(array $lista, ArvoreDoLote $arvore): array
    {
        $escolhidos = [];
        foreach ($lista as $caso) {
            if (count($escolhidos) >= self::TETO_DE_CASOS) {
                break;
            }
            $numero = is_array($caso) ? self::numero($caso['no'] ?? null) : null;
            $no = $numero !== null ? $arvore->no($numero) : null;
            if (!is_array($caso) || $numero === null || $no === null || !$no['pasta'] || isset($escolhidos[$numero])) {
                continue;
            }
            $cliente = is_string($caso['cliente'] ?? null) ? self::limpar($caso['cliente'], ' ') : '';
            if ($cliente === '' || mb_strlen($cliente) > self::TAMANHO_DO_CLIENTE || $this->dentroDeAlgum($no['caminho'], array_keys($escolhidos), $arvore)) {
                continue;
            }
            // O de fora vence: tira os já escolhidos que estão dentro deste.
            foreach (array_keys($escolhidos) as $outro) {
                if (self::dentro((string) $arvore->no($outro)['caminho'], $no['caminho'])) {
                    unset($escolhidos[$outro]);
                }
            }
            $motivo = is_string($caso['motivo'] ?? null) ? mb_substr(self::limpar($caso['motivo'], ', '), 0, self::TAMANHO_DO_TEXTO) : '';
            $escolhidos[$numero] = ['cliente' => $cliente, 'mesmo' => $caso['mesmoClienteQue'] ?? null, 'motivo' => $motivo];
        }

        return $escolhidos;
    }

    /** @return list<array{caminho: string, texto: string}> */
    private function duvidas(mixed $lista, ArvoreDoLote $arvore): array
    {
        if (!is_array($lista)) {
            return [];
        }
        $duvidas = [];
        foreach ($lista as $duvida) {
            if (count($duvidas) >= self::TETO_DE_DUVIDAS) {
                break;
            }
            if (!is_array($duvida)) {
                continue;
            }
            $numero = self::numero($duvida['no'] ?? null);
            $no = $numero !== null ? $arvore->no($numero) : null;
            $textoDaDuvida = is_string($duvida['texto'] ?? null) ? mb_substr(self::limpar($duvida['texto'], ', '), 0, self::TAMANHO_DO_TEXTO) : '';
            if ($no === null || $textoDaDuvida === '') {
                continue;
            }
            $duvidas[] = ['caminho' => $no['caminho'], 'texto' => $textoDaDuvida];
        }

        return $duvidas;
    }

    /** @param list<int> $escolhidos */
    private function dentroDeAlgum(string $caminho, array $escolhidos, ArvoreDoLote $arvore): bool
    {
        foreach ($escolhidos as $numero) {
            if (self::dentro($caminho, (string) $arvore->no($numero)['caminho'])) {
                return true;
            }
        }

        return false;
    }

    /** `$caminho` está estritamente dentro da pasta `$pasta`. */
    private static function dentro(string $caminho, string $pasta): bool
    {
        return str_starts_with($caminho, $pasta . '/');
    }

    /** Número inteiro positivo, aceitando "12" em texto; o resto é null. */
    private static function numero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }
        if (is_string($valor) && preg_match('/^\s*(\d{1,9})\s*$/', $valor, $m) === 1) {
            return (int) $m[1] > 0 ? (int) $m[1] : null;
        }

        return null;
    }

    /** Uma linha só, sem controle e sem travessão (texto que a tela mostra). */
    private static function limpar(string $texto, string $noLugarDoTravessao): string
    {
        $texto = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $texto);
        $texto = str_replace(["\u{2014}", "\u{2013}"], $noLugarDoTravessao, $texto);
        // "a , e b" e "a,, b" (o travessão trocado por vírgula) viram "a, e b".
        $texto = (string) preg_replace('/\s*,(?:\s*,)*/u', ',', $texto);

        return trim((string) preg_replace('/\s+/u', ' ', $texto), " ,");
    }
}
