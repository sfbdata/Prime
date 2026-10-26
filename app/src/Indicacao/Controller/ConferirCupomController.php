<?php

declare(strict_types=1);

namespace App\Indicacao\Controller;

use App\Indicacao\Service\CodigoDeIndicacao;
use App\Indicacao\UseCase\ConferirCodigoDeIndicacaoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Conferência ao vivo do cupom do "Criar conta" (spec `login-cupom-ao-vivo.md`). Pública e sem estado: não grava na
 * sessão nem atribui nada; responde só `{"valido": bool}` (sem nome, e-mail ou id de quem indicou).
 *
 * Aceito e registrado: este endpoint revela se um código existe (o `/i/{codigo}` foi feito para não revelar). A
 * mitigação é o limite por IP (20 por 15 minutos, `indicacao_cupom.yaml`), consumido a cada consulta inclusive de
 * código malformado, o alfabeto de 8 caracteres e a resposta mínima.
 */
final class ConferirCupomController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $indicacaoCupomLimiter,
    ) {
    }

    #[Route('/cadastro/cupom/conferir', name: 'auth_cadastro_cupom_conferir', methods: ['POST'])]
    public function conferir(Request $request, ConferirCodigoDeIndicacaoUseCase $conferir): JsonResponse
    {
        if (!$this->indicacaoCupomLimiter->create('ip-' . $request->getClientIp())->consume()->isAccepted()) {
            return $this->semCache(new JsonResponse(['erro' => 'limite'], Response::HTTP_TOO_MANY_REQUESTS));
        }

        try {
            $corpo = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $corpo = null;
        }
        $codigo = CodigoDeIndicacao::doCupom(is_array($corpo) ? ($corpo['codigo'] ?? null) : null);

        return $this->semCache(new JsonResponse(['valido' => $codigo !== null && $conferir->executar($codigo) !== null]));
    }

    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'no-store, private');

        return $resposta;
    }
}
