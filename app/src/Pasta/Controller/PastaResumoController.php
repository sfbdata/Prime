<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\DTO\PastaPendenciasOutput;
use App\Pasta\DTO\PastaPushOutput;
use App\Pasta\DTO\PastaResumoImpressaoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\Service\ConferenciaDeAnexosDoChecklist;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Imprimir resumo" do menu ⋮ da pasta (desenho 1.2.3, `imprimirResumo`): uma folha A4 com os
 * dados da pasta, clientes, processos, pendências, metas abertas, últimas movimentações do Push
 * e o financeiro resumido. A página abre em aba nova e chama `window.print()` sozinha; "Baixar
 * PDF" é o "Salvar como PDF" do navegador — nenhum gerador de PDF novo.
 *
 * Só leitura. O guarda é o MESMO da tela da pasta, em duas camadas explícitas:
 *   1. a pasta é do escritório da sessão — o resolver de entidade busca por PK e o TenantFilter
 *      não se aplica a `find()`, então a conferência de dono não pode ficar implícita (404);
 *   2. o usuário pode VER esta pasta (`resources.pasta.view`, via `canAccessResource`) — 403.
 *
 * O Financeiro segue a regra da aba Financeiro da tela: hoje quem vê a pasta vê o financeiro
 * (não existe permissão própria; `modules.financeiro.view` é "futuro" no catálogo e não tem
 * tela). O interruptor existe aqui, num lugar só, para o dia em que existir.
 */
#[Route('/pasta')]
final class PastaResumoController extends AbstractController
{
    /** O mesmo teto que a aba Push usa: as pendências do Push contam sobre esse conjunto. */
    private const PUSH_LIMITE = 100;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PublicacaoDjenRepository $publicacaoDjenRepository,
        private readonly PastaPagamentoRepository $pastaPagamentoRepository,
        private readonly PastaChecklistItemRepository $checklistRepository,
    ) {
    }

    #[Route(
        '/{id}/resumo/imprimir',
        name: 'pasta_resumo_imprimir',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function imprimir(Pasta $pasta): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem permissão para ver esta pasta.');
        }

        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }
        $push = PastaPushOutput::montar(
            $this->publicacaoDjenRepository->listarItensPorNumerosDoTenant($tenant, $numeros, self::PUSH_LIMITE),
            $numeros,
            self::PUSH_LIMITE,
        );

        $pagamentos = $this->pastaPagamentoRepository->findByPasta($pasta, $tenant);

        // A mesma regra da pasta_show: item do checklist marcado sem arquivo correspondente
        // acende a pendência de Documentos também na folha impressa.
        $conferenciaChecklist = ConferenciaDeAnexosDoChecklist::daPasta(
            $this->checklistRepository->findByPasta($pasta, $tenant),
            $pasta->getDocumentos(),
            $tenant,
        );

        $resumo = PastaResumoImpressaoOutput::montar(
            $pasta,
            PastaPendenciasOutput::montar($pasta, $push->naoLidas, $pagamentos, itensMarcadosSemAnexo: $conferenciaChecklist->totalMarcadosSemAnexo()),
            $push->itens,
            $pagamentos,
            incluirFinanceiro: $this->podeVerFinanceiro(),
        );

        return $this->render('pasta/resumo_impressao.html.twig', [
            'resumo'      => $resumo,
            'pastaId'     => $pasta->getId(),
            'impressoEm'  => new \DateTimeImmutable(),
            'impressoPor' => $usuario->getFullName(),
        ]);
    }

    /**
     * Mesma regra da aba Financeiro de `pasta_show`: passou pelo guarda de leitura, vê.
     * Quando o catálogo ganhar uma permissão de financeiro com tela, ela entra AQUI.
     */
    private function podeVerFinanceiro(): bool
    {
        return true;
    }
}
