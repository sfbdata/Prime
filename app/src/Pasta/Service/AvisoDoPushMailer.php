<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AvisoDaPastaOutput;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * O e-mail do "Avisar quando chegar" para o responsável da pasta (spec push-avisar-quando-chegar §3,
 * D3 e D4): o mesmo conteúdo do sino, a lista das movimentações (tipo, data, órgão e resumo, nunca o
 * teor inteiro), o botão "Abrir a pasta" e o link "Não quero mais receber estes avisos".
 *
 * Remetente: o `MAILER_FROM` com o NOME DO ESCRITÓRIO no nome de exibição (D4). "Responder para" fica
 * o padrão da instalação: o destinatário já é o responsável. O descadastro vai também no cabeçalho
 * List-Unsubscribe (com o one-click do RFC 8058), que os provedores mostram como "Cancelar inscrição".
 */
final class AvisoDoPushMailer
{
    public const ASSUNTO_DO_DESCADASTRO = 'push_aviso_equipe';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly IdentidadeDeEmail $identidade,
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    /** @throws \RuntimeException falha do transporte (quem chama registra e segue) */
    public function enviar(User $destinatario, Tenant $tenant, AvisoDaPastaOutput $aviso): void
    {
        $email = (string) $destinatario->getEmail();
        $descadastro = $this->descadastro->gerarLink($tenant, $email, self::ASSUNTO_DO_DESCADASTRO);
        $mensagem = (new Email())
            ->to($email)
            ->html($this->twig->render('email/push_aviso_equipe.html.twig', [
                'nome' => $destinatario->getFullName(),
                'escritorio' => $tenant->getName(),
                'aviso' => $aviso,
                'linkDaPasta' => $this->urls->generate('pasta_show', ['id' => $aviso->pastaId], UrlGeneratorInterface::ABSOLUTE_URL) . '#push',
                'linkDescadastro' => $descadastro,
            ]));
        $this->identidade->aplicar($mensagem, $aviso->mensagem());
        $this->usarNomeDoEscritorio($mensagem, $tenant);
        $mensagem->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $descadastro . '>');
        $mensagem->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        try {
            $this->mailer->send($mensagem);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar o aviso do Push: ' . $e::class, previous: $e);
        }
    }

    /** Mesmo endereço do `MAILER_FROM`, com o nome do escritório no nome de exibição (D4). */
    private function usarNomeDoEscritorio(Email $mensagem, Tenant $tenant): void
    {
        $nome = trim((string) $tenant->getName());
        $remetente = $mensagem->getFrom()[0] ?? null;
        if ($nome !== '' && $remetente !== null) {
            $mensagem->from(new Address($remetente->getAddress(), $nome));
        }
    }
}
