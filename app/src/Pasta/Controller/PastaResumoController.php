<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\DTO\PastaExtratoImpressaoOutput;
use App\Pasta\DTO\PastaImpressaoOpcoes;
use App\Pasta\DTO\PastaPendenciasOutput;
use App\Pasta\DTO\PastaPushOutput;
use App\Pasta\DTO\PastaResumoImpressaoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\Service\ConferenciaDeAnexosDoChecklist;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A folha impressa da pasta (desenho 1.2.3: `imprimirResumo`, `impMontar`, `impVals`).
 *
 * Uma rota de folha, duas formas de entrega:
 *  - página própria (`pasta_resumo_imprimir`): abre em aba nova e chama `window.print()` — é o
 *    caminho sem JS do item "Imprimir resumo" do ⋮;
 *  - fragmento (`?fragmento=1`): só a folha, que o `pasta-impressao.js` põe na pré-visualização
 *    da janela "Imprimir" da própria tela da pasta e imprime por iframe oculto.
 *
 * As duas obedecem às MESMAS opções (`PastaImpressaoOpcoes`): seção (resumo ou só a moldura, para
 * o registro e o processo que a tela monta), cabeçalho do escritório, dados da pasta, autor,
 * financeiro e tamanho do texto.
 *
 * Só leitura. O guarda é o MESMO da tela da pasta, em duas camadas explícitas:
 *   1. a pasta é do escritório da sessão — o resolver de entidade busca por PK e o TenantFilter
 *      não se aplica a `find()`, então a conferência de dono não pode ficar implícita (404);
 *   2. o usuário pode VER esta pasta (`resources.pasta.view`, via `canAccessResource`) — 403.
 *
 * O Financeiro (seção resumida e bloco de lançamentos) só sai para quem o vê, pela regra única de
 * `VisibilidadeDoFinanceiroDaPasta` — a mesma da timeline e da análise.
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
        private readonly VisibilidadeDoFinanceiroDaPasta $visibilidadeDoFinanceiro,
    ) {
    }

    #[Route(
        '/{id}/resumo/imprimir',
        name: 'pasta_resumo_imprimir',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function imprimir(Pasta $pasta, Request $request): Response
    {
        $fragmento = $request->query->getBoolean('fragmento');
        $opcoes    = PastaImpressaoOpcoes::daQuery($request->query);
        // A moldura (corpo vazio) só tem sentido dentro da janela da tela.
        $valida    = $opcoes->secao !== null && ($fragmento || $opcoes->secao !== PastaImpressaoOpcoes::SECAO_MOLDURA);

        $contexto = $this->contexto($pasta, $opcoes, $valida);

        $resposta = $this->render('pasta/resumo_impressao.html.twig', $contexto + [
            'modo' => $fragmento ? 'fragmento' : 'pagina',
        ]);
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }

    /**
     * Tudo o que o template precisa, com os guardas na frente. `$valida` falso (seção
     * desconhecida) vira 404 DEPOIS do guarda de dono — a pasta de outro escritório não pode
     * responder diferente conforme a query.
     *
     * @return array<string, mixed>
     */
    private function contexto(Pasta $pasta, PastaImpressaoOpcoes $opcoes, bool $valida): array
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->guarda($pasta, $usuario);

        if (!$valida) {
            throw $this->createNotFoundException('Folha de impressão desconhecida.');
        }

        $veFinanceiro = $this->visibilidadeDoFinanceiro->podeVer($usuario, $tenant, $pasta);

        $pagamentos = $this->pastaPagamentoRepository->findByPasta($pasta, $tenant);
        $resumo     = $opcoes->secao === PastaImpressaoOpcoes::SECAO_RESUMO
            ? $this->resumoCompleto($pasta, $tenant, $pagamentos, $veFinanceiro)
            // A moldura só usa a faixa Pasta/Cliente/Processo: sem Push nem checklist.
            : PastaResumoImpressaoOutput::montar(
                $pasta,
                PastaPendenciasOutput::montar($pasta, 0, $pagamentos),
                [],
                $pagamentos,
                incluirFinanceiro: false,
            );

        $titulo = match ($opcoes->secao) {
            PastaImpressaoOpcoes::SECAO_RESUMO => 'Resumo da pasta ' . $resumo->numero,
            default                            => '',
        };

        return [
            'resumo'       => $resumo,
            'opcoes'       => $opcoes,
            'titulo'       => $titulo,
            // `null` = o bloco não existe na folha (e a opção some da janela).
            'extrato'      => $veFinanceiro ? PastaExtratoImpressaoOutput::montar($pasta, $pagamentos) : null,
            'pastaId'      => $pasta->getId(),
            'impressoEm'   => new \DateTimeImmutable(),
            'impressoPor'  => $usuario->getFullName(),
        ];
    }

    private function guarda(Pasta $pasta, User $usuario): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem permissão para ver esta pasta.');
        }

        return $tenant;
    }

    /**
     * @param PastaPagamento[] $pagamentos
     */
    private function resumoCompleto(Pasta $pasta, Tenant $tenant, array $pagamentos, bool $veFinanceiro): PastaResumoImpressaoOutput
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }
        $push = PastaPushOutput::montar(
            $this->publicacaoDjenRepository->listarItensPorNumerosDoTenant($tenant, $numeros, self::PUSH_LIMITE),
            $numeros,
            self::PUSH_LIMITE,
        );

        // A mesma regra da pasta_show: item do checklist marcado sem arquivo correspondente
        // acende a pendência de Documentos também na folha impressa.
        $conferenciaChecklist = ConferenciaDeAnexosDoChecklist::daPasta(
            $this->checklistRepository->findByPasta($pasta, $tenant),
            $pasta->getDocumentos(),
            $tenant,
        );

        return PastaResumoImpressaoOutput::montar(
            $pasta,
            PastaPendenciasOutput::montar($pasta, $push->naoLidas, $pagamentos, itensMarcadosSemAnexo: $conferenciaChecklist->totalMarcadosSemAnexo()),
            $push->itens,
            $pagamentos,
            incluirFinanceiro: $veFinanceiro,
        );
    }
}
