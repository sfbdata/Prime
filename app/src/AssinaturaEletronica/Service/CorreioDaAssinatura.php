<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\Enum\TipoDeCarta;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Auth\User;
use App\Shared\Email\IdentidadeDeEmail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Os e-mails da Assinatura (contrato da etapa 2, seção 6): Mailer síncrono, sempre DEPOIS do COMMIT da ação, um por
 * destinatário. Ao signatário, em nome do escritório ("{Escritório} via BlueJus" sobre o endereço da plataforma, sem o
 * prefixo dos e-mails do sistema, Responder para quem enviou); ao remetente, como e-mail do sistema.
 *
 * Falha num destinatário não desfaz nada: vira log (sem código, link nem token) e o evento `email_nao_enviado`. O
 * e-mail que leva o link e ficou sem ele (o token recalculado não bate com o `token_hash` guardado: segredo trocado
 * depois do envio) não sai e conta como falha. Só o e-mail entregue ao transporte grava o evento da entrega
 * (`convite_enviado`, `lembrete_enviado` ou `codigo_enviado`, com o e-mail mascarado e, no código, a evidência da
 * requisição).
 */
final readonly class CorreioDaAssinatura
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private IdentidadeDeEmail $identidade,
        private TranslatorInterface $tradutor,
        private DocumentoParaAssinarRepository $documentos,
        private TrilhaDoDocumento $trilha,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<CartaDaAssinatura> $cartas
     *
     * @return bool se todas as cartas com destinatário foram entregues ao transporte
     */
    public function entregar(array $cartas): bool
    {
        $tudoEntregue = true;
        foreach ($cartas as $carta) {
            $para = $carta->destinatario();
            if ($para !== null && !$this->entregarUma($carta, $para)) {
                $tudoEntregue = false;
            }
        }

        return $tudoEntregue;
    }

    /** Manda uma carta e registra o desfecho na trilha. Falso quando o e-mail não saiu. */
    private function entregarUma(CartaDaAssinatura $carta, string $para): bool
    {
        if ($carta->semLink()) {
            $this->registrarFalha($carta, ['motivo' => 'link_indisponivel']);

            return false;
        }

        try {
            $this->mailer->send($this->email($carta, $para));
        } catch (\Throwable $e) {
            $this->registrarFalha($carta, ['classe' => $e::class]);

            return false;
        }

        $tipo = $carta->eventoDaEntrega();
        if ($tipo !== null) {
            $this->registrar($carta, $tipo, ['para' => MascaraDeEmail::mascarar($para)]);
        }

        return true;
    }

    private function email(CartaDaAssinatura $carta, string $para): Email
    {
        $documento = $carta->documento;
        $escritorio = trim((string) $documento->getTenant()->getName());
        $assunto = $this->tradutor->trans($carta->tipo->chaveDoAssunto(), ['documento' => $documento->getNome()]);
        $email = (new Email())->to($para)->html($this->twig->render($carta->tipo->template(), [
            'escritorio' => $escritorio,
            'documento' => $documento->getNome(),
            'mensagem' => (string) $documento->getMensagem(),
            'nome' => $carta->signatario?->getNome() ?? '',
            'motivo' => (string) $carta->signatario?->getMotivoRecusa(),
            'link' => $carta->link,
            'codigo' => $carta->codigo,
        ]));
        $this->identidade->aplicar($email, $assunto);
        if (!$carta->tipo->aoSignatario()) {
            return $email;
        }

        // Ao signatário: o assunto como está, o nome do escritório no remetente e a resposta para quem enviou.
        $email->subject($assunto);
        $remetente = $email->getFrom()[0] ?? null;
        if ($remetente !== null && $escritorio !== '') {
            $email->from(new Address($remetente->getAddress(), $escritorio . ' via BlueJus'));
        }
        $responder = $carta->responderA?->getEmail();
        if ($responder !== null && $responder !== '') {
            $email->replyTo($responder);
        }

        return $email;
    }

    /**
     * O log da falha (o tipo da carta, os ids e o motivo; nunca o link, o token, o código nem a mensagem da exceção) e o
     * evento `email_nao_enviado`.
     *
     * @param array{motivo?: string, classe?: class-string} $porque
     */
    private function registrarFalha(CartaDaAssinatura $carta, array $porque): void
    {
        $this->logger->error('E-mail da assinatura não enviado.', [
            'carta' => $carta->tipo->value,
            'documento' => $carta->documento->getId(),
            'signatario' => $carta->tipo->aoSignatario() ? $carta->signatario?->getId() : null,
        ] + $porque);
        $this->registrar($carta, TipoDeEvento::EmailNaoEnviado, ['motivo' => 'falha_no_envio']);
    }

    /**
     * O evento da entrega numa transação própria (a ação já foi confirmada). Se a trilha falhar, fica no log.
     *
     * @param array<string, mixed> $dados
     */
    private function registrar(CartaDaAssinatura $carta, TipoDeEvento $tipo, array $dados): void
    {
        $aoSignatario = $carta->tipo->aoSignatario();
        try {
            $this->documentos->emTransacao(fn (): mixed => $this->trilha->acrescentar(
                $carta->documento,
                $tipo,
                $dados,
                $this->usuarioDoEvento($carta),
                $aoSignatario ? $carta->signatario : null,
                $carta->evidencia,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Evento do e-mail da assinatura não gravado.', [
                'evento' => $tipo->value,
                'documento' => $carta->documento->getId(),
                'classe' => $e::class,
            ]);
        }
    }

    /**
     * Quem o evento cita: no convite e no lembrete, quem os mandou; nas cartas ao remetente, o próprio remetente (o
     * `{nome}` do "E-mail não enviado a {nome}"); no código e na vez, ninguém do escritório.
     */
    private function usuarioDoEvento(CartaDaAssinatura $carta): ?User
    {
        return match ($carta->tipo) {
            TipoDeCarta::Convite, TipoDeCarta::Lembrete => $carta->responderA,
            TipoDeCarta::Recusa, TipoDeCarta::Conclusao => $carta->documento->getCriadoPor(),
            TipoDeCarta::Codigo, TipoDeCarta::Vez => null,
        };
    }
}
