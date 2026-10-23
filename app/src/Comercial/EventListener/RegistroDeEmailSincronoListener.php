<?php

declare(strict_types=1);

namespace App\Comercial\EventListener;

use App\Comercial\Service\RegistroDeEnvioExterno;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * A cota de envio conta TODOS os e-mails (spec comercial-e-emails.md §3.6): os que saem direto pelo Mailer (cadastro,
 * convite, senha, avisos, agendador...) não passam pelo Carteiro, então cada um que o transporte aceitou vira uma
 * linha `sistema` em `comercial_envio` (transacional, enviado). LGPD (decisão do coordenador, 23/10/2026): a linha não
 * guarda o endereço nem o assunto, porque a cota só precisa da situação, da origem e de `enviado_em`; o destinatário
 * (coluna obrigatória) leva o marcador fixo `sistema` e o assunto, o fixo "E-mail do sistema".
 *
 * Os já registrados (o do Carteiro, no {@see \App\Comercial\MessageHandler\EnviarEmailHandler}, e a confirmação do
 * cadastro, que o {@see CicloDaContaListener} grava como `automacao:cad`) chegam com o cabeçalho interno
 * {@see self::CABECALHO}. Ele é tirado antes de a mensagem sair (no MessageEvent do transporte, por último), e a
 * mensagem fica lembrada até o SentMessageEvent, que então não grava nada.
 *
 * Uma falha ao registrar nunca derruba o envio: vai ao log, sem o endereço, numa transação própria (savepoint).
 */
final class RegistroDeEmailSincronoListener
{
    public const CABECALHO = 'X-Bluejus-Registrado';
    public const ORIGEM = 'sistema';

    /** @var \WeakMap<RawMessage, true> */
    private \WeakMap $jaRegistrados;

    public function __construct(
        private readonly RegistroDeEnvioExterno $registro,
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
        $this->jaRegistrados = new \WeakMap();
    }

    /** Por último entre os ouvintes, no evento do transporte: o que sai daqui é o que vai ao servidor. */
    #[AsEventListener(priority: -1024)]
    public function aoPrepararEnvio(MessageEvent $evento): void
    {
        $mensagem = $evento->getMessage();
        if ($evento->isQueued() || !$mensagem instanceof Message || !$mensagem->getHeaders()->has(self::CABECALHO)) {
            return;
        }
        $mensagem->getHeaders()->remove(self::CABECALHO);
        $this->jaRegistrados[$mensagem] = true;
    }

    #[AsEventListener]
    public function aoEnviar(SentMessageEvent $evento): void
    {
        $enviada = $evento->getMessage();
        $original = $enviada->getOriginalMessage();
        if (isset($this->jaRegistrados[$original])) {
            unset($this->jaRegistrados[$original]);

            return;
        }
        if ($original instanceof Message && $original->getHeaders()->has(self::CABECALHO)) {
            return;
        }
        try {
            $this->conexao->transactional(fn () => $this->registro->registrarSemDados(self::ORIGEM, $this->relogio->now()));
        } catch (\Throwable $erro) {
            $this->logger->error('Comercial: e-mail síncrono enviado sem registro na cota.', ['erro' => $erro::class]);
        }
    }
}
