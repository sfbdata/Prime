<?php

declare(strict_types=1);

namespace App\Ponto\Model;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;

/**
 * A batida que um ajuste vai corrigir, lida sob a trava da pessoa (E10, plano-e10-ponto §6): a raiz da linhagem (o
 * registro original, alvo 'b{id}', ou a inclusão, alvo 'a{id}'), a cabeça atual e a forma que a folha conta agora.
 *
 * `cabeca` é nula quando o registro original nunca foi ajustado (a forma atual é a dele); na inclusão, a cabeça é no
 * mínimo a própria inclusão. Tipo e data/hora atuais são nulos quando a cabeça é uma desconsideração: a batida está
 * fora da conta.
 */
final readonly class AlvoDoAjuste
{
    public function __construct(
        public Tenant $escritorio,
        public User $pessoa,
        public string $alvo,
        public ?RegistroPonto $raizBatida,
        public ?PontoAjuste $raizInclusao,
        public ?PontoAjuste $cabeca,
        public ?string $tipoAtual,
        public ?\DateTimeImmutable $dataHoraAtual,
    ) {
        if (($raizBatida === null) === ($raizInclusao === null)) {
            throw new \LogicException('O alvo do ajuste tem uma raiz só: o registro original ou a inclusão.');
        }
        if ($raizInclusao !== null && $cabeca === null) {
            throw new \LogicException('Na inclusão, a cabeça é no mínimo a própria inclusão: sem ela, a linhagem ganharia uma segunda primeira linha.');
        }
    }

    /** O id da cabeça atual (o que a tela compara com a cabeça que ela abriu); nulo no registro nunca ajustado. */
    public function cabecaId(): ?int
    {
        return $this->cabeca?->getId();
    }

    /** A batida está fora da conta: a cabeça é uma desconsideração. */
    public function desconsiderada(): bool
    {
        return $this->tipoAtual === null;
    }
}
