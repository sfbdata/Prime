<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Dashboard\Entity\AcompanhamentoDaPessoa;

/**
 * O estado do acompanhamento de uma pessoa para a tela: aberto ou não, a data de revisão e se venceu.
 * Sem nome, sem texto: só o que o perfil precisa para o chip e o botão.
 */
final readonly class AcompanhamentoDaPessoaOutput
{
    public function __construct(
        public int $pessoaId,
        public bool $aberto,
        public ?\DateTimeImmutable $revisarEm,
        public bool $vencido,
    ) {
    }

    public static function semAcompanhamento(int $pessoaId): self
    {
        return new self($pessoaId, false, null, false);
    }

    public static function deEntidade(AcompanhamentoDaPessoa $a, \DateTimeImmutable $agora): self
    {
        return new self((int) $a->getPessoa()->getId(), $a->estaAberto(), $a->estaAberto() ? $a->getRevisarEm() : null, $a->estaVencido($agora));
    }

    /** @return array{pessoa: int, aberto: bool, revisar_em: ?string, revisar_ate: ?string, vencido: bool} */
    public function paraArray(): array
    {
        return [
            'pessoa'       => $this->pessoaId,
            'aberto'       => $this->aberto,
            'revisar_em'   => $this->revisarEm?->format('Y-m-d'),
            'revisar_ate'  => $this->revisarEm?->format('d/m'),
            'vencido'      => $this->vencido,
        ];
    }
}
