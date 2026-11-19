<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\EscritorioParaConectarOutput;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\Service\PortaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\UserTenantRepository;

/**
 * Os escritórios que a tela de autorização do conector mostra (`docs/specs/mcp-remoto-oauth.md`, 4 e 14.a): os
 * vínculos ativos da pessoa, cada um conferido pelas MESMAS regras da autorização, sem o passe do Master (4.1): o
 * {@see PortaoDoConector::conferir()} (pessoa, barreiras do escritório, vínculo aprovado, termos) e a
 * {@see LiberacaoDoConector::exigir()} (os dois níveis). O que não passa aparece desabilitado, com o motivo.
 *
 * Só mostra: quem decide no POST é o {@see AutorizarConexaoUseCase}, que confere tudo de novo.
 */
final class ListarEscritoriosParaConectarUseCase
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly PortaoDoConector $portao,
        private readonly LiberacaoDoConector $liberacao,
    ) {
    }

    /** @return list<EscritorioParaConectarOutput> */
    public function executar(User $pessoa): array
    {
        return array_map(
            fn (UserTenant $vinculo): EscritorioParaConectarOutput => $this->escritorio((int) $pessoa->getId(), $vinculo),
            array_values($this->vinculos->findActiveByUser($pessoa)),
        );
    }

    private function escritorio(int $userId, UserTenant $vinculo): EscritorioParaConectarOutput
    {
        $tenant = $vinculo->getTenant();
        $tenantId = (int) $tenant->getId();

        try {
            $this->portao->conferir($userId, $tenantId);
            $this->liberacao->exigir($tenant);
        } catch (AcessoDoConectorRecusado $recusa) {
            return new EscritorioParaConectarOutput($tenantId, (string) $tenant->getName(), false, $recusa->getMessage());
        }

        return new EscritorioParaConectarOutput($tenantId, (string) $tenant->getName(), true, null);
    }
}
