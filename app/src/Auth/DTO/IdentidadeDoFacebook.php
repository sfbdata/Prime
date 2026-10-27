<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * Quem o Facebook disse que é (`/me`). O `id` prova a pessoa para o app; o e-mail NÃO é prova de nada (o Facebook não
 * o garante verificado) e serve só de sugestão para o campo da tela: nunca casa, liga nem confirma conta existente.
 */
final class IdentidadeDoFacebook
{
    public function __construct(
        public readonly string $id,
        public readonly string $nome,
        /** Sugestão de e-mail; nulo quando o Facebook não manda. */
        public readonly ?string $email = null,
    ) {
    }

    /**
     * Para guardar na sessão entre a volta do Facebook e o envio do cadastro.
     *
     * @return array{id: string, nome: string, email: ?string}
     */
    public function paraSessao(): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'email' => $this->email];
    }

    /** @param array<mixed> $dados */
    public static function daSessao(array $dados): ?self
    {
        $id = $dados['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            return null;
        }
        $email = $dados['email'] ?? null;

        return new self($id, is_string($dados['nome'] ?? null) ? $dados['nome'] : '', is_string($email) && '' !== $email ? $email : null);
    }
}
