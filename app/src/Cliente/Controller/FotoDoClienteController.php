<?php

declare(strict_types=1);

namespace App\Cliente\Controller;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\DTO\FotoDoClienteInput;
use App\Cliente\DTO\FotoDoClienteOutput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\FotoDoClienteInvalidaException;
use App\Cliente\Exception\FotoDoClienteRecusadaException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\UseCase\RemoverFotoDoClienteUseCase;
use App\Cliente\UseCase\TrocarFotoDoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Http\EntregaDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Foto do cliente no cabeçalho da pasta (desenho 02 VS 18.10.26, L1255, L472-505 e L3853-3884).
 *
 * Trocar e remover vêm da pasta (`#[PastaPorId]`: a lápide e o cadeado recusam pela rota) e respondem JSON
 * `{ok, url}`, no molde da foto de perfil. A entrega é inline, para quem VÊ o cliente no escritório da sessão, e só
 * do nome que o cliente guarda. Escritório errado, cliente fora da pasta ou nome que não é o dele: 404.
 */
final class FotoDoClienteController extends AbstractController
{
    public static function idDoToken(int $clienteId): string
    {
        return 'cliente_foto_' . $clienteId;
    }

    public function __construct(
        private readonly TrocarFotoDoClienteUseCase $trocar,
        private readonly RemoverFotoDoClienteUseCase $remover,
        private readonly ClienteRepository $clientes,
        private readonly PermissionChecker $permissoes,
        private readonly TenantContext $tenantContext,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
    ) {
    }

    #[Route('/pasta/{id}/clientes/{cliente}/foto', name: 'pasta_cliente_foto_trocar', requirements: ['id' => '\d+', 'cliente' => '\d+'], methods: ['POST'])]
    #[PastaPorId('id')]
    public function trocar(int $id, int $cliente, Request $request): JsonResponse
    {
        $arquivo = $request->files->get('foto');

        return $this->executar($request, $id, $cliente, $this->trocar->executar(...), $arquivo instanceof UploadedFile ? $arquivo : null);
    }

    #[Route('/pasta/{id}/clientes/{cliente}/foto/remover', name: 'pasta_cliente_foto_remover', requirements: ['id' => '\d+', 'cliente' => '\d+'], methods: ['POST'])]
    #[PastaPorId('id')]
    public function remover(int $id, int $cliente, Request $request): JsonResponse
    {
        return $this->executar($request, $id, $cliente, $this->remover->executar(...));
    }

    #[Route('/clientes/{id}/foto/{nome}', name: 'cliente_foto', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entregar(int $id, string $nome): Response
    {
        // Nome com caminho é recusado antes de qualquer acesso ao banco ou ao disco.
        if (basename($nome) !== $nome) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }
        $cliente = $this->clienteVisivel($id);
        if ($cliente === null || $cliente->getFoto() !== $nome) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }

        try {
            $chave = ChavesDeCliente::foto($cliente);
        } catch (ChaveDeArquivoInvalida) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }
        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }

        return $this->entrega->resposta($chave, $nome, inline: true);
    }

    /** @param \Closure(FotoDoClienteInput): FotoDoClienteOutput $acao */
    private function executar(Request $request, int $pastaId, int $clienteId, \Closure $acao, ?UploadedFile $arquivo = null): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::idDoToken($clienteId), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $foto = $acao(new FotoDoClienteInput($pastaId, $clienteId, $usuario, $tenant, $arquivo));
        } catch (PastaDeOutroEscritorioException|ClienteNaoEncontradoException) {
            return $this->json(['erro' => 'Cliente não encontrado nesta pasta.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException|SemPermissaoParaEditarClienteException|FotoDoClienteRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (FotoDoClienteInvalidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'url' => $this->urlDa($foto)]);
    }

    private function urlDa(FotoDoClienteOutput $foto): ?string
    {
        if ($foto->nome === null) {
            return null;
        }

        return $this->generateUrl('cliente_foto', [
            'id'   => $foto->clienteId,
            'nome' => $foto->nome,
            'v'    => $foto->atualizadaEm?->getTimestamp(),
        ]);
    }

    private function clienteVisivel(int $id): ?Cliente
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $cliente = $tenant !== null ? $this->clientes->find($id) : null;
        if ($tenant === null || !$cliente instanceof Cliente || $cliente->getTenant()?->getId() !== $tenant->getId()) {
            return null;
        }
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW) ? $cliente : null;
    }
}
