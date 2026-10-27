<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\ApresentadorDeTermo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\Service\PreviaDePerfil;
use App\TrabalhoExterno\UseCase\AceitarTermoUseCase;
use App\TrabalhoExterno\UseCase\ConsultarTermoDoCurriculoUseCase;
use App\TrabalhoExterno\UseCase\EnviarTermoParaAceiteUseCase;
use App\TrabalhoExterno\UseCase\GerarDocumentoDoTermoUseCase;
use App\TrabalhoExterno\UseCase\GerarRascunhoDoTermoUseCase;
use App\TrabalhoExterno\UseCase\PreVisualizarTermoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O Termo de Voluntariado do banco de talentos (spec `trabalho-externo-termo-voluntariado.md`). API JSON como os
 * currículos: escritas com CSRF no `X-CSRF-Token`, leituras aceitam o `?como=` da prévia do Master, escritas nunca.
 * O documento (prévia, Word, Imprimir) é UM só HTML, montado no servidor. Outro voluntário, outro escritório ou
 * termo inexistente: 404.
 */
#[Route('/trabalho-externo')]
final class TermosController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    private const CODIGO_DO_CURRICULO = 'CV-\d{1,9}';
    private const CODIGO_DO_TERMO = 'TV-\d{4,9}';

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly PreviaDePerfil $previa,
        private readonly ApresentadorDeTermo $apresentador,
    ) {
    }

    /** O termo mais recente do currículo, sem CPF nem RG. */
    #[Route('/api/curriculos/{codigo}/termo', name: 'trabalho_externo_termo_do_curriculo', methods: ['GET'], requirements: ['codigo' => self::CODIGO_DO_CURRICULO])]
    public function doCurriculo(Request $request, string $codigo, ConsultarTermoDoCurriculoUseCase $consultar): JsonResponse
    {
        return $this->ler($request, static fn (Contexto $ctx): array => $consultar->executar($ctx, $codigo));
    }

    /** "Gerar termo" / "Novo termo": os campos pré-preenchidos. Não grava. */
    #[Route('/api/curriculos/{codigo}/termo/rascunho', name: 'trabalho_externo_termo_rascunho', methods: ['GET'], requirements: ['codigo' => self::CODIGO_DO_CURRICULO])]
    public function rascunho(Request $request, string $codigo, GerarRascunhoDoTermoUseCase $gerar): JsonResponse
    {
        return $this->ler($request, static fn (Contexto $ctx): array => $gerar->executar($ctx, $codigo));
    }

    /** A prévia do rascunho (iframe, Word, Imprimir): o HTML dos campos enviados. Erro em JSON, como as escritas. */
    #[Route('/api/curriculos/{codigo}/termo/previa', name: 'trabalho_externo_termo_previa', methods: ['POST'], requirements: ['codigo' => self::CODIGO_DO_CURRICULO])]
    public function previa(Request $request, string $codigo, PreVisualizarTermoUseCase $previa): Response
    {
        $html = null;
        $erro = $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $previa, &$html): array {
            $html = $previa->executar($ctx, $codigo, $this->corpo($request));

            return [];
        });

        return is_string($html) ? $this->documentoHtml($html) : $erro;
    }

    #[Route('/api/curriculos/{codigo}/termo/enviar', name: 'trabalho_externo_termo_enviar', methods: ['POST'], requirements: ['codigo' => self::CODIGO_DO_CURRICULO])]
    public function enviar(Request $request, string $codigo, EnviarTermoParaAceiteUseCase $enviar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $enviar): array {
            $termo = $enviar->executar($ctx, $codigo, $this->corpo($request));

            return ['termo' => $this->apresentador->resumo($ctx, $termo)];
        });
    }

    #[Route('/api/termos/{codigo}/aceitar', name: 'trabalho_externo_termo_aceitar', methods: ['POST'], requirements: ['codigo' => self::CODIGO_DO_TERMO])]
    public function aceitar(Request $request, string $codigo, AceitarTermoUseCase $aceitar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $aceitar): array {
            $li = filter_var($this->corpo($request)['li'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $termo = $aceitar->executar($ctx, $codigo, $li, $request->getClientIp(), $request->headers->get('User-Agent'));

            return ['termo' => $this->apresentador->resumo($ctx, $termo)];
        });
    }

    /** O HTML do termo gravado (prévia, Word, Imprimir). Nunca em cache: leva CPF e RG. */
    #[Route('/termos/{codigo}/documento', name: 'trabalho_externo_termo_documento', methods: ['GET'], requirements: ['codigo' => self::CODIGO_DO_TERMO])]
    public function documento(Request $request, string $codigo, GerarDocumentoDoTermoUseCase $gerar): Response
    {
        $ctx = $this->contextoDeLeitura($request) ?? throw $this->createAccessDeniedException();
        try {
            return $this->documentoHtml($gerar->executar($ctx, $codigo));
        } catch (DemandaNaoEncontrada) {
            throw $this->createNotFoundException();
        } catch (AcaoNaoPermitida $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }
    }

    private function documentoHtml(string $html): Response
    {
        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            // O documento só desenha: sem script, só estilos inline.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }

    private function contextoDeLeitura(Request $request): ?Contexto
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return null;
        }
        $como = $this->previa->perfilPedido($ctx, $request->query->get('como'));

        return $como !== null ? $this->previa->contexto($ctx, $como) : $ctx;
    }

    /** @param callable(Contexto): array<string, mixed> $acao */
    private function ler(Request $request, callable $acao): JsonResponse
    {
        $ctx = $this->contextoDeLeitura($request);

        return $ctx === null ? $this->semAcesso() : $this->responder(static fn (): array => $acao($ctx));
    }

    /** @param callable(Contexto): array<string, mixed> $acao */
    private function escrever(Request $request, callable $acao): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);

        return $this->responder(static fn (): array => $acao($ctx));
    }
}
