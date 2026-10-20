<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Pasta\Entity\Pasta;
use App\Service\PermissionChecker;

/**
 * Se a chave "Análise da BlueJus IA" do "Avisar quando chegar" pode valer (spec
 * push-avisar-quando-chegar §2):
 *   - escritório: os dois primeiros níveis da {@see DisponibilidadeDeInteligencia} (plataforma e
 *     escritório), pela própria regra dela (`paraEscritorio`), com o mesmo motivo;
 *   - pasta: o responsável é quem pede a análise na captação; sem `modules.inteligencia.view` o
 *     pedido seria recusado sempre, então a chave trava com a dica. Pasta sem responsável não trava
 *     (não há a quem perguntar; a captação simplesmente não pede).
 * A cota fica com o `SolicitarResumoDoPushUseCase`, na hora do pedido.
 */
final class DisponibilidadeDaIaNoAvisoDoPush
{
    public const DICA_RESPONSAVEL_SEM_IA = 'O responsável da pasta não tem acesso à BlueJus IA.';

    public function __construct(
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function doEscritorio(Tenant $tenant): Disponibilidade
    {
        return $this->disponibilidade->paraEscritorio($tenant);
    }

    /** O motivo de a chave da IA travar nesta pasta, ou null. */
    public function motivoDaTravaNaPasta(Pasta $pasta, Tenant $tenant): ?string
    {
        $doEscritorio = $this->doEscritorio($tenant);
        if (!$doEscritorio->estaDisponivel()) {
            return $doEscritorio->mensagem() . '.';
        }

        $responsavel = $pasta->getResponsavel();
        if ($responsavel !== null && !$this->permissoes->canAccessModule($responsavel, $tenant, DisponibilidadeDeInteligencia::MODULO)) {
            return self::DICA_RESPONSAVEL_SEM_IA;
        }

        return null;
    }
}
