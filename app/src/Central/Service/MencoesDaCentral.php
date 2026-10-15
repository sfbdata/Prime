<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\DTO\MencoesResolvidas;
use App\Central\Entity\Mensagem;
use App\Entity\Auth\User;

/**
 * @menção no texto da Central (`docs/specs/central-comunicacao-a2.md` §2), no token canônico da casa — o mesmo do
 * Registro da pasta e da nota técnica: `@[Nome](user:ID)`. A mensagem é texto puro; o destaque é feito pela tela,
 * que lê o mesmo padrão.
 *
 * Quem decide quem é mencionável é quem chama (participante, com vínculo ativo, não o autor). Aqui só se aplica:
 * token de alguém mencionável tem o rótulo REESCRITO com o nome do banco (ninguém forja "@Diretor" com o id de
 * outro); token de qualquer outro id — e o que passar do teto — vira texto comum `@Rótulo` e não notifica.
 */
final class MencoesDaCentral
{
    public const PADRAO = Mensagem::PADRAO_MENCAO;

    /** Teto de tokens lidos por mensagem — o mesmo do Registro. */
    public const MAXIMO_POR_MENSAGEM = 20;

    /** @param array<int, User> $mencionaveis id → pessoa que pode ser mencionada nesta conversa */
    public function resolver(string $conteudo, array $mencionaveis): MencoesResolvidas
    {
        $lidos = 0;
        $mencionados = [];

        $reescrito = preg_replace_callback(self::PADRAO, static function (array $token) use (&$lidos, &$mencionados, $mencionaveis): string {
            ++$lidos;
            $id = (int) $token[2];
            $pessoa = $mencionaveis[$id] ?? null;
            if ($pessoa === null || $lidos > self::MAXIMO_POR_MENSAGEM) {
                return '@' . $token[1];
            }

            $mencionados[$id] = $pessoa;

            return sprintf('@[%s](user:%d)', self::rotulo($pessoa), $id);
        }, $conteudo);

        $reescrito ??= $conteudo;

        // Uma passada só não basta: o token rebaixado a texto pode "fechar" um token de fora que não casou por ter
        // `[` no rótulo (`@[Dr. @[x](user:9) ](user:5)` → `@[Dr. @x ](user:5)`). Repete até sobrar só token
        // canônico — o que esta função escreveu, de quem foi mencionado. Termina: cada volta remove um token.
        do {
            $antes = $reescrito;
            $reescrito = preg_replace_callback(self::PADRAO, static function (array $token) use ($mencionados): string {
                $pessoa = $mencionados[(int) $token[2]] ?? null;

                return $pessoa !== null && $token[1] === self::rotulo($pessoa) ? $token[0] : '@' . $token[1];
            }, $reescrito) ?? $antes;
        } while ($reescrito !== $antes);

        return new MencoesResolvidas($reescrito, array_values($mencionados));
    }

    /** O texto com cada token trocado por `@Nome` — para prévia, citação e o que não pode carregar marcação. */
    public static function comoTexto(string $conteudo): string
    {
        return Mensagem::textoVisivel($conteudo);
    }

    /**
     * O nome do banco, sem os caracteres que o padrão não aceita no rótulo e cortado em 120 — é o que a tela recebe
     * em `mencionaveis` e mostra como "@Nome", para o tamanho visível dela bater com o do servidor.
     */
    public static function rotulo(User $pessoa): string
    {
        $nome = trim(str_replace(['[', ']', '<', '>'], '', (string) $pessoa->getFullName()));
        $nome = $nome === '' ? 'colega' : $nome;

        return mb_substr($nome, 0, 120);
    }
}
