<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\Banco\Bacen\BancoPixBacen;
use App\Contribua\Banco\BancosPix;
use App\Contribua\Entity\NotificacaoRecebida;
use App\Contribua\Message\ReconsultarCobrancaNoBanco;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\Repository\NotificacaoRecebidaRepository;
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
 *
 * Bancos da API Pix do BACEN (Inter, BB, Outro; D6 da C4b): o corpo é `{"pix": [{endToEndId, txid, valor,
 * horario, ...}]}` (bacen.github.io/pix-api, "Webhook"). Do corpo só se aproveita o txid, e só se for de
 * uma cobrança nossa daquele banco; o valor nunca é usado: a reconsulta `GET /cob/{txid}` é que confirma.
 * O padrão manda o PSP acrescentar `/pix` ao endereço cadastrado, então o caminho com `/pix/pix` também é
 * aceito (cadastrou o endereço da tela, o aviso chega nele mesmo ou nele + `/pix`).
 */
final class WebhookDoBancoController extends AbstractController
{
    /** Um aviso do BACEN pode juntar vários PIX; mais que isto não é aviso de cobrança nossa. */
    private const MAXIMO_POR_AVISO = 50;

    public function __construct(
        private readonly NotificacaoRecebidaRepository $avisos,
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly MessageBusInterface $bus,
        private readonly BancosPix $bancos,
        private readonly IntegracaoBancariaRepository $integracoes,
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
        if ('' === $evento || '' === $recurso || !str_starts_with($tipo, 'invoice.') || null === $this->cobrancas->doBanco('cora', $recurso)) {
            return $this->json(['success' => true]);
        }
        if ($this->avisos->registrarSeNova(new NotificacaoRecebida('cora', $evento, mb_substr($tipo, 0, 60), $recurso))) {
            $this->bus->dispatch(new ReconsultarCobrancaNoBanco('cora', $recurso, $evento));
        }

        return $this->json(['success' => true]);
    }

    #[Route('/api/webhooks/{banco}/pix', name: 'contribua_webhook_bacen', requirements: ['banco' => '[a-z]{2,20}'], methods: ['POST'])]
    #[Route('/api/webhooks/{banco}/pix/pix', name: 'contribua_webhook_bacen_pix', requirements: ['banco' => '[a-z]{2,20}'], methods: ['POST'])]
    public function bacen(Request $request, string $banco): JsonResponse
    {
        // Só os bancos da API Pix do BACEN registrados (a Cora tem a rota própria, acima), e só o configurado
        // agora: aviso do banco anterior não gera trabalho (as credenciais guardadas não são dele).
        if (!$this->bancos->doCodigo($banco) instanceof BancoPixBacen || $banco !== $this->integracoes->atual()->getBanco()) {
            return $this->json(['success' => true]);
        }
        $corpo = json_decode($request->getContent(), true);
        $avisos = is_array($corpo) && is_array($corpo['pix'] ?? null) ? array_slice($corpo['pix'], 0, self::MAXIMO_POR_AVISO) : [];
        foreach ($avisos as $pix) {
            $txid = is_array($pix) && is_string($pix['txid'] ?? null) ? $pix['txid'] : '';
            // Só uma cobrança nossa gera registro e fila (o endereço é público).
            if (1 !== preg_match('/^[a-zA-Z0-9]{26,35}$/', $txid) || null === $this->cobrancas->doBanco($banco, $txid)) {
                continue;
            }
            $e2e = is_string($pix['endToEndId'] ?? null) ? mb_substr(trim($pix['endToEndId']), 0, 120) : '';
            $evento = '' !== $e2e ? $e2e : 'txid:' . $txid;
            if ($this->avisos->registrarSeNova(new NotificacaoRecebida($banco, $evento, 'pix', $txid))) {
                $this->bus->dispatch(new ReconsultarCobrancaNoBanco($banco, $txid, $evento));
            }
        }

        return $this->json(['success' => true]);
    }

    private function cabecalho(Request $request, string $nome): string
    {
        return mb_substr(trim((string) $request->headers->get($nome, '')), 0, 120);
    }
}
