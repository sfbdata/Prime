<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;

/**
 * O "Trancar" do modal do cadeado. `niveis` é a grade (id do usuário => nível) e só vale no modo
 * `PorPessoa`; quem não está nela fica `Oculto`.
 */
final class ConfigurarCadeadoInput
{
    /** @param array<int, NivelDeAcesso> $niveis */
    public function __construct(
        public readonly Pasta $pasta,
        public readonly User $ator,
        public readonly Tenant $tenant,
        public readonly ModoDoCadeado $modo,
        public readonly array $niveis = [],
    ) {
    }

    /**
     * A grade como chega do formulário: `niveis[<id>] = oculto|visualizar|editar`. Valor desconhecido
     * é recusado, para o modal não trancar diferente do que mostrou.
     *
     * @param array<mixed> $grade
     *
     * @return array<int, NivelDeAcesso>
     */
    public static function gradeDoFormulario(array $grade): array
    {
        $niveis = [];
        foreach ($grade as $id => $valor) {
            $nivel = is_string($valor) ? NivelDeAcesso::tryFrom($valor) : null;
            if (!is_numeric($id) || $nivel === null) {
                throw new \DomainException('Nível de acesso inválido na grade do cadeado.');
            }
            $niveis[(int) $id] = $nivel;
        }

        return $niveis;
    }
}
