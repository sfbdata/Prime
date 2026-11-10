<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * O selo de uma linha da trilha do Ponto (E9): HMAC-SHA256, com a chave da trilha
 * ({@see ChavesDoHmacDoPonto::FINALIDADE_TRILHA}), sobre o JSON canônico (chaves em ordem, sem escapar acento nem
 * barra) dos campos da linha:
 *
 * `selo_anterior`, `tenant_id`, `sequencia`, `em` (ISO 8601 local, em segundos, que é o que a coluna guarda),
 * `autor_id`, `autor_nome`, `acao` e `detalhe` (também com as chaves em ordem, em todos os níveis).
 *
 * Determinístico e sem dependência: a gravação ({@see TrilhaDoPonto}) e a conferência
 * ({@see \App\Ponto\UseCase\ConferirTrilhaDoPontoUseCase}) chamam a mesma conta, então qualquer coluna mexida depois
 * da gravação, inclusive o elo com a linha anterior, deixa de bater.
 */
final class SeloDaTrilhaDoPonto
{
    /**
     * @param array{selo_anterior: ?string, tenant_id: int, sequencia: int, em: string, autor_id: ?int,
     *     autor_nome: ?string, acao: string, detalhe: array<string, mixed>} $linha
     */
    public static function calcular(string $chave, array $linha): string
    {
        return hash_hmac('sha256', self::mensagem($linha), $chave);
    }

    /**
     * O texto selado, exposto para o teste provar que é o mesmo antes e depois de gravar.
     *
     * @param array{selo_anterior: ?string, tenant_id: int, sequencia: int, em: string, autor_id: ?int,
     *     autor_nome: ?string, acao: string, detalhe: array<string, mixed>} $linha
     */
    public static function mensagem(array $linha): string
    {
        $campos = [
            'acao'          => $linha['acao'],
            'autor_id'      => $linha['autor_id'],
            'autor_nome'    => $linha['autor_nome'],
            'detalhe'       => $linha['detalhe'],
            'em'            => (new \DateTimeImmutable($linha['em']))->format('Y-m-d\TH:i:s'),
            'selo_anterior' => $linha['selo_anterior'],
            'sequencia'     => $linha['sequencia'],
            'tenant_id'     => $linha['tenant_id'],
        ];

        return json_encode(self::ordenar($campos), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * Chaves em ordem em todos os níveis; listas mantêm a ordem dos itens.
     *
     * @param array<mixed> $valor
     *
     * @return array<mixed>
     */
    private static function ordenar(array $valor): array
    {
        if (!array_is_list($valor)) {
            ksort($valor, \SORT_STRING);
        }

        return array_map(static fn (mixed $item): mixed => is_array($item) ? self::ordenar($item) : $item, $valor);
    }
}
