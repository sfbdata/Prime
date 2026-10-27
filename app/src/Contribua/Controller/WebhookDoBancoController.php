<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\Entity\NotificacaoRecebida;
use App\Contribua\Message\ReconsultarCobrancaNoBanco;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\NotificacaoRecebidaRepository;
use App\Pagamento\Message\ReconsultarPagamentoNoBanco;
use App\Pagamento\Repository\PagamentoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aviso da Cora (developers.cora.com.br/reference/exemplo-de-post-da-notificação): chega SEM corpo e SEM
 * assinatura, só os cabeçalhos `webhook-event-id`, `webhook-event-type` e `webhook-resource-id`. Por isso é
 * só um aviso: registra (evento repetido é ignorado), responde 200 na hora e enfileira a reconsulta mTLS,
 * que é quem confirma. Público pela regra `^/api/webhooks/` do security.yaml (C3).
 */
final class WebhookDoBancoController extends AbstractController
{
    public function __construct(
        private readonly NotificacaoRecebidaRepository $avisos,
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly PagamentoRepository $pagamentos,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/webhooks/cora/pix', name: 'contribua_webhook_cora', methods: ['POST'])]
    public function cora(Request $request): JsonResponse
    {
        $evento = $this->cabecalho($request, 'webhook-event-id');
        $tipo = $this->cabecalho($request, 'webhook-event-type');
        $recurso = $this->cabecalho($request, 'webhook-resource-id');
        // Só uma cobrança nossa gera trabalho: o endereço é público e sem assinatura, então aviso inventado
        // não grava nem enfileira nada (não enche a fila que o Drive e a Inteligência também usam).
        // Nossa é a cobrança PIX da conta (apoio do Contribua) ou um pagamento do registro único (gateway).
        if ('' === $evento || '' === $recurso || !str_starts_with($tipo, 'invoice.')) {
            return $this->json(['success' => true]);
        }
        $mensagem = match (true) {
            null !== $this->cobrancas->doBanco('cora', $recurso) => new ReconsultarCobrancaNoBanco('cora', $recurso, $evento),
            null !== $this->pagamentos->porIdExterno('cora', $recurso) => new ReconsultarPagamentoNoBanco('cora', $recurso, $evento),
            default => null,
        };
        if (null !== $mensagem && $this->avisos->registrarSeNova(new NotificacaoRecebida('cora', $evento, mb_substr($tipo, 0, 60), $recurso))) {
            $this->bus->dispatch($mensagem);
        }

        return $this->json(['success' => true]);
    }

    private function cabecalho(Request $request, string $nome): string
    {
        return mb_substr(trim((string) $request->headers->get($nome, '')), 0, 120);
    }
}
