<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Enum\Perfil;

/**
 * Quem está pedindo, em que escritório, com que perfil, e a configuração da Central naquele
 * momento. Montado UMA vez por requisição (`ResolvedorDePerfil`) e passado para a política e os
 * UseCases: ninguém abaixo do controller relê sessão ou papel. Para quem é Convidado numa demanda,
 * o `LocalizadorDeDemanda` troca o contexto pelo de Convidado daquela demanda (`comoConvidado`).
 */
final readonly class Contexto
{
    public function __construct(
        public User $usuario,
        public ?Tenant $tenant,
        public Perfil $perfil,
        public ConfiguracaoCentral $configuracao,
        /** Só no perfil Convidado: o convite que dá acesso à demanda em questão (o perfil é por demanda). */
        public ?ConviteDemanda $convite = null,
    ) {
    }

    /** O mesmo usuário, como Convidado da demanda do convite. */
    public function comoConvidado(ConviteDemanda $convite): self
    {
        return new self($this->usuario, $this->tenant, Perfil::Convidado, $this->configuracao, $convite);
    }

    public function autor(): Autor
    {
        return Autor::de($this->usuario, $this->perfil);
    }

    public function ehInterno(): bool
    {
        return $this->perfil->ehInterno();
    }
}
