<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma unidade na tela "Unidades e links". O `token` é recalculado da semente (o banco não o guarda) e só vai para a
 * tela da equipe, que monta o link `/votar/{token}`.
 */
final readonly class UnidadeNaLista
{
    public function __construct(
        public int $id,
        public string $identificacao,
        public string $bloco,
        public ?string $situacao,
        public bool $apta,
        public ?string $fracao,
        public ?string $responsavel,
        public ?string $email,
        public string $token,
        public ?\DateTimeImmutable $linkEnviadoEm,
    ) {
    }

    /** "Bloco A · A-101", ou só a unidade sem bloco. */
    public function rotulo(): string
    {
        return '' === $this->bloco ? $this->identificacao : $this->bloco . ' · ' . $this->identificacao;
    }
}
