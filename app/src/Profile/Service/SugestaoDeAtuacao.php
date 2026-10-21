<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\VinculoColaborador;

/**
 * O que o sistema sugere como atuação quando a pessoa ainda não declarou (spec docs/specs/meu-perfil-genero-atuacao.md):
 * OAB principal confirmada sugere advogado; vínculo de estágio no escritório sugere estagiário. Só preenche o campo
 * VAZIO no modal do Meu Perfil; quem grava é a pessoa, ao salvar.
 */
final class SugestaoDeAtuacao
{
    public static function para(User $user, ?VinculoColaborador $vinculo): ?Atuacao
    {
        // Os campos de OAB do User são a cópia da inscrição principal (EspelhoDaOabPrincipal).
        if ($user->getOabStatus() === StatusOab::Confirmada) {
            return Atuacao::Advogado;
        }

        return $vinculo === VinculoColaborador::Estagio ? Atuacao::Estagiario : null;
    }
}
