<?php

declare(strict_types=1);

namespace App\ChatIa\Controller;

use App\ChatIa\DTO\EnviarMensagemInput;
use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\UseCase\EnviarMensagemNoChatUseCase;
use App\ChatIa\UseCase\TransmitirRespostaDoChatUseCase;
use App\Entity\Auth\User;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Service\Tenant\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /chat-ia/conversas/{id}/mensagens`: envia a pergunta e responde em SSE (`text/event-stream`).
 *
 * Tudo o que pode recusar (CSRF, ritmo, formato, posse, chave, aceite, segredo, teto) sai ANTES do fluxo como JSON
 * `{codigo, mensagem}` com o status do contrato. Depois o fluxo emite os eventos de
 * {@see TransmitirRespostaDoChatUseCase}: `delta {t}`, `fim {mensagemId, tokensEntrada, tokensSaida, titulo?}` e
 * `erro {codigo, mensagem}`. Cabeçalhos: `Cache-Control: no-store`, `X-Accel-Buffering: no`. Parar = o cliente aborta o
 * fetch; o servidor segue até gravar o trecho parcial (`ignore_user_abort`) e então encerra.
 *
 * O corpo do pedido e os trechos da resposta nunca vão para log.
 */
#[Route('/chat-ia')]
final class ChatIaMensagensController extends AbstractController
{
    use RespondeEmJsonDoChat;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EnviarMensagemNoChatUseCase $preparar,
        private readonly TransmitirRespostaDoChatUseCase $transmitir,
        private readonly EscritorDeSse $escritor,
        // Interface (não a classe): dublê em teste. O mesmo limitador da I.A da pasta e da Central.
        private readonly RateLimiterFactoryInterface $inteligenciaSolicitarLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/conversas/{id}/mensagens', name: 'chat_ia_mensagens_enviar', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function enviar(int $id, Request $request): Response
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        $usuario = $this->usuario();
        try {
            $input = EnviarMensagemInput::deCorpo($this->corpoJson($request));
            $this->conferirORitmo($usuario);
            $preparada = $this->preparar->executar($usuario, $this->tenantContext->getCurrentTenant(), $id, $input);
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return $this->fluxo($preparada);
    }

    private function fluxo(RespostaPreparada $preparada): StreamedResponse
    {
        $resposta = new StreamedResponse(function () use ($preparada): void {
            ignore_user_abort(true);
            // O limite padrão do FPM (30s) cortaria respostas longas do Opus. Fora da CLI: lá o limite vale para o
            // processo inteiro (worker do ParaTest) e o set_time_limit mataria a suíte depois de 300s acumulados.
            if (PHP_SAPI !== 'cli') {
                set_time_limit(300);
            }
            try {
                foreach ($this->transmitir->executar($preparada, static fn (): bool => connection_aborted() === 1) as $evento) {
                    $this->escritor->escrever($evento['evento'], $evento['dados']);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Chat I.A: erro inesperado durante a resposta.', self::contextoDoErro($e, (int) $preparada->conversa->getId()));
                $this->escritor->escrever('erro', ['codigo' => 'erro_interno', 'mensagem' => 'Não consegui responder agora. Tente de novo.']);
            }
        });
        $resposta->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $resposta->headers->set('Cache-Control', 'no-store');
        $resposta->headers->set('X-Accel-Buffering', 'no');

        return $resposta;
    }

    /**
     * O que vai ao log de uma falha inesperada: classe, lugar onde estourou e a cadeia de causas. A mensagem só vai quando a classe é
     * da linguagem ou nossa (as do Doctrine/DBAL podem trazer os parâmetros da consulta, isto é, dado pessoal).
     *
     * @return array<string, mixed>
     */
    private static function contextoDoErro(\Throwable $e, int $conversaId): array
    {
        $contexto = ['erro' => $e::class, 'conversaId' => $conversaId, 'em' => basename($e->getFile()) . ':' . $e->getLine()];
        $seguras = [\RuntimeException::class, \LogicException::class, \UnexpectedValueException::class, \InvalidArgumentException::class, \DomainException::class];
        if (\in_array($e::class, $seguras, true) || str_starts_with($e::class, 'App\\')) {
            $contexto['motivo'] = mb_substr($e->getMessage(), 0, 300);
        }
        $causas = [];
        for ($causa = $e->getPrevious(); $causa !== null; $causa = $causa->getPrevious()) {
            $causas[] = $causa::class . '@' . basename($causa->getFile()) . ':' . $causa->getLine();
        }
        if ($causas !== []) {
            $contexto['causas'] = $causas;
        }

        return $contexto;
    }

    /** @throws MuitasSolicitacoesException */
    private function conferirORitmo(User $usuario): void
    {
        if (!$this->inteligenciaSolicitarLimiter->create((string) $usuario->getId())->consume()->isAccepted()) {
            throw new MuitasSolicitacoesException();
        }
    }
}
