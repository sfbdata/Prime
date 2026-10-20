<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\EmailDaCampanha;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\Service\PreviaDePerfil;
use App\TrabalhoExterno\Service\RegistroDaCampanha;
use App\TrabalhoExterno\UseCase\ExportarDestinatariosDaCampanhaUseCase;
use App\TrabalhoExterno\UseCase\ExportarEmailDaCampanhaUseCase;
use App\TrabalhoExterno\UseCase\MontarCampanhaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Campanha por e-mail do Master (spec VS 18.10.26 §4.10): monta, mostra e exporta; não envia. Tudo é só do
 * Master de verdade: a Equipe, o externo e a prévia (`?como=`, aceito aqui para ser negado) recebem 403 em
 * JSON, também nos downloads (a tela baixa por `fetch` e mostra o toast). Os parâmetros são os do modal:
 * `pub`, `uf`, `area` e `assunto`. A escrita (o registro do "Copiar HTML") tem CSRF no `X-CSRF-Token`.
 */
#[Route('/trabalho-externo')]
final class CampanhaController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly PreviaDePerfil $previa,
    ) {
    }

    #[Route('/api/campanha', name: 'trabalho_externo_campanha', methods: ['GET'])]
    public function campanha(Request $request, MontarCampanhaUseCase $montar): JsonResponse
    {
        $ctx = $this->contexto($request);
        $filtro = FiltroDaCampanha::deArray($request->query->all());

        return $ctx === null ? $this->semAcesso() : $this->responder(static fn (): array => $montar->executar($ctx, $filtro));
    }

    #[Route('/campanha/destinatarios.csv', name: 'trabalho_externo_campanha_csv', methods: ['GET'])]
    public function destinatarios(Request $request, ExportarDestinatariosDaCampanhaUseCase $exportar): Response
    {
        $filtro = FiltroDaCampanha::deArray($request->query->all());

        return $this->baixar($request, static fn (Contexto $ctx): string => $exportar->executar($ctx, $filtro), ExportarDestinatariosDaCampanhaUseCase::NOME_DO_ARQUIVO, 'text/csv; charset=utf-8');
    }

    #[Route('/campanha/email.html', name: 'trabalho_externo_campanha_html', methods: ['GET'])]
    public function email(Request $request, ExportarEmailDaCampanhaUseCase $exportar): Response
    {
        $filtro = FiltroDaCampanha::deArray($request->query->all());

        return $this->baixar($request, static fn (Contexto $ctx): string => $exportar->executar($ctx, $filtro, RegistroDaCampanha::BAIXAR)['html'], EmailDaCampanha::NOME_DO_ARQUIVO, 'text/html; charset=utf-8');
    }

    /** "Copiar HTML": grava o log e devolve o HTML exportado (com o link de vagas próprio) para a tela copiar. */
    #[Route('/api/campanha/registro', name: 'trabalho_externo_campanha_registro', methods: ['POST'])]
    public function registro(Request $request, ExportarEmailDaCampanhaUseCase $exportar): JsonResponse
    {
        $ctx = $this->contexto($request);
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);
        $corpo = $this->corpo($request);
        $acao = is_string($corpo['acao'] ?? null) ? $corpo['acao'] : '';

        return $this->responder(static fn (): array => $exportar->executar($ctx, FiltroDaCampanha::deArray($corpo), $acao));
    }

    /**
     * Os downloads: o arquivo como anexo; a recusa (403) e a lista vazia (404, com o título do toast do
     * desenho) em JSON.
     *
     * @param callable(Contexto): string $gerar
     */
    private function baixar(Request $request, callable $gerar, string $nome, string $tipo): Response
    {
        $ctx = $this->contexto($request);
        if ($ctx === null) {
            return $this->semAcesso();
        }
        try {
            $conteudo = $gerar($ctx);
        } catch (DemandaNaoEncontrada $e) {
            return $this->json(['ok' => false, 'titulo' => ExportarDestinatariosDaCampanhaUseCase::TITULO_SEM_DESTINATARIOS, 'mensagem' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (AcaoNaoPermitida $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }
        $resposta = new Response($conteudo, Response::HTTP_OK, ['Content-Type' => $tipo, 'X-Content-Type-Options' => 'nosniff']);
        $resposta->headers->set('Content-Disposition', $resposta->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nome));

        return $resposta;
    }

    /** O contexto do usuário, já como a prévia pedida (`?como=`), que a política nega. */
    private function contexto(Request $request): ?Contexto
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return null;
        }
        $como = $this->previa->perfilPedido($ctx, $request->query->get('como'));

        return $como !== null ? $this->previa->contexto($ctx, $como) : $ctx;
    }
}
