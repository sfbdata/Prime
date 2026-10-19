<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\UseCase\EnviarCertificadoDoBancoUseCase;
use App\Contribua\UseCase\PremioDoApoiadorUseCase;
use App\Contribua\UseCase\SalvarIntegracaoBancariaUseCase;
use App\Contribua\UseCase\TestarConexaoDoBancoUseCase;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Integração bancária do Master (desenho 08, L80-101) e a baixa do prêmio do apoiador (L192-203). Mesmo
 * protocolo das ações da página Contribua: CSRF `contribua` no campo `_token`, resposta `{ok, mensagem}` ou
 * `{ok: false, erro}`.
 */
#[IsGranted('ROLE_USER')]
#[Route('/contribua')]
final class IntegracaoBancariaController extends AbstractController
{
    public function __construct(
        private readonly SalvarIntegracaoBancariaUseCase $salvar,
        private readonly EnviarCertificadoDoBancoUseCase $enviarCertificado,
        private readonly TestarConexaoDoBancoUseCase $testar,
        private readonly PremioDoApoiadorUseCase $premio,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/integracao-bancaria/salvar', name: 'contribua_integracao_salvar', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function salvar(Request $request): JsonResponse
    {
        return $this->acao($request, fn (): array => ['mensagem' => $this->salvar->executar(
            $request->request->getString('ambiente'),
            $request->request->getString('clientId'),
            array_map(static fn (mixed $v): bool => '1' === (string) $v, $request->request->all('recursos')),
            $this->usuario(),
        )]);
    }

    #[Route('/integracao-bancaria/certificado', name: 'contribua_integracao_certificado', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function certificado(Request $request): JsonResponse
    {
        return $this->acao($request, function () use ($request): array {
            $arquivos = [];
            foreach ($request->files->all('arquivos') as $arquivo) {
                if ($arquivo instanceof UploadedFile && $arquivo->isValid()) {
                    $arquivos[$arquivo->getClientOriginalName()] = $arquivo->getContent();
                }
            }
            $nomes = $this->enviarCertificado->executar($arquivos, $this->usuario());

            return ['mensagem' => 'Certificado e chave guardados no cofre do servidor.', 'arquivos' => $nomes];
        });
    }

    #[Route('/integracao-bancaria/testar', name: 'contribua_integracao_testar', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function testar(Request $request): JsonResponse
    {
        return $this->acao($request, function (): array {
            $r = $this->testar->executar();

            return ['mensagem' => $r->mensagem, 'conectado' => $r->conectou];
        });
    }

    #[Route('/premio/{id}/visto', name: 'contribua_premio_visto', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function premioVisto(Request $request, int $id): JsonResponse
    {
        return $this->acao($request, function () use ($id): array {
            $escritorio = $this->tenantContext->getCurrentTenant() ?? throw new \DomainException('Escolha um escritório.');
            $this->premio->marcarVisto($id, (int) $escritorio->getId());

            return ['mensagem' => ''];
        });
    }

    /** @param callable(): array<string, mixed> $fazer */
    private function acao(Request $request, callable $fazer): JsonResponse
    {
        if (!$this->isCsrfTokenValid('contribua', $request->request->getString('_token'))) {
            return $this->json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], 403);
        }
        try {
            return $this->json(['ok' => true] + $fazer());
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()], 422);
        }
    }

    private function usuario(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }
}
