<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\Entity\CasoCobranca;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
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
     * Resolve o id do Objeto ao qual o Caso pertence — destino canônico de todo redirect pós-mutação
     * (ajuste 2, Fatia 5): o Caso deixou de ter tela própria, a página do Objeto é a única. A JoinColumn
     * `objeto` é NOT NULL; os getters são nullable só pelo estado pré-persistência, então blindamos com
     * LogicException (nunca deve ocorrer com um Caso persistido). Aceita `?CasoCobranca` para cobrir
     * também `Documento::getCaso()`, cujo getter é nullable.
     */
    private function objetoIdDoCaso(?CasoCobranca $caso): int
    {
        $objeto = $caso?->getObjeto();
        if ($objeto === null || $objeto->getId() === null) {
            throw new \LogicException('Não foi possível resolver o objeto do caso de cobrança.');
        }

        return $objeto->getId();
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

    /**
     * B5 (ajuste 10): trata um Form inválido de mutação decidindo entre reabrir o modal com o digitado
     * (erro de VALIDAÇÃO de campo, que o gestor corrige e reenvia) e a rejeição limpa de antes (erro de
     * RAIZ — CSRF/token expirado ou forjado —, um evento de segurança, não um "digitou errado"). O
     * sinal: erros de campo ficam nos filhos (`getErrors()` da raiz conta 0, não borbulham); o erro de
     * CSRF fica na raiz. Só o primeiro reabre o modal ecoando o payload.
     *
     * @param string $formKey     chave do FormView no array `forms` do template (ex.: 'registrarObrigacao')
     * @param string $modalId     id do modal no DOM a reabrir (ex.: 'modalRegistrarObrigacao')
     * @param string $blockPrefix prefixo do Form no request (ex.: 'registrar_obrigacao')
     */
    private function tratarFormInvalido(Request $request, FormInterface $form, int $objetoId, string $formKey, string $modalId, string $blockPrefix): void
    {
        if (\count($form->getErrors()) > 0) {
            // Erro de raiz (CSRF): rejeição limpa, sem ecoar o payload de volta na página.
            $this->flashErrosDoForm($form);

            return;
        }

        $this->guardarErroDeModal($request, $objetoId, $formKey, $modalId, $blockPrefix);
    }

    /**
     * Mantém a PRG, mas em vez de o erro de validação virar um flash solto que apaga o que o gestor
     * digitou, guarda na sessão — ONE-SHOT, por objeto — o payload submetido e qual modal reabrir. O
     * `ObjetoController::show` consome esse estado, re-submete o Form (reidratando valores e erros via
     * `form_row`) e reabre o modal. Nada de entidade na sessão: só o array cru do request.
     */
    private function guardarErroDeModal(Request $request, int $objetoId, string $formKey, string $modalId, string $blockPrefix): void
    {
        $request->getSession()->set(self::chaveErroModal($objetoId), [
            'form' => $formKey,
            'modalId' => $modalId,
            'payload' => $request->request->all($blockPrefix),
        ]);
    }

    /**
     * Lê-e-consome (one-shot) o estado de erro de modal guardado por `guardarErroDeModal`. Removê-lo na
     * leitura é o que impede o modal de reabrir em toda visita seguinte à mesma página.
     *
     * @return array{form: string, modalId: string, payload: array<string, mixed>}|null
     */
    private function consumirErroDeModal(Request $request, int $objetoId): ?array
    {
        $session = $request->getSession();
        $chave = self::chaveErroModal($objetoId);
        $estado = $session->get($chave);
        $session->remove($chave);

        return \is_array($estado) ? $estado : null;
    }

    private static function chaveErroModal(int $objetoId): string
    {
        // O id do objeto na chave impede o estado de vazar entre objetos/abas (spec §B5).
        return 'cobranca_modal_erro_' . $objetoId;
    }
}
