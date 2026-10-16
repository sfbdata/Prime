<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\GerenciarReusDaPastaUseCase;
use App\Service\Tenant\TenantContext;
use App\Twig\DocumentoBrExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aba Réu do Cadastro (E12h): adicionar, subir e remover réus da pasta. Cada resposta traz a lista inteira, na ordem,
 * para a janela redesenhar. Escritório conferido antes de tudo (404); CSRF por pasta.
 */
#[Route('/pasta')]
final class PastaReuController extends AbstractController
{
    public static function idDoToken(int $pastaId): string
    {
        return 'pasta_reus_' . $pastaId;
    }

    public function __construct(
        private readonly GerenciarReusDaPastaUseCase $reus,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/{id}/reus', name: 'pasta_reu_adicionar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adicionar(Pasta $pasta, Request $request): JsonResponse
    {
        return $this->executar($pasta, $request, fn (User $u, $t) => $this->reus->adicionar(
            $pasta, $u, $t, (string) $request->request->get('nome', ''), (string) $request->request->get('documento', ''),
        ));
    }

    #[Route('/{id}/reus/{reuId}/subir', name: 'pasta_reu_subir', requirements: ['id' => '\d+', 'reuId' => '\d+'], methods: ['POST'])]
    public function subir(Pasta $pasta, int $reuId, Request $request): JsonResponse
    {
        return $this->executar($pasta, $request, fn (User $u, $t) => $this->reus->subir($pasta, $u, $t, $reuId));
    }

    #[Route('/{id}/reus/{reuId}/remover', name: 'pasta_reu_remover', requirements: ['id' => '\d+', 'reuId' => '\d+'], methods: ['POST'])]
    public function remover(Pasta $pasta, int $reuId, Request $request): JsonResponse
    {
        return $this->executar($pasta, $request, fn (User $u, $t) => $this->reus->remover($pasta, $u, $t, $reuId));
    }

    /** @param callable(User, \App\Entity\Tenant\Tenant): list<PastaReu> $acao */
    private function executar(Pasta $pasta, Request $request, callable $acao): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid(self::idDoToken((int) $pasta->getId()), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $lista = $acao($usuario, $tenant);
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'reus' => self::paraTela($lista)]);
    }

    /**
     * @param list<PastaReu> $lista
     *
     * @return list<array{id: int, nome: string, documento: string}>
     */
    public static function paraTela(array $lista): array
    {
        $doc = new DocumentoBrExtension();

        return array_map(static fn (PastaReu $r): array => [
            'id' => (int) $r->getId(),
            'nome' => $r->getNome(),
            'documento' => $r->getDocumento() !== null ? $doc->documentoBrRotulo($r->getDocumento()) . ' ' . $doc->documentoBr($r->getDocumento()) : 'Documento não informado',
        ], $lista);
    }
}
