<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Glue de autorização/contexto compartilhado pelos controllers de Cobrança (Etapa 8). Centraliza o
 * gate de módulo (`cobrancas`) e a checagem de capacidade de papel (`hasPermission`) usados por
 * leitura (Onda 8A) e mutação (Onda 8B), evitando duplicação. Espelha o padrão do DjenController.
 *
 * Requer que o controller que o usa seja um AbstractController e injete, como propriedades privadas
 * readonly, `$this->permissionChecker` (App\Service\PermissionChecker) e `$this->tenantContext`
 * (App\Service\Tenant\TenantContext).
 */
trait AutorizacaoCobranca
{
    private const MODULO_COBRANCAS = 'cobrancas';

    /**
     * @return array{0: User, 1: ?Tenant} usuário logado + tenant atual
     */
    private function contexto(): array
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return [$usuario, $this->tenantContext->getCurrentTenant()];
    }

    private function usuarioLogado(): User
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $usuario;
    }

    /**
     * Gate de módulo. Retorna o Tenant quando o usuário acessa o módulo de Cobranças; senão null.
     */
    private function tenantComModulo(): ?Tenant
    {
        [$usuario, $tenant] = $this->contexto();
        if ($tenant !== null && $this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO_COBRANCAS)) {
            return $tenant;
        }

        return null;
    }

    /**
     * Gate de módulo + capacidade de papel. Retorna o Tenant só quando o usuário acessa o módulo E
     * possui a capacidade (`resources.cobranca.gerenciar` / `resources.carteira.gerenciar` /
     * `resources.cobranca.movimentacao_financeira`); senão null. Capacidade via `hasPermission`
     * direto (não é per-item ACL — a tabela `resource_access` não está wired para cobrança).
     */
    private function tenantComCapacidade(string $capacidade): ?Tenant
    {
        [$usuario, $tenant] = $this->contexto();
        if ($tenant !== null
            && $this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO_COBRANCAS)
            && $this->permissionChecker->hasPermission($usuario, $tenant, $capacidade)
        ) {
            return $tenant;
        }

        return null;
    }

    private function semAcesso(): Response
    {
        $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de Cobranças.');

        return $this->redirectToRoute('homepage');
    }

    /**
     * Mutações da Onda 8B são POST-only com PRG (redirect sempre). Quando o Form é inválido, não há
     * página para re-renderizar com erros inline — então os erros de validação viram flashes, exibidos
     * ao voltar para a tela de origem.
     */
    private function flashErrosDoForm(FormInterface $form): void
    {
        foreach ($form->getErrors(true) as $erro) {
            $this->addFlash('danger', $erro->getMessage());
        }

        if (!$form->isSubmitted()) {
            $this->addFlash('danger', 'Não foi possível processar o formulário.');
        }
    }
}
