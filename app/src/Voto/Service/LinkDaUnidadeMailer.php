<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Email\IdentidadeDeEmail;
use App\Voto\Entity\UnidadeDaAssembleia;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
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
        private readonly LocaleSwitcher $idiomaDoEmail,
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
        // E-mail para outra pessoa sai em português, nunca no idioma de quem disparou (spec sistema-em-ingles §3).
        $this->idiomaDoEmail->runWithLocale('pt_BR', fn () => $this->enviarEmPortugues($unidade));
    }

    private function enviarEmPortugues(UnidadeDaAssembleia $unidade): void
    {
        $link = $this->urlGenerator->generate(
            'voto_assembleia_cedula',
            ['token' => $this->tokens->token($unidade->getSemente())],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $assembleia = $unidade->getAssembleia()->getTitulo();
        $html = $this->renderizar(
            $assembleia,
            '' === $unidade->getBloco() ? $unidade->getIdentificacao() : $unidade->getBloco() . ' · ' . $unidade->getIdentificacao(),
            $unidade->getResponsavel(),
            $link,
        );
        $email = (new Email())->to((string) $unidade->getEmail())->html($html);
        $this->identidadeDeEmail->aplicar($email, $this->assunto($assembleia));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar o link da assembleia: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Prévia da janela de confirmação do envio: o mesmo modelo do e-mail, com dados de exemplo da assembleia. Nada é
     * enviado e nenhum token é gerado: o link aparece como o texto "link individual de cada pessoa".
     *
     * @return array{assunto: string, html: string}
     */
    public function previa(string $tituloDaAssembleia): array
    {
        return $this->idiomaDoEmail->runWithLocale('pt_BR', fn (): array => [
            'assunto' => (string) $this->identidadeDeEmail->aplicar(new Email(), $this->assunto($tituloDaAssembleia))->getSubject(),
            'html' => $this->tornarInerte($this->renderizar(
                $tituloDaAssembleia,
                $this->tradutor->trans('voto.assembleia.gestor.links.previa_unidade'),
                $this->tradutor->trans('voto.assembleia.gestor.links.previa_responsavel'),
                $textoDoLink = $this->tradutor->trans('voto.assembleia.gestor.links.previa_link'),
            ), $textoDoLink),
        ]);
    }

    /** Na prévia o botão e o link não levam a lugar nenhum: href="#" e sem clique (pointer-events: none). */
    private function tornarInerte(string $html, string $textoDoLink): string
    {
        $html = str_replace('href="' . htmlspecialchars($textoDoLink, \ENT_QUOTES) . '"', 'href="#"', $html);
        $estilo = '<style>a{pointer-events:none;cursor:default}</style>';

        return str_contains($html, '</head>') ? str_replace('</head>', $estilo . '</head>', $html) : $estilo . $html;
    }

    private function assunto(string $assembleia): string
    {
        return $this->tradutor->trans('voto.assembleia.gestor.email.assunto', ['assembleia' => $assembleia]);
    }

    private function renderizar(string $assembleia, string $unidade, ?string $responsavel, string $link): string
    {
        return $this->twig->render('email/voto_assembleia_link.html.twig', [
            'assembleia' => $assembleia,
            'unidade' => $unidade,
            'responsavel' => $responsavel,
            'link' => $link,
        ]);
    }
}
