<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * Quem pode abonar ou recusar a PRÓPRIA justificativa de ponto (P43 a do desenho do Ponto,
 * docs/specs/ponto-desenho-0811.md, E3): o primário do escritório e o Master, como no desenho.
 *
 * A permissão de analisar (`admin.users.manage`, conferida antes nas rotas do TenantController) não
 * basta para a própria: quem gere pelo perfil e não é primário nem Master abonaria as próprias faltas.
 * A definição de "primário ou Master" é a do sistema (`RegraDosPrimarios::podeGerirPrimarios`).
 *
 * Analisar a justificativa de OUTRA pessoa não passa por aqui: segue só a regra de hoje.
 */
final class AnaliseDaPropriaJustificativa
{
    public const RECUSA = 'Só o usuário primário do escritório ou o Master pode analisar a própria justificativa.';

    public function __construct(
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    /** Analista e dono diferentes: nada a decidir aqui. A própria: só primário ativo ou Master. */
    public function permite(User $analista, User $dono, Tenant $tenant): bool
    {
        if (!$this->ehAPropria($analista, $dono)) {
            return true;
        }

        // A linha da troca do P43 a: com o Ponto antigo de volta, `return true;` devolve a regra de antes (quem
        // analisa pelo perfil analisa também a própria). É o único lugar da regra: a ficha e a aba passam por aqui.
        return $this->regraDosPrimarios->podeGerirPrimarios($analista, $tenant);
    }

    private function ehAPropria(User $analista, User $dono): bool
    {
        return $analista === $dono || ($analista->getId() !== null && $analista->getId() === $dono->getId());
    }
}
