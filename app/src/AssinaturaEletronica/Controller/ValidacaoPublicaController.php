<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\UseCase\ValidarCodigoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A validação pública do código BJA (etapa 3, bloco 2; inventário A7 e A8), sem login: a página que o QR do PDF abre
 * (`/assinar/validar/{codigo}`), a mesma página sem código (`/assinar/validar`, o rodapé do PDF manda para ela) e a
 * consulta em JSON que a aba Validar da tela interna e a página usam.
 *
 * Prefixo de nome `assinatura_eletronica_publica_`: o
 * {@see \App\AssinaturaEletronica\EventListener\AssinaturaPublicaListener} desliga o filtro de escritório (a aba de um
 * escritório acha o código de outro, como a página pública) e põe os cabeçalhos públicos. Prioridade acima da reserva
 * `/assinar/{resto}` do {@see AssinaturaPublicaController}; a consulta acima da página com código.
 *
 * Toda procura passa pelo limitador por IP `assinatura_validacao`; a página sem código não procura nada e não gasta a
 * cota. A resposta nunca leva CPF nem e-mail (projeção em {@see \App\AssinaturaEletronica\Service\ProjecaoPublicaDaValidacao}).
 */
final class ValidacaoPublicaController extends AbstractController
{
    private const TEMPLATE = 'assinatura_eletronica/validar.html.twig';

    public function __construct(
        private readonly RateLimiterFactoryInterface $assinaturaValidacaoLimiter,
        private readonly ValidarCodigoUseCase $validar,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/assinar/validar', name: 'assinatura_eletronica_publica_validar', methods: ['GET'], priority: 10)]
    public function pagina(): Response
    {
        return $this->render(self::TEMPLATE, ['codigo' => '', 'consultado' => false, 'resultado' => null, 'limite' => false]);
    }

    #[Route('/assinar/validar/consulta', name: 'assinatura_eletronica_publica_validar_consulta', methods: ['GET'], priority: 11)]
    public function consulta(Request $request): JsonResponse
    {
        if (!$this->dentroDoLimite($request)) {
            return $this->json(['erro' => $this->tradutor->trans(MuitasTentativas::CHAVE)], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $resultado = $this->validar->executar($request->query->getString('codigo'));

        return $this->json(['documento' => $resultado], $resultado !== null ? Response::HTTP_OK : Response::HTTP_NOT_FOUND);
    }

    #[Route('/assinar/validar/{codigo}', name: 'assinatura_eletronica_publica_validar_codigo', requirements: ['codigo' => '[^/]+'], methods: ['GET'], priority: 10)]
    public function comCodigo(string $codigo, Request $request): Response
    {
        if (!$this->dentroDoLimite($request)) {
            return $this->render(self::TEMPLATE, ['limite' => true], new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
        }

        $resultado = $this->validar->executar($codigo);

        return $this->render(self::TEMPLATE, [
            'codigo' => ValidarCodigoUseCase::normalizar($codigo),
            'consultado' => true,
            'resultado' => $resultado,
            'limite' => false,
        ], new Response(null, $resultado !== null ? Response::HTTP_OK : Response::HTTP_NOT_FOUND));
    }

    private function dentroDoLimite(Request $request): bool
    {
        return $this->assinaturaValidacaoLimiter->create($request->getClientIp() ?? 'sem-ip')->consume()->isAccepted();
    }
}
