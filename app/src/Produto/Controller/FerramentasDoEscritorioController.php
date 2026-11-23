<?php

declare(strict_types=1);

namespace App\Produto\Controller;

use App\Entity\Auth\User;
use App\Produto\Enum\Produto;
use App\Produto\Exception\FerramentaRecusadaException;
use App\Produto\UseCase\DesligarFerramentaUseCase;
use App\Produto\UseCase\LigarFerramentaUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Ferramentas do escritório" no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md, §9): o dono liga ou
 * desliga uma ferramenta do escritório da sessão. A rota é do núcleo (`app_profile`), então abre para qualquer
 * escritório; quem decide é o UseCase (só o dono, 403 antes de tudo).
 *
 * JSON. O corpo é `{ligar: bool}` e o token CSRF `perfil_ferramentas` vai no header `X-CSRF-Token`. Resposta
 * `{ok: true, ligada: bool}`; recusa `{erro}` com 403 (CSRF ou não é o dono), 404 (sem escritório na sessão ou
 * ferramenta que não existe) ou 422 (corpo inválido, travada pelo Master, assembleia em andamento).
 */
#[IsGranted('ROLE_USER')]
final class FerramentasDoEscritorioController extends AbstractController
{
    public const TOKEN = 'perfil_ferramentas';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LigarFerramentaUseCase $ligar,
        private readonly DesligarFerramentaUseCase $desligar,
    ) {
    }

    #[Route('/perfil/ferramentas/{produto}', name: 'app_profile_ferramenta', requirements: ['produto' => '[a-z]+'], methods: ['POST'])]
    public function alternar(string $produto, Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant) {
            return $this->recusa('Escritório não encontrado.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->recusa('Sua sessão expirou. Recarregue a página e tente de novo.', Response::HTTP_FORBIDDEN);
        }
        $ligar = self::corpo($request)['ligar'] ?? null;
        if (!is_bool($ligar)) {
            return $this->recusa('Informe se a ferramenta deve ser ligada ou desligada.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Valor fora da lista vira `completo`: o UseCase confere o dono primeiro (403) e só depois recusa (404).
            $useCase = $ligar ? $this->ligar : $this->desligar;
            $useCase->executar($this->usuario(), $tenant, Produto::tryFrom($produto) ?? Produto::Completo);
        } catch (FerramentaRecusadaException $e) {
            return $this->recusa($e->getMessage(), $e->status);
        }

        return $this->json(['ok' => true, 'ligada' => $ligar]);
    }

    private function recusa(string $mensagem, int $status): JsonResponse
    {
        return $this->json(['erro' => $mensagem], $status);
    }

    private function usuario(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }

    /** @return array<mixed> */
    private static function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
