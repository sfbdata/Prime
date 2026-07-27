<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\EventoNaoEncontradoException;
use App\Cobranca\Exception\QualificacaoNaoDesfazivelException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\UseCase\DesfazerQualificacaoContatoUseCase;
use App\Cobranca\UseCase\RegistrarQualificacaoContatoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP da qualificação de contato do painel da aba Responsáveis (spec §3.5, 2026-07-27).
 * Controller FINO: gate de capacidade, resolução tenant-safe por id (anti-IDOR), CSRF, delegação ao
 * UseCase e tradução de exceção em flash. As quatro condições do desfazer vivem no UseCase/entidade —
 * aqui nada é recalculado.
 *
 * **Por que um controller próprio, e não mais duas actions no `CasoController`.** A spec fixa os dois
 * caminhos, e o segundo (`/cobrancas/qualificacoes/{eventoId}/desfazer`) fica FORA do prefixo
 * `/cobrancas/casos` da classe do caso — o Symfony não deixa uma action escapar do prefixo da sua
 * classe. Com o prefixo `/cobrancas` aqui, os dois caminhos saem exatamente como a spec pede. De
 * quebra, o `CasoController` já estava nas 10 actions da heurística 5-10-20.
 *
 * O POST do registrar não tem Form nem Input DTO de propósito (spec §3): não há formulário a validar,
 * só um enum vindo de um botão. `QualificacaoContato::tentarDe` faz a hidratação segura do valor cru —
 * valor desconhecido é recusado com flash, não com exceção.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class QualificacaoContatoController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly RegistrarQualificacaoContatoUseCase $registrarQualificacao,
        private readonly DesfazerQualificacaoContatoUseCase $desfazerQualificacao,
    ) {
    }

    /**
     * Um clique no painel vira um evento de histórico. Volta para a aba Responsáveis, onde o painel
     * mora — quem qualificou vê a linha nova na listinha sem trocar de aba.
     */
    #[Route('/casos/{id}/qualificacoes', name: 'cobranca_qualificacao_registrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function registrar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        // A guarda de tenant vem ANTES do CSRF e da leitura do payload: caso de outro escritório tem de
        // responder 404 mesmo com token ruim ou botão inexistente. Validar primeiro faria um POST
        // forjado voltar com "sessão expirada" e esconder o anti-IDOR atrás de um redirect.
        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        // Mesma cadeia nullable do desfazer, de propósito: a JoinColumn é NOT NULL, mas o getter é
        // nullable e `objetoIdDoCaso()` responderia a um grafo quebrado com 500 — ANTES do CSRF, ainda
        // por cima. As duas actions degradam igual, para o mesmo destino de fallback.
        $objetoId = $caso->getObjeto()?->getId();

        if (!$this->isCsrfTokenValid('registrar_qualificacao_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Sessão expirada. Tente novamente.');

            return $this->voltarParaResponsaveis($objetoId);
        }

        $qualificacao = QualificacaoContato::tentarDe($request->request->get('qualificacao'));
        if ($qualificacao === null) {
            $this->addFlash('danger', 'Qualificação inválida.');

            return $this->voltarParaResponsaveis($objetoId);
        }

        try {
            $this->registrarQualificacao->executar($id, $qualificacao, $tenant, $this->usuarioLogado());
            // "Qualificação registrada: <rótulo>" e não "<rótulo> registrada": dois dos três rótulos são
            // masculinos ("Telefone inexistente"), e concordar com o rótulo exigiria gênero no enum.
            $this->addFlash('success', 'Qualificação registrada: ' . $qualificacao->label() . '.');
        } catch (CasoEncerradoException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (CasoNaoEncontradoException) {
            // Inalcançável no fluxo normal (o caso acabou de ser resolvido, na mesma request e no mesmo
            // EM). Fica pela corrida teórica — o UseCase re-resolve, e sem isto um caso apagado no meio
            // do caminho viraria 500 em vez do 404 que o resto da rota já dá.
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        return $this->voltarParaResponsaveis($objetoId);
    }

    /**
     * Válvula de escape do clique errado (5 minutos, só o autor, só a última — spec §3.5). O objeto de
     * destino é resolvido ANTES da remoção: depois dela o evento já não leva a lugar nenhum.
     */
    #[Route('/qualificacoes/{eventoId}/desfazer', name: 'cobranca_qualificacao_desfazer', methods: ['POST'], requirements: ['eventoId' => '\d+'])]
    public function desfazer(int $eventoId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        // Guarda de tenant primeiro, pela mesma razão do registrar: qualificação de outro escritório é
        // 404, não "sessão expirada".
        $evento = $this->eventoRepository->findOneByIdDoTenant($eventoId, $tenant);
        if ($evento === null) {
            throw $this->createNotFoundException('Qualificação não encontrada.');
        }

        $objetoId = $evento->getCaso()?->getObjeto()?->getId();

        if (!$this->isCsrfTokenValid('desfazer_qualificacao_' . $eventoId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Sessão expirada. Tente novamente.');

            return $this->voltarParaResponsaveis($objetoId);
        }

        try {
            $this->desfazerQualificacao->executar($eventoId, $tenant, $this->usuarioLogado());
            $this->addFlash('success', 'Qualificação desfeita.');
        } catch (EventoNaoEncontradoException) {
            throw $this->createNotFoundException('Qualificação não encontrada.');
        } catch (QualificacaoNaoDesfazivelException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->voltarParaResponsaveis($objetoId);
    }

    /**
     * Destino único das duas rotas: a aba onde o painel mora. `?aba=responsaveis` é o mecanismo
     * genérico que o `show` já usa para ativar a aba na volta do POST.
     */
    private function voltarParaResponsaveis(?int $objetoId): Response
    {
        if ($objetoId === null) {
            return $this->redirectToRoute('cobranca_caso_index');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId, 'aba' => 'responsaveis']);
    }
}
