<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\OabReviewOutput;
use App\Auth\Enum\StatusOab;
use App\Auth\UseCase\ConfirmarOabManualmenteUseCase;
use App\Auth\UseCase\ReverterOabUseCase;
use App\Auth\UseCase\VerificarOabUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tela de revisão de OAB do super-admin (platform-level, fora de escritório). Lista usuários com OAB
 * (cross-tenant, identidade global), permite confirmar/reverter/re-verificar. As mudanças de `oabStatus`
 * são auditadas automaticamente (User é `Auditavel` → `AuditLogSubscriber` no flush). Enquanto a
 * verificação automática está dormente, "re-verificar" é no-op (mantém `nao_verificada`).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/platform/oab')]
final class OabReviewController extends AbstractController
{
    private const POR_PAGINA = 20;

    /** @var array<string, list<StatusOab>> */
    private const FILTROS = [
        'pendentes'      => [StatusOab::NaoVerificada, StatusOab::Divergente],
        'nao_verificada' => [StatusOab::NaoVerificada],
        'divergente'     => [StatusOab::Divergente],
        'confirmada'     => [StatusOab::Confirmada],
        'todas'          => [],
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ConfirmarOabManualmenteUseCase $confirmar,
        private readonly ReverterOabUseCase $reverter,
        private readonly VerificarOabUseCase $verificar,
    ) {
    }

    #[Route('', name: 'admin_platform_oab_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statusAtual = (string) $request->query->get('status', 'pendentes');
        if (!array_key_exists($statusAtual, self::FILTROS)) {
            $statusAtual = 'pendentes';
        }

        $busca  = trim((string) $request->query->get('q', ''));
        $pagina = max(1, $request->query->getInt('pagina', 1));

        $statuses = self::FILTROS[$statusAtual];
        $total    = $this->userRepository->contarParaRevisaoOab($statuses, $busca);
        $offset   = ($pagina - 1) * self::POR_PAGINA;

        $usuarios = $this->userRepository->buscarParaRevisaoOab($statuses, $busca, $offset, self::POR_PAGINA);
        $itens    = array_map(OabReviewOutput::fromUser(...), $usuarios);

        return $this->render('admin/platform/oab.html.twig', [
            'itens'        => $itens,
            'statusAtual'  => $statusAtual,
            'busca'        => $busca,
            'pagina'       => $pagina,
            'totalPaginas' => (int) max(1, (int) ceil($total / self::POR_PAGINA)),
            'total'        => $total,
        ]);
    }

    #[Route('/{id}/confirmar', name: 'admin_platform_oab_confirmar', methods: ['POST'])]
    public function confirmarAcao(int $id, Request $request): Response
    {
        $user = $this->resolverUsuario($id, $request, 'oab_confirmar_' . $id);
        $this->confirmar->executar($user);
        $this->addFlash('sucesso', 'OAB confirmada.');

        return $this->redirectParaLista($request);
    }

    #[Route('/{id}/reverter', name: 'admin_platform_oab_reverter', methods: ['POST'])]
    public function reverterAcao(int $id, Request $request): Response
    {
        $user    = $this->resolverUsuario($id, $request, 'oab_reverter_' . $id);
        $destino = StatusOab::tryFrom((string) $request->request->get('destino'));

        if ($destino === null) {
            $this->addFlash('erro', 'Destino inválido.');

            return $this->redirectParaLista($request);
        }

        try {
            $this->reverter->executar($user, $destino);
            $this->addFlash('sucesso', 'OAB revertida.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectParaLista($request);
    }

    #[Route('/{id}/reverificar', name: 'admin_platform_oab_reverificar', methods: ['POST'])]
    public function reverificarAcao(int $id, Request $request): Response
    {
        $user      = $this->resolverUsuario($id, $request, 'oab_reverificar_' . $id);
        $resultado = $this->verificar->executar($user);

        if ($resultado === null) {
            $this->addFlash('erro', 'Usuário sem número de OAB para verificar.');
        } else {
            $this->addFlash('info', 'Verificação executada. Backend automático indisponível — status permanece não verificado.');
        }

        return $this->redirectParaLista($request);
    }

    private function resolverUsuario(int $id, Request $request, string $csrfId): User
    {
        if (!$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            throw $this->createNotFoundException('Usuário não encontrado.');
        }

        return $user;
    }

    private function redirectParaLista(Request $request): Response
    {
        return $this->redirectToRoute('admin_platform_oab_index', [
            'status' => $request->query->get('status', 'pendentes'),
            'q'      => $request->query->get('q', ''),
            'pagina' => $request->query->get('pagina', 1),
        ]);
    }
}
