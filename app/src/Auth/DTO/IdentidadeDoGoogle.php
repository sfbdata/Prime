<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/** Quem o Google disse que é, depois de conferido o `id_token` (e-mail sempre confirmado pelo Google). */
final class IdentidadeDoGoogle
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly string $nome,
        /** Domínio da conta Workspace (`hd` do `id_token`); vazio para conta comum. */
        public readonly string $dominio = '',
    ) {
    }

    /**
     * O e-mail vale como prova para casar com uma conta pelo e-mail ou criar uma conta com ele (decisão 10 do dono,
     * padrão do Google em developers.google.com/identity/account-linking/oauth-with-sign-in-linking): só conta
     * `@gmail.com` ou conta Workspace (`hd`), em que o Google é a autoridade do e-mail. Nas outras, o e-mail foi
     * confirmado uma vez, quando a conta Google foi criada, e pode ter mudado de dono depois.
     */
    public function emailComGarantia(): bool
    {
        return str_ends_with(mb_strtolower($this->email), '@gmail.com') || '' !== $this->dominio;
    }

    /**
     * Para guardar na sessão entre a volta do Google e o envio do cadastro.
     *
     * @return array{sub: string, email: string, nome: string, dominio: string}
     */
    public function paraSessao(): array
    {
        return ['sub' => $this->sub, 'email' => $this->email, 'nome' => $this->nome, 'dominio' => $this->dominio];
    }

    /** @param array<mixed> $dados */
    public static function daSessao(array $dados): ?self
    {
        $sub = $dados['sub'] ?? null;
        $email = $dados['email'] ?? null;
        if (!is_string($sub) || '' === $sub || !is_string($email) || '' === $email) {
            return null;
        }

        return new self($sub, $email, is_string($dados['nome'] ?? null) ? $dados['nome'] : '', is_string($dados['dominio'] ?? null) ? $dados['dominio'] : '');
    }
}
