<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\MarcarAssinadoEmPapelUseCase;
use App\AssinaturaEletronica\UseCase\MontarFolhaParaImprimirUseCase;
use App\Shared\Service\GeradorDePdf;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Função PROVISÓRIA da Assinatura (até a etapa completa sair): "Baixar para imprimir" (PDF com espaço de assinatura à
 * mão) e "Marcar como assinado em papel" (o escritório confirma; o sistema não confere a assinatura). Fica separada do
 * resto da tela para sair inteira depois. Baixar e marcar: qualquer colaborador do escritório com o módulo; o Master só
 * onde tem vínculo (sem bypass entre escritórios). Documento de outro escritório: 404.
 */
final class AssinaturaEmPapelController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/assinatura/documentos/{id}/imprimir', name: 'assinatura_eletronica_imprimir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function imprimir(int $id, MontarFolhaParaImprimirUseCase $montar, GeradorDePdf $gerador, ClockInterface $relogio): Response
    {
        [, $tenant] = $this->acesso->quemPode() ?? throw $this->createAccessDeniedException();

        try {
            $folha = $montar->executar($tenant, $id);
        } catch (DocumentoNaoEncontrado $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        $html = $this->renderView('assinatura_eletronica/papel/folha_para_imprimir.html.twig', ['folha' => $folha, 'logo' => $gerador->logoEmbutido()]);

        return $gerador->resposta($gerador->gerar($html), $folha->nome, \DateTimeImmutable::createFromInterface($relogio->now()));
    }

    #[Route('/assinatura/documentos/{id}/assinado-em-papel', name: 'assinatura_eletronica_assinado_em_papel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function marcar(int $id, Request $request, MarcarAssinadoEmPapelUseCase $marcar): JsonResponse
    {
        $quem = $this->acesso->quemPodeMarcarEmPapel();
        if ($quem === null) {
            return $this->erro('assinatura_eletronica.erro.sem_permissao', Response::HTTP_FORBIDDEN);
        }
        $this->validarCsrfAjax($request);

        try {
            $marcar->executar($quem[0], $quem[1], $id);
        } catch (DocumentoNaoEncontrado) {
            return $this->erro('assinatura_eletronica.erro.nao_encontrado', Response::HTTP_NOT_FOUND);
        } catch (AcaoRecusada $e) {
            return $this->erro($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true]);
    }

    private function erro(string $chave, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave)], $status);
    }
}
