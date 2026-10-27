<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\UseCase\ReceberAvisoDoGatewayUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhook dos gateways de cartão: `https://bluejus.com.br/api/webhooks/<codigo>` (asaas, mercado_pago,
 * stripe, pagarme). Público (`security.yaml`, `^/api/webhooks/`), sem sessão nem CSRF: quem autentica é o
 * adaptador, pela credencial do gateway. Sempre aberto, mesmo com a tela Contribua desligada.
 *
 * Respostas: 401 sem a credencial; 404 para gateway que não existe; 200 em todo o resto (inclusive aviso
 * repetido, ignorado ou que ficou para a conciliação), para o gateway não reenviar nem pausar a fila.
 */
final class WebhookDoGatewayController extends AbstractController
{
    #[Route('/api/webhooks/{codigo}', name: 'contribua_webhook_gateway', requirements: ['codigo' => '[a-z_]{2,20}'], methods: ['POST'])]
    public function receber(string $codigo, Request $request, ReceberAvisoDoGatewayUseCase $receber): JsonResponse
    {
        try {
            $resultado = $receber->executar($codigo, self::cabecalhos($request), $request->getContent());
        } catch (AvisoNaoAutenticado) {
            return $this->json(['success' => false], Response::HTTP_UNAUTHORIZED);
        } catch (FalhaNoGateway) {
            // O adaptador precisou consultar o gateway para autenticar ou traduzir (JWKS do Pagar.me, fatura do
            // Mercado Pago) e ele não respondeu: 503 faz o gateway reenviar.
            return $this->json(['success' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($resultado === ReceberAvisoDoGatewayUseCase::DESCONHECIDO) {
            throw $this->createNotFoundException();
        }

        return $this->json(['success' => true, 'resultado' => $resultado]);
    }

    /**
     * Cabeçalhos em minúsculas (primeiro valor de cada um) e, como `x-query-<nome>`, os parâmetros da URL:
     * o Mercado Pago assina o `data.id` da query (`x-query-data.id`).
     *
     * @return array<string, string>
     */
    private static function cabecalhos(Request $request): array
    {
        $cabecalhos = [];
        foreach ($request->headers->all() as $nome => $valores) {
            $cabecalhos[strtolower((string) $nome)] = (string) ($valores[0] ?? '');
        }
        // À mão e não com parse_str: o PHP troca o ponto de `data.id` por sublinhado. rawurldecode para o `+`
        // continuar `+` (o valor assinado pelo gateway é o cru).
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $par) {
            [$nome, $valor] = array_pad(explode('=', $par, 2), 2, '');
            if ($nome !== '') {
                $cabecalhos['x-query-' . strtolower(rawurldecode($nome))] = rawurldecode($valor);
            }
        }

        return $cabecalhos;
    }
}
