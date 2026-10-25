<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\DTO\FotoDaPastaInput;
use App\Pasta\DTO\FotoDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\FotoDaPastaInvalidaException;
use App\Pasta\Exception\FotoDaPastaRecusadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\FotoDoCabecalhoDaPasta;
use App\Pasta\UseCase\RemoverFotoDaPastaUseCase;
use App\Pasta\UseCase\TrocarFotoDaPastaUseCase;
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
 * Foto do cabeçalho da pasta SEM cliente cadastrado (desenho 02 VS 18.10.26, L1255, L472-505 e L3853-3884): o molde é
 * o `FotoDoClienteController`. Trocar e remover vêm da pasta (`#[PastaPorId]`: a lápide e o cadeado recusam pela
 * rota) e respondem JSON `{ok, url, origem}`, a foto que o círculo passa a mostrar ({@see FotoDoCabecalhoDaPasta}).
 * A entrega é inline, para quem VÊ a pasta no escritório da sessão, e só do nome que a pasta guarda. Escritório
 * errado ou nome que não é o dela: 404.
 */
final class FotoDaPastaController extends AbstractController
{
    public static function idDoToken(int $pastaId): string
    {
        return 'pasta_foto_' . $pastaId;
    }

    public function __construct(
        private readonly TrocarFotoDaPastaUseCase $trocar,
        private readonly RemoverFotoDaPastaUseCase $remover,
        private readonly PastaRepository $pastas,
        private readonly PermissionChecker $permissoes,
        private readonly TenantContext $tenantContext,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
        private readonly FotoDoCabecalhoDaPasta $fotoDoCabecalho,
    ) {
    }

    #[Route('/pasta/{id}/foto', name: 'pasta_foto_trocar', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[PastaPorId('id')]
    public function trocar(int $id, Request $request): JsonResponse
    {
        $arquivo = $request->files->get('foto');

        return $this->executar($request, $id, $this->trocar->executar(...), $arquivo instanceof UploadedFile ? $arquivo : null);
    }

    #[Route('/pasta/{id}/foto/remover', name: 'pasta_foto_remover', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[PastaPorId('id')]
    public function remover(int $id, Request $request): JsonResponse
    {
        return $this->executar($request, $id, $this->remover->executar(...));
    }

    #[Route('/pasta/{id}/foto/{nome}', name: 'pasta_foto', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[PastaPorId('id')]
    public function entregar(int $id, string $nome): Response
    {
        // Nome com caminho é recusado antes de qualquer acesso ao banco ou ao disco.
        if (basename($nome) !== $nome) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }
        $pasta = $this->pastaVisivel($id);
        if ($pasta === null || $pasta->getFoto() !== $nome) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }

        try {
            $chave = ChavesDePasta::foto($pasta);
        } catch (ChaveDeArquivoInvalida) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }
        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }

        return $this->entrega->resposta($chave, $nome, inline: true);
    }

    /** @param \Closure(FotoDaPastaInput): FotoDaPastaOutput $acao */
    private function executar(Request $request, int $pastaId, \Closure $acao, ?UploadedFile $arquivo = null): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::idDoToken($pastaId), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $acao(new FotoDaPastaInput($pastaId, $usuario, $tenant, $arquivo));
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException|SemPermissaoParaEditarPastaException|FotoDaPastaRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (FotoDaPastaInvalidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pasta = $this->pastas->find($pastaId);
        \assert($pasta instanceof Pasta);

        return $this->json(['ok' => true] + $this->fotoDoCabecalho->para($pasta, $usuario, $tenant));
    }

    private function pastaVisivel(int $id): ?Pasta
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $pasta = $tenant !== null ? $this->pastas->find($id) : null;
        if ($tenant === null || !$pasta instanceof Pasta || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            return null;
        }
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_VIEW) ? $pasta : null;
    }
}
