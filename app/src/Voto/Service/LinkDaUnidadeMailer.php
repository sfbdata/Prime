<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Email\IdentidadeDeEmail;
use App\Voto\Entity\UnidadeDaAssembleia;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Envia à unidade o link pessoal da assembleia, `/votar/{token}` (spec voto-assembleia-condominio, seções 1.6 e 4).
 * Precedente: App\Auth\Service\ConviteMailer (envio síncrono, IdentidadeDeEmail no remetente e no assunto).
 *
 * O token é recalculado da semente na hora do envio; o banco não o guarda.
 */
final class LinkDaUnidadeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly IdentidadeDeEmail $identidadeDeEmail,
        private readonly TokenDaUnidade $tokens,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /**
     * Quem chama conta a falha e segue o lote nos dois casos.
     *
     * @throws \RuntimeException         quando o transporte recusa
     * @throws \InvalidArgumentException quando o endereço não cumpre a RFC (Mime\Address, no `->to()`)
     */
    public function enviar(UnidadeDaAssembleia $unidade): void
    {
        $link = $this->urlGenerator->generate(
            'voto_assembleia_cedula',
            ['token' => $this->tokens->token($unidade->getSemente())],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $assembleia = $unidade->getAssembleia()->getTitulo();
        $html = $this->twig->render('email/voto_assembleia_link.html.twig', [
            'assembleia' => $assembleia,
            'unidade' => '' === $unidade->getBloco() ? $unidade->getIdentificacao() : $unidade->getBloco() . ' · ' . $unidade->getIdentificacao(),
            'responsavel' => $unidade->getResponsavel(),
            'link' => $link,
        ]);
        $email = (new Email())->to((string) $unidade->getEmail())->html($html);
        $this->identidadeDeEmail->aplicar($email, $this->tradutor->trans('voto.assembleia.gestor.email.assunto', ['assembleia' => $assembleia]));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar o link da assembleia: ' . $e->getMessage(), previous: $e);
        }
    }
}
