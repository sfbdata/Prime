<?php

declare(strict_types=1);

namespace App\Calculo\Controller;

use App\Calculo\DTO\ResultadoDaVerificacao;
use App\Calculo\UseCase\VerificarCodigoDoCalculoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Verificação pública do código do PDF do Cálculo (spec calculo-atualizacao-monetaria §7): `/calculo/verificar`, SEM
 * login (`access_control` PUBLIC_ACCESS). Limitada por IP (`calculo_verificacao`), sempre `noindex` e sem cache.
 * O arquivo enviado só tem o SHA-256 calculado e nunca é gravado.
 */
#[Route('/calculo/verificar')]
final class VerificacaoDoCalculoController extends AbstractController
{
    private const TOKEN = 'calculo_verificar';
    private const TAMANHO_MAXIMO_DO_PDF = 5 * 1024 * 1024;

    public function __construct(
        private readonly VerificarCodigoDoCalculoUseCase $verificar,
        private readonly RateLimiterFactoryInterface $calculoVerificacaoLimiter,
    ) {
    }

    #[Route('', name: 'calculo_verificar', methods: ['GET', 'POST'])]
    public function verificar(Request $request): Response
    {
        if (!$this->calculoVerificacaoLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->pagina(null, 'limite', Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($request->isMethod('POST')) {
            return $this->postar($request);
        }
        $codigo = trim((string) $request->query->get('codigo', ''));

        return $this->pagina($codigo === '' ? null : $this->verificar->executar($codigo), null, Response::HTTP_OK, $codigo);
    }

    private function postar(Request $request): Response
    {
        $codigo = trim((string) $request->request->get('codigo', ''));
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            return $this->pagina(null, 'sessao', Response::HTTP_OK, $codigo);
        }

        $arquivo = $request->files->get('arquivo');
        $hash = null;
        if ($arquivo instanceof UploadedFile && $arquivo->isValid()) {
            if ($arquivo->getSize() > self::TAMANHO_MAXIMO_DO_PDF) {
                return $this->pagina(null, 'arquivo_grande', Response::HTTP_OK, $codigo);
            }
            $hash = hash_file('sha256', $arquivo->getPathname()) ?: null;
        }

        return $this->pagina($this->verificar->executar($codigo, $hash), null, Response::HTTP_OK, $codigo);
    }

    private function pagina(?ResultadoDaVerificacao $resultado, ?string $aviso, int $status, string $codigo = ''): Response
    {
        $resposta = $this->render('calculo/verificar.html.twig', [
            'resultado' => $resultado,
            'aviso' => $aviso,
            'codigo' => $codigo,
        ], new Response('', $status));
        $resposta->headers->set('X-Robots-Tag', 'noindex');
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
