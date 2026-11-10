<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\ConferirPdfEnviadoUseCase;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confere o PDF do passo 1 sem gravar (etapa 5, E5-6): multipart com `arquivo`, CSRF no cabeçalho X-CSRF-Token.
 * Responde `{jaAssinado: quadro}` (nulo sem assinatura digital). Sem o módulo: 403; arquivo ausente, que não é PDF ou
 * que passa de 10 MB: 422 com `{erro}`; passou do limitador por usuário (`assinatura_pdf_conferir`, cada conferência
 * ocupa um processo do PHP por segundos): 429 com `{erro}`.
 */
final class ConferenciaDoPdfController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly TranslatorInterface $tradutor,
        private readonly RateLimiterFactoryInterface $assinaturaPdfConferirLimiter,
    ) {
    }

    #[Route('/assinatura/pdf/conferir', name: 'assinatura_eletronica_pdf_conferir', methods: ['POST'])]
    public function conferir(Request $request, ConferirPdfEnviadoUseCase $conferir): JsonResponse
    {
        $quem = $this->acesso->quemPode();
        if ($quem === null) {
            return $this->erro('assinatura_eletronica.erro.sem_permissao', [], Response::HTTP_FORBIDDEN);
        }
        $this->validarCsrfAjax($request);
        if (!$this->assinaturaPdfConferirLimiter->create('usuario-' . $quem[0]->getId())->consume()->isAccepted()) {
            return $this->erro(MuitasTentativas::CHAVE, [], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $arquivo = $request->files->get('arquivo');
        if (!$arquivo instanceof UploadedFile) {
            return $this->erro('assinatura_eletronica.erro.arquivo_invalido', [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json(['jaAssinado' => $conferir->executar($arquivo)]);
        } catch (AcaoRecusada $e) {
            return $this->erro($e->getMessage(), $e->parametros, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, int|string> $parametros */
    private function erro(string $chave, array $parametros, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave, $parametros)], $status);
    }
}
