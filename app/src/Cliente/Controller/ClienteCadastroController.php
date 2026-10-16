<?php

declare(strict_types=1);

namespace App\Cliente\Controller;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\CadastroInvalidoException;
use App\Cliente\Exception\DocumentoJaCadastradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Cliente\Service\PendenciasDoCadastro;
use App\Cliente\Service\QualificacaoDoCliente;
use App\Cliente\UseCase\SalvarCadastroDoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\DigitoVerificador;
use App\Twig\DocumentoBrExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Janela "Cadastro e qualificação do cliente" do botão Cadastro da pasta (desenho 1.2.3, `cadAbrir`; E12f).
 *
 * GET devolve o fragmento da janela (ver o cliente basta; sem EDITAR os campos vêm travados). POST salva pelo
 * {@see SalvarCadastroDoClienteUseCase} e devolve JSON. A conferência de documento serve o aviso "Este CPF já possui
 * cadastro no sistema." ao sair do campo. Todas conferem o escritório antes de qualquer coisa (404).
 */
final class ClienteCadastroController extends AbstractController
{
    public static function idDoToken(int $clienteId): string
    {
        return 'cliente_cadastro_' . $clienteId;
    }

    public function __construct(
        private readonly ClienteRepository $clienteRepository,
        private readonly ClientePFRepository $pfRepository,
        private readonly ClientePJRepository $pjRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PendenciasDoCadastro $pendencias,
        private readonly QualificacaoDoCliente $qualificacao,
        private readonly SalvarCadastroDoClienteUseCase $salvar,
    ) {}

    #[Route('/clientes/{id}/cadastro', name: 'cliente_cadastro', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function janela(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregar($id);
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem acesso a este cliente.');
        }

        $pasta = $this->pastaDoContexto($request->query->get('pasta'), $tenant);

        return $this->render('cliente/_cadastro.html.twig', [
            'cliente'      => $cliente,
            'pf'           => $cliente instanceof ClientePF,
            'pendencias'   => $this->pendencias->porAba($cliente),
            // Lista (não objeto) para o JS somar às que ele recalcula: as de documento não dependem do formulário.
            'pendenciasDocs' => array_values(array_filter($this->pendencias->porAba($cliente), static fn (array $p): bool => $p['aba'] === 'docs')),
            'qualificacao' => $this->qualificacao->de($cliente),
            'podeEditar'   => $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_EDIT),
            'pasta'        => $pasta,
            'podeVincular' => $pasta !== null && $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT),
            'estadosCivis' => SalvarCadastroDoClienteUseCase::ESTADOS_CIVIS,
        ]);
    }

    #[Route('/clientes/{id}/cadastro', name: 'cliente_cadastro_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvar(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregar($id);
        if (!$this->isCsrfTokenValid(self::idDoToken($id), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }

        $campos = $request->request->all();
        unset($campos['_token'], $campos['pasta']);
        try {
            $alterados = $this->salvar->executar($cliente, array_map('strval', array_filter($campos, 'is_scalar')), $usuario, $tenant,
                $this->pastaDoContexto($request->request->get('pasta'), $tenant));
        } catch (SemPermissaoParaEditarClienteException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (DocumentoJaCadastradoException $e) {
            // Só mostra quem é o outro cadastro a quem pode vê-lo (mesma regra da conferência ao sair do campo).
            $visivel = $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $e->existente->getId(), AccessRequest::ACTION_VIEW);

            return $this->json(['erro' => $e->getMessage(), 'existente' => $visivel ? $this->resumoDe($e->existente) : null], Response::HTTP_CONFLICT);
        } catch (CadastroInvalidoException $e) {
            return $this->json(['erro' => $e->getMessage(), 'aba' => $e->aba], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pendencias = $this->pendencias->porAba($cliente);

        return $this->json([
            'ok'           => true,
            'mudou'        => $alterados !== [],
            'alterados'    => $alterados,
            'mensagem'     => $pendencias === [] ? 'Cadastro salvo.' : 'Cadastro salvo. Ainda há pendências.',
            'pendencias'   => $pendencias,
            'qualificacao' => $this->qualificacao->de($cliente),
        ]);
    }

    /** Aviso ao sair do campo CPF/CNPJ: o documento já é de outro cadastro do escritório? */
    #[Route('/clientes/{id}/cadastro/documento', name: 'cliente_cadastro_documento', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documento(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregar($id);
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem acesso a este cliente.');
        }

        $digitos = DigitoVerificador::digitos((string) $request->query->get('doc', ''));
        $outro = match (true) {
            strlen($digitos) === 11 => $this->pfRepository->findOneByCpfDoTenant($digitos, $tenant),
            strlen($digitos) === 14 => $this->pjRepository->findOneByCnpjDoTenant($digitos, $tenant),
            default => null,
        };
        $visivel = $outro !== null && $outro->getId() !== $cliente->getId()
            && $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $outro->getId(), AccessRequest::ACTION_VIEW);

        return $this->json(['existente' => $visivel ? $this->resumoDe($outro) : null]);
    }

    /** @return array{0: Cliente, 1: Tenant} */
    private function carregar(int $id): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $cliente = $tenant !== null ? $this->clienteRepository->find($id) : null;
        if ($tenant === null || $cliente === null || $cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        return [$cliente, $tenant];
    }

    private function pastaDoContexto(mixed $pastaId, Tenant $tenant): ?Pasta
    {
        if (!\is_string($pastaId) || !ctype_digit($pastaId)) {
            return null;
        }
        $pasta = $this->pastaRepository->find((int) $pastaId);

        return $pasta !== null && $pasta->getTenant()?->getId() === $tenant->getId() ? $pasta : null;
    }

    /** @return array{id: int, nome: string, documento: string, completo: bool} */
    private function resumoDe(Cliente $c): array
    {
        $doc = $c instanceof ClientePF ? $c->getCpf() : (method_exists($c, 'getCnpj') ? $c->getCnpj() : '');

        return [
            'id'        => (int) $c->getId(),
            'nome'      => $c->getNomeExibicao(),
            'documento' => (new DocumentoBrExtension())->documentoBrRotulo($doc),
            'completo'  => $this->pendencias->estaCompleto($c),
        ];
    }
}
