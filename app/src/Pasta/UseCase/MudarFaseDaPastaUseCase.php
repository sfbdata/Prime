<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\AndamentoDaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clique numa fase da trilha do andamento (desenho 1.2.3, `faseMarcar`), depois da confirmação da tela.
 *
 * História: o advogado protocolou a inicial e clica em "Protocolo". A tela pergunta "Mudar de X para
 * Protocolo?" (avisando quantas fases ficam puladas); confirmado, a fase é gravada com data e hora, o Registro
 * ganha "Andamento: Fase da pasta alterada de X para Protocolo." e a pasta sai do filtro "Protocolar" da Lista.
 * Avançar pela faixa de sugestão não pula fases (`$semPular`) e leva o motivo. Precisa poder EDITAR a pasta.
 */
final class MudarFaseDaPastaUseCase
{
    public function __construct(
        private readonly AndamentoDaPasta $andamento,
        private readonly PermissionChecker $permissionChecker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant, int $fase, bool $semPular, string $motivo): ?string
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        $texto = $this->andamento->marcar($pasta, $usuario, $tenant, $fase, $semPular, mb_substr(trim($motivo), 0, 200));
        $this->em->flush();

        return $texto;
    }
}
