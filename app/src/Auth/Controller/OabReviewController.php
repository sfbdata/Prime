<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\OabReviewOutput;
use App\Auth\Enum\StatusOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\UseCase\ConfirmarInscricaoOabUseCase;
use App\Auth\UseCase\ReverterInscricaoOabUseCase;
use App\Auth\UseCase\VerificarInscricaoOabUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tela de revisão de OAB do super-admin (platform-level, fora de escritório). Lista as inscrições na OAB
 * (principal e suplementares; cross-tenant, a inscrição é da pessoa) e permite confirmar/reverter/re-verificar
 * cada uma pelo id da inscrição (spec docs/specs/oab-revisao-por-inscricao.md). Os UseCases mantêm o espelho
 * User.oab* quando a inscrição é a principal; as mudanças são auditadas automaticamente (InscricaoOab e User são
 * `Auditavel` → `AuditLogSubscriber` no flush). Enquanto a verificação automática está dormente, "re-verificar"
 * devolve `nao_verificada`.
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
        private readonly InscricaoOabRepository $inscricoes,
        private readonly ConfirmarInscricaoOabUseCase $confirmar,
        private readonly ReverterInscricaoOabUseCase $reverter,
        private readonly VerificarInscricaoOabUseCase $verificar,
        private readonly TranslatorInterface $tradutor,
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
        $total    = $this->inscricoes->contarParaRevisao($statuses, $busca);

        $inscricoes = $this->inscricoes->paraRevisao($statuses, $busca, $pagina, self::POR_PAGINA);
        $itens      = array_map(OabReviewOutput::fromInscricao(...), $inscricoes);

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
        $this->validarCsrf($request, 'oab_confirmar_' . $id);
        if (!$this->conferirOQueFoiVisto($id, $request)) {
            return $this->redirectParaLista($request);
        }
        $this->executarOu404(fn () => $this->confirmar->executar($id));
        $this->addFlash('success', $this->tradutor->trans('plataforma.flash.oab_confirmada'));

        return $this->redirectParaLista($request);
    }

    #[Route('/{id}/reverter', name: 'admin_platform_oab_reverter', methods: ['POST'])]
    public function reverterAcao(int $id, Request $request): Response
    {
        $this->validarCsrf($request, 'oab_reverter_' . $id);
        $destino = StatusOab::tryFrom((string) $request->request->get('destino'));

        if ($destino === null) {
            $this->addFlash('danger', $this->tradutor->trans('plataforma.flash.oab_destino_invalido'));

            return $this->redirectParaLista($request);
        }
        if (!$this->conferirOQueFoiVisto($id, $request)) {
            return $this->redirectParaLista($request);
        }

        try {
            $this->executarOu404(fn () => $this->reverter->executar($id, $destino));
            $this->addFlash('success', $this->tradutor->trans('plataforma.flash.oab_revertida'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectParaLista($request);
    }

    #[Route('/{id}/reverificar', name: 'admin_platform_oab_reverificar', methods: ['POST'])]
    public function reverificarAcao(int $id, Request $request): Response
    {
        $this->validarCsrf($request, 'oab_reverificar_' . $id);
        $inscricao = $this->executarOu404(fn () => $this->verificar->executar($id));

        if ($inscricao->getStatus() === StatusOab::NaoVerificada) {
            $this->addFlash('info', $this->tradutor->trans('plataforma.flash.oab_verificada_sem_automatico'));
        } else {
            $this->addFlash('info', $this->tradutor->trans('plataforma.flash.oab_verificada'));
        }

        return $this->redirectParaLista($request);
    }

    private function validarCsrf(Request $request, string $csrfId): void
    {
        if (!$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    /**
     * Confirmar e reverter valem para o número e a UF que o Master viu na fila (o form os envia). Se o titular trocou
     * a inscrição depois (`InscricaoOab::alterar` mantém o id), recusa com aviso em vez de agir sobre o que não foi
     * visto. Inscrição inexistente: 404.
     */
    private function conferirOQueFoiVisto(int $id, Request $request): bool
    {
        $inscricao = $this->inscricoes->find($id) ?? throw $this->createNotFoundException('Inscrição não encontrada.');
        $numero    = (string) $request->request->get('numero');
        $uf        = (string) $request->request->get('uf');
        if ($numero === $inscricao->getNumero() && $uf === $inscricao->getUf()) {
            return true;
        }
        $this->addFlash('danger', $this->tradutor->trans('plataforma.flash.oab_inscricao_mudou'));

        return false;
    }

    /**
     * Os UseCases lançam `DomainException` para inscrição inexistente: vira 404.
     *
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    private function executarOu404(callable $acao): mixed
    {
        try {
            return $acao();
        } catch (\DomainException $e) {
            throw $this->createNotFoundException('Inscrição não encontrada.', $e);
        }
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
