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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aviso da Cora (developers.cora.com.br/reference/exemplo-de-post-da-notificação): chega SEM corpo e SEM
 * assinatura, só os cabeçalhos `webhook-event-id`, `webhook-event-type` e `webhook-resource-id`. Por isso é
 * só um aviso: registra (evento repetido é ignorado), responde 200 na hora e enfileira a reconsulta mTLS,
 * que é quem confirma. Público pela regra `^/api/webhooks/` do security.yaml (C3).
 *
 * Como o id do evento é escolhido por quem envia, duas travas seguram o volume (auditoria 3.16): o limitador
 * `webhook_cora` por IP e, por recurso, nenhuma reconsulta nova enquanto há aviso pendente do mesmo recurso
 * recebido nos últimos 60 segundos ({@see self::JANELA_DO_RECURSO}). Nas duas a resposta continua 200: a
 * confirmação só vem do banco, e o aviso que ficar de fora é coberto pela conciliação.
 */
final class WebhookDoBancoController extends AbstractController
{
    /** Janela em que um aviso pendente do mesmo recurso segura a reconsulta seguinte. */
    private const JANELA_DO_RECURSO = '-60 seconds';

    public function __construct(
        private readonly NotificacaoRecebidaRepository $avisos,
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly PagamentoRepository $pagamentos,
        private readonly MessageBusInterface $bus,
        // Interface, não a classe concreta: é o que permite o dublê de teste substituir o serviço
        // (ver RateLimiterFactoryEspiao), como no PoliticaPrivacidadeController.
        private readonly RateLimiterFactoryInterface $webhookCoraLimiter,
    ) {
    }

    #[Route('/api/webhooks/cora/pix', name: 'contribua_webhook_cora', methods: ['POST'])]
    public function cora(Request $request): JsonResponse
    {
        // Acima do limite por IP: 200 sem gravar nem enfileirar. A Cora não deve receber 429, e um laço de avisos
        // inventados não chega nem às consultas de recurso abaixo.
        if (!$this->webhookCoraLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['success' => true]);
        }
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
        // Reconsulta do mesmo recurso já na fila: outro id de evento não grava nem enfileira de novo (a que está
        // na fila consulta o banco e confirma do mesmo jeito).
        if (null === $mensagem || $this->avisos->haPendenteDoRecurso('cora', $recurso, new \DateTimeImmutable(self::JANELA_DO_RECURSO))) {
            return $this->json(['success' => true]);
        }
        if ($this->avisos->registrarSeNova(new NotificacaoRecebida('cora', $evento, mb_substr($tipo, 0, 60), $recurso))) {
            $this->bus->dispatch($mensagem);
        }

        return $this->json(['success' => true]);
    }

    private function cabecalho(Request $request, string $nome): string
    {
        return mb_substr(trim((string) $request->headers->get($nome, '')), 0, 120);
    }
}
