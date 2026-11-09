<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\UseCase\ValidarComprovanteUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Validação pública do comprovante do ponto (E15, P50 e D20): `/ponto/validar/{codigo}`, SEM login (`access_control`
 * PUBLIC_ACCESS só neste caminho). É o endereço do QR Code da imagem e o que a janela "Validar código" da /ponto
 * consulta (com `Accept: application/json`).
 *
 * Só leitura, por GET e sem CSRF: o código é a única entrada e nada é gravado. A resposta de quem não é autêntico é
 * a mesma para todos (código que não existe, selo que não confere, formato errado, selo indisponível): "Código não
 * localizado.". Limitada por IP (`ponto_validacao`), sempre `noindex`, sem cache e sem referer.
 */
final class ValidacaoDoComprovanteController extends AbstractController
{
    public const MUITAS_TENTATIVAS = 'Muitas tentativas. Aguarde um minuto e tente de novo.';

    /**
     * Nomes de rota SEM o prefixo `ponto_`, de propósito: o catálogo do Master (E7) derruba toda rota `ponto_*` quando a
     * tela do Ponto é desligada, e a validação de um comprovante já emitido não pode cair junto. O caminho fica em
     * `/ponto/validar`.
     */
    public const ROTA = 'validacao_comprovante_ponto';
    public const ROTA_DO_CODIGO = 'validacao_comprovante_ponto_codigo';

    public function __construct(
        private readonly ValidarComprovanteUseCase $validar,
        private readonly RateLimiterFactoryInterface $pontoValidacaoLimiter,
    ) {
    }

    #[Route('/ponto/validar', name: self::ROTA, methods: ['GET'])]
    #[Route('/ponto/validar/{codigo}', name: self::ROTA_DO_CODIGO, methods: ['GET'])]
    public function validar(Request $request, string $codigo = ''): Response
    {
        $json = in_array('application/json', $request->getAcceptableContentTypes(), true);
        if (!$this->pontoValidacaoLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->resposta($json, null, null, true, Response::HTTP_TOO_MANY_REQUESTS);
        }

        $codigo = trim($codigo !== '' ? $codigo : (string) $request->query->get('codigo', ''));
        $dados = $codigo === '' ? null : $this->validar->executar($codigo);

        return $this->resposta($json, $codigo, $dados, false, Response::HTTP_OK);
    }

    private function resposta(bool $json, ?string $codigo, ?DadosDoComprovante $dados, bool $limite, int $status): Response
    {
        $resposta = $json
            ? new JsonResponse($limite ? ['autentico' => false, 'erro' => self::MUITAS_TENTATIVAS] : ['autentico' => $dados !== null, 'texto' => $dados?->textoDaValidacao()], $status)
            : $this->render('ponto/validar_comprovante.html.twig', [
                'codigo'    => (string) $codigo,
                'consultou' => $codigo !== null && $codigo !== '',
                'texto'     => $dados?->textoDaValidacao(),
                'limite'    => $limite ? self::MUITAS_TENTATIVAS : null,
            ], new Response('', $status));
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
