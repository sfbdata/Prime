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
        /**
         * Pré-visualização do Master (spec §9): a tela é montada como este perfil a veria, mas
         * SÓ para leitura. A política nega toda escrita quando isto está ligado.
         */
        public bool $previa = false,
        /**
         * Só no perfil Master: os escritórios (ids) em que ele é usuário com vínculo ativo. Nas
         * demandas deles o Master age TAMBÉM como Solicitante (spec §9.2, "dois papéis").
         *
         * @var list<int>
         */
        public array $escritoriosDoPrimario = [],
        /**
         * Só no perfil Convidado: o escritório dono da demanda está fora do ar (suspenso ou excluído). Ele só lê,
         * como na pré-visualização (docs/specs/te-convidado-escritorio-fora-do-ar.md).
         */
        public bool $donoForaDoAr = false,
    ) {
    }

    /** O mesmo usuário, como Convidado da demanda do convite. */
    public function comoConvidado(ConviteDemanda $convite, bool $donoForaDoAr = false): self
    {
        return new self($this->usuario, $this->tenant, Perfil::Convidado, $this->configuracao, $convite, $this->previa, [], $donoForaDoAr);
    }

    /** Nenhuma escrita: a pré-visualização do Master ou o Convidado de escritório fora do ar. */
    public function somenteLeitura(): bool
    {
        return $this->previa || $this->donoForaDoAr;
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
