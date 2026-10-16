<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;

/**
 * "Pedir acesso" da janela de pasta protegida, desenho 1.1 (E12).
 *
 * História: o colaborador clica numa pasta trancada, lê que ela tem documentos sigilosos e pede acesso.
 * Cada administrador ativo do escritório recebe uma notificação no sino, com link para a pasta. O desenho
 * fala em Chat I.A., que não existe no sistema: a notificação é o canal real. Pasta que não está trancada
 * não gera pedido. Devolve quantos administradores foram avisados.
 */
final class PedirAcessoAPastaTrancadaUseCase
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly NotificacaoService $notificacaoService,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant, string $urlDaPasta): int
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$pasta->estaTrancada()) {
            throw new \DomainException('Esta pasta não está trancada.');
        }

        $mensagem = sprintf('%s pediu acesso à pasta %s (trancada)', $usuario->getFullName(), $pasta->getNup());
        $avisados = 0;

        foreach ($this->userTenantRepository->findUsuariosAdminsAtivos($tenant) as $admin) {
            if ($admin === $usuario) {
                continue;
            }
            $this->notificacaoService->criarNotificacao($admin, $tenant, Notificacao::TIPO_PASTA_PEDIDO_ACESSO, $mensagem, $urlDaPasta);
            ++$avisados;
        }

        return $avisados;
    }
}
