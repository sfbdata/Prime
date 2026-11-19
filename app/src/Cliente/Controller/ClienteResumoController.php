<?php

declare(strict_types=1);

namespace App\Cliente\Controller;

use App\Cliente\DTO\AtualizarContatoDoClienteInput;
use App\Cliente\DTO\ClienteResumoOutput;
use App\Cliente\DTO\LeituraDoClienteOutput;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\ContatoInvalidoException;
use App\Cliente\Exception\SemPermissaoParaVerClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\LeituraDoCliente;
use App\Cliente\Service\QualificacaoDoCliente;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase;
use App\Cliente\UseCase\LerClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fragmento HTML da janela "Detalhes do cliente" da tela da pasta (desenho 1.2.3,
 * dc L.779-836): nome, documento, contatos, "cliente desde" e as pastas do mesmo
 * cliente. Os contatos (3 slots fixos) se editam ali mesmo, pelo POST
 * `cliente_contatos`, que devolve a janela re-renderizada.
 *
 * A leitura (guardas 1 e 2, e as pastas da guarda 3) mora no {@see LerClienteUseCase}, o mesmo da ferramenta
 * `ler_clientes` do conector de I.A.
 *
 * Guardas, nesta ordem:
 *  1. o cliente é do escritório atual — o `find()` passa pelo TenantFilter, e a
 *     posse é conferida de novo aqui (outro tenant = 404, nunca 403: 403 confirmaria
 *     que o id existe);
 *  2. o usuário pode VER o cliente (`canAccessResource('cliente', view)`, a mesma
 *     regra da ficha `cliente_show`) — a janela mostra os mesmos dados que ela;
 *  3. cada pasta da lista passa por `canAccessResource('pasta', view)`, a mesma
 *     regra que abre a pasta. O tenant sozinho NÃO basta: um colaborador com acesso
 *     a uma pasta isolada não pode descobrir, por aqui, número e ação das outras
 *     pastas do mesmo cliente.
 */
final class ClienteResumoController extends AbstractController
{
    public function __construct(
        private readonly ClienteRepository $clienteRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly QualificacaoDoCliente $qualificacao,
        private readonly AtualizarContatoDoClienteUseCase $atualizarContato,
        private readonly LerClienteUseCase $lerCliente,
        private readonly LeituraDoCliente $leitura,
    ) {}

    #[Route('/clientes/{id}/resumo', name: 'cliente_resumo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function resumo(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        try {
            $lido = $this->lerCliente->executar($id, $usuario, $tenant);
        } catch (ClienteNaoEncontradoException) {
            throw $this->createNotFoundException('Cliente não encontrado');
        } catch (SemPermissaoParaVerClienteException) {
            throw $this->createAccessDeniedException('Sem acesso a este cliente.');
        }

        return $this->renderJanela($lido, $request->query->get('pasta'));
    }

    /**
     * Edição inline de UM contato (desenho dc L.5748-5786: lápis, Enter salva,
     * "+ Telefone"/"+ E-mail"). Corpo: `campo` (celular|fixo|email), `valor`
     * (vazio = remover telefone), `pasta` (para marcar a atual) e `_token`.
     *
     * Guardas, nesta ordem: tenant (404), permissão de EDITAR o cliente — a
     * mesma de `cliente_edit` — (403), CSRF (403), valor (422, JSON `erro`).
     * Sucesso: 200 com o fragmento da janela já com o valor novo, pela {@see LeituraDoCliente} sem a guarda de ver
     * (quem edita pode não ter a permissão de ver).
     */
    #[Route('/clientes/{id}/contatos', name: 'cliente_contatos', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function contatos(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        $cliente = $this->clienteRepository->find($id);
        if ($cliente === null || $cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_EDIT)) {
            throw $this->createAccessDeniedException('Sem permissão para editar este cliente.');
        }

        if (!$this->isCsrfTokenValid('cliente_contatos_' . $id, (string) $request->request->get('_token'))) {
            return new JsonResponse(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->atualizarContato->executar(
                new AtualizarContatoDoClienteInput(
                    $id,
                    (string) $request->request->get('campo', ''),
                    (string) $request->request->get('valor', ''),
                ),
                $tenant,
                $usuario,
            );
        } catch (ClienteNaoEncontradoException) {
            throw $this->createNotFoundException('Cliente não encontrado');
        } catch (ContatoInvalidoException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderJanela($this->leitura->montar($cliente, $usuario, $tenant), $request->request->get('pasta'));
    }

    private function renderJanela(LeituraDoClienteOutput $lido, mixed $pastaAtual): Response
    {
        $pastaAtualId = \is_string($pastaAtual) && ctype_digit($pastaAtual) ? (int) $pastaAtual : null;

        $resumo = ClienteResumoOutput::montar(
            $lido->cliente,
            $lido->pastasVisiveis,
            $pastaAtualId,
            array_column($lido->pendencias, 'texto'),
            $this->qualificacao->de($lido->cliente),
            $lido->podeEditar,
        );

        return $this->render('cliente/_resumo.html.twig', ['resumo' => $resumo]);
    }
}
