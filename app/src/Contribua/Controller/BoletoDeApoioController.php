<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\Exception\BoletoIndisponivel;
use App\Contribua\Exception\BoletoNaoGerado;
use App\Contribua\Service\SolicitanteAtual;
use App\Contribua\UseCase\GerarBoletoDeApoioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Gerar boleto" da seção Apoie (chave `bol` do desenho 08, só com a Cora). A mesma porta do PIX dinâmico:
 * qualquer pessoa logada num escritório; no ambiente de testes, só o Master. JSON para o JS do parcial
 * `contribua/_boleto.html.twig`, com o `_token` do CSRF da página Contribua.
 */
#[IsGranted('ROLE_USER')]
final class BoletoDeApoioController extends AbstractController
{
    #[Route('/contribua/boleto', name: 'contribua_boleto', methods: ['POST'])]
    public function gerar(Request $request, SolicitanteAtual $solicitante, GerarBoletoDeApoioUseCase $gerar): JsonResponse
    {
        if (!$this->isCsrfTokenValid('contribua', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $quem = $solicitante->agora() ?? throw $this->createAccessDeniedException('Escolha um escritório para abrir o Contribua.');
        try {
            $boleto = $gerar->executar($quem, (int) $request->request->get('centavos', 0), (string) $request->request->get('documento', ''));
        } catch (BoletoIndisponivel) {
            throw $this->createNotFoundException();
        } catch (BoletoNaoGerado $falha) {
            return $this->json(['ok' => false, 'erro' => $falha->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\DomainException $recusa) {
            return $this->json(['ok' => false, 'erro' => $recusa->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'boleto' => $boleto]);
    }
}
