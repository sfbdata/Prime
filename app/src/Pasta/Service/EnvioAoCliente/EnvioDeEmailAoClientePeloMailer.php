<?php

declare(strict_types=1);

namespace App\Pasta\Service\EnvioAoCliente;

use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Implementação PROVISÓRIA do envio ao cliente, até o Carteiro da 5d entrar no master: Symfony Mailer
 * SÍNCRONO (como o aviso do Push à equipe, D5 do P4b). Monta o layout com o miolo, o rodapé e o link
 * "Não quero mais receber estes avisos" (descadastro do #87, escopo = o escritório, assunto
 * `push_cliente`, ou a lista quando houver), mais os cabeçalhos List-Unsubscribe (RFC 8058).
 *
 * Remetente: o endereço do `MAILER_FROM` com o `nomeDoRemetente` ("{Escritório} via BlueJus") no nome
 * de exibição; Responder para = `replyTo` (o responsável da pasta). O assunto vai como o advogado
 * escreveu, sem o prefixo "[BlueJus]" dos e-mails do sistema. Não consulta o descadastro: quem chama
 * já trava o endereço descadastrado (o Carteiro relê na fila).
 */
final class EnvioDeEmailAoClientePeloMailer implements EnvioDeEmailAoClienteInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly IdentidadeDeEmail $identidade,
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    public function enviar(MensagemAoCliente $mensagem): ResultadoDoEnvioAoCliente
    {
        $link = $this->descadastro->gerarLink($mensagem->escopo, $mensagem->para, $mensagem->lista ?? self::ASSUNTO_DO_DESCADASTRO);
        $email = $this->email($mensagem, $link);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar o e-mail ao cliente: ' . $e::class, previous: $e);
        }

        return new ResultadoDoEnvioAoCliente(null, ResultadoDoEnvioAoCliente::ENVIADO);
    }

    private function email(MensagemAoCliente $mensagem, string $link): Email
    {
        $email = (new Email())->to($mensagem->para)->html($this->twig->render('email/push_cliente.html.twig', [
            'escritorio' => $mensagem->escopo?->getName(),
            'titulo' => $mensagem->titulo,
            'corpoHtml' => $mensagem->corpoHtml,
            'linkDescadastro' => $link,
        ]));
        $this->identidade->aplicar($email, $mensagem->assunto);
        $email->subject($mensagem->assunto);
        $this->aplicarRemetente($email, $mensagem);
        if ($mensagem->cc !== []) {
            $email->cc(...$mensagem->cc);
        }
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $link . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        return $email;
    }

    private function aplicarRemetente(Email $email, MensagemAoCliente $mensagem): void
    {
        $remetente = $email->getFrom()[0] ?? null;
        $nome = trim((string) $mensagem->nomeDoRemetente);
        if ($remetente !== null && $nome !== '') {
            $email->from(new Address($remetente->getAddress(), $nome));
        }
        if ($mensagem->replyTo !== null) {
            $email->replyTo($mensagem->replyTo);
        }
    }
}
