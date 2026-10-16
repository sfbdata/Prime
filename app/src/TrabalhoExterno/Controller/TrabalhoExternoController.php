<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Service\Tenant\TenantContext;
use App\Shared\Http\EntregaDeArquivo;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PreviaDePerfil;
use App\TrabalhoExterno\UseCase\DetalharDemandaUseCase;
use App\TrabalhoExterno\UseCase\MontarPainelUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Módulo Trabalho Externo: a tela (uma página que o `trabalho-externo.js` desenha) e as leituras.
 *
 * Toda rota passa pelo perfil resolvido no servidor (`FabricaDeContexto`); sem perfil, a tela e o
 * link de documento mostram o aviso de acesso negado (403, página do módulo) e a API responde 403
 * em JSON. Demanda de outro escritório é 404.
 */
#[Route('/trabalho-externo')]
final class TrabalhoExternoController extends AbstractController
{
    use RespondeJsonTrait;

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly MontarPainelUseCase $montarPainel,
        private readonly DetalharDemandaUseCase $detalhar,
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
        private readonly TenantContext $tenantContext,
        private readonly PreviaDePerfil $previa,
    ) {
    }

    #[Route('', name: 'trabalho_externo_index', methods: ['GET'])]
    public function index(): Response
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->paginaSemAcesso();
        }

        return $this->render('trabalho_externo/index.html.twig', [
            'painel' => $this->montarPainel->executar($ctx),
        ]);
    }

    /** `?como=solicitante|equipe|convidado`: pré-visualização do Master (spec §9); para os demais, ignorado. */
    #[Route('/api/painel', name: 'trabalho_externo_painel', methods: ['GET'])]
    public function painel(Request $request): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $como = $this->previa->perfilPedido($ctx, $request->query->get('como'));

        return $this->responder(fn (): array => ['painel' => $como !== null
            ? $this->montarPainel->executarPrevia($ctx, $como)
            : $this->montarPainel->executar($ctx)]);
    }

    #[Route('/api/demandas/{codigo}', name: 'trabalho_externo_detalhe', methods: ['GET'], requirements: ['codigo' => 'TE-\d{1,9}'])]
    public function detalhe(Request $request, string $codigo): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $como = $this->previa->perfilPedido($ctx, $request->query->get('como'));

        return $this->responder(fn (): array => ['demanda' => $como !== null
            ? $this->detalhar->executarPrevia($ctx, $codigo, $como)
            : $this->detalhar->executar($ctx, $codigo)]);
    }

    #[Route('/demandas/{codigo}/documentos/{id}', name: 'trabalho_externo_documento', methods: ['GET'], requirements: ['codigo' => 'TE-\d{1,9}', 'id' => '\d+'])]
    public function documento(string $codigo, int $id): Response
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->paginaSemAcesso();
        }

        try {
            [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        } catch (DemandaNaoEncontrada) {
            throw $this->createNotFoundException();
        }
        if (!$this->politica->podeBaixar($ctx, $demanda)) {
            throw $this->createNotFoundException();
        }

        foreach ($demanda->getDocumentos() as $doc) {
            if ($doc->getId() === $id) {
                $chave = ChavesDeTrabalhoExterno::documento($doc);
                if (!$this->armazenamento->existe($chave)) {
                    throw $this->createNotFoundException('Arquivo não encontrado.');
                }

                return $this->entrega->resposta($chave, $doc->getNomeOriginal(), inline: true);
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * O aviso de acesso negado, na própria página do módulo. NÃO redireciona para "/": de lá a
     * pessoa ia ao Expediente, que pode estar fechado para ela também, e caía na página técnica
     * 403 do Symfony sem ver aviso nenhum.
     */
    private function paginaSemAcesso(): Response
    {
        return $this->render('trabalho_externo/sem_acesso.html.twig', [
            'semEscritorio' => $this->tenantContext->getCurrentTenant() === null,
        ], new Response('', Response::HTTP_FORBIDDEN));
    }
}
