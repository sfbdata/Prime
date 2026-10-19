<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\LinkDeDescadastro;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Monta o e-mail de um envio (spec `comercial-e-emails.md` §3.1 passo 4, §3.7 e §3.8): o layout com o rodapé legal
 * (sempre, não editável), a versão texto, a identidade da plataforma, o List-Unsubscribe em `lista` e, com o rastreio
 * ligado, o pixel e os links rastreados.
 *
 * Rastreio ligado só com as duas chaves: `COMERCIAL_RASTREIO_LIGADO` e {@see PoliticaPrivacidadeVigente::CITA_RASTREIO_DE_EMAIL}
 * (a Política tem de citar o rastreio antes). O segundo parâmetro do construtor nasce da constante; os testes o trocam.
 */
final class MontadorDeEmail
{
    public const TEMPLATE = 'email/comercial/_layout.html.twig';
    public const ROTA_ABERTURA = 'comercial_rastreio_abertura';
    public const ROTA_CLIQUE = 'comercial_rastreio_clique';
    private const ROTA_POLITICA = 'legal_politica_privacidade';
    private const LOGO = '/images/logo/bluejus-white-transparent-nav.png';

    public function __construct(
        private readonly Environment $twig,
        private readonly IdentidadeDeEmail $identidade,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly UrlGeneratorInterface $urls,
        private readonly UriSigner $assinadorDeClique,
        private readonly bool $rastreioLigado,
        private readonly bool $politicaCitaRastreio = PoliticaPrivacidadeVigente::CITA_RASTREIO_DE_EMAIL,
    ) {
    }

    /** @param string $token o token público do envio (pixel e clique) */
    public function montar(MensagemDeEmail $m, string $token): Email
    {
        $linkDescadastro = $this->linkDescadastro($m);
        $html = $this->html($m, $token, $linkDescadastro);

        $email = (new Email())->to(new Address($m->para, $m->nome ?? ''))->html($html)->text(self::texto($html));
        foreach ($m->cc as $copia) {
            $email->addCc($copia);
        }
        $this->aplicarIdentidade($email, $m);
        if ($m->tipo === TipoDeEnvio::Lista) {
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $linkDescadastro . '>');
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        return $email;
    }

    /** Se este envio sai com pixel e links rastreados: só `lista`, pedido pelo chamador, com as duas chaves ligadas. */
    public function rastreia(MensagemDeEmail $m): bool
    {
        return $m->rastrear && $m->tipo === TipoDeEnvio::Lista && $this->rastreioLigado && $this->politicaCitaRastreio;
    }

    /** O link de descadastro de todo e-mail: o da própria lista; no transacional, o opt-out total (`todos`, M-k). */
    private function linkDescadastro(MensagemDeEmail $m): string
    {
        $assunto = $m->tipo === TipoDeEnvio::Lista && $m->lista !== null ? $m->lista : DescadastroDeEmailInterface::ASSUNTO_TODOS;

        return $this->descadastro->gerarLink($m->escopo, $m->para, $assunto);
    }

    private function html(MensagemDeEmail $m, string $token, string $linkDescadastro): string
    {
        $rastreia = $this->rastreia($m);
        $linkPolitica = $this->urls->generate(self::ROTA_POLITICA, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $nome = self::primeiroNome($m->nome);
        $html = $this->twig->render(self::TEMPLATE, [
            'assunto' => $m->assunto,
            'selo' => $m->selo,
            'titulo' => $m->titulo === null ? null : str_replace('{nome}', $nome, $m->titulo),
            'corpo' => str_replace('{nome}', htmlspecialchars($nome, ENT_QUOTES), $m->corpoHtml),
            'logo' => $this->base() . self::LOGO,
            'tipo' => $m->tipo->value,
            'escritorio' => $m->escopo === null ? null : (trim((string) $m->escopo->getName()) ?: 'o escritório'),
            'linkDescadastro' => $linkDescadastro,
            'linkPolitica' => $linkPolitica,
            'pixel' => $rastreia ? $this->urls->generate(self::ROTA_ABERTURA, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL) : null,
        ]);

        return $rastreia ? $this->reescreverLinks($html, $token, $linkPolitica) : $html;
    }

    /**
     * Cada `href` http(s) vira o link rastreado, exceto o de descadastro (qualquer um, inclusive o do corpo) e o da
     * Política: esses saem intocados, para o descadastro e a leitura da Política nunca dependerem do rastreio.
     */
    public function reescreverLinks(string $html, string $token, string $linkPolitica): string
    {
        $poupados = [$this->urls->generate(LinkDeDescadastro::ROTA, [], UrlGeneratorInterface::ABSOLUTE_URL), $linkPolitica];

        return (string) preg_replace_callback('/href="(https?:\/\/[^"]+)"/i', function (array $achado) use ($token, $poupados): string {
            $url = html_entity_decode($achado[1], ENT_QUOTES | ENT_HTML5);
            foreach ($poupados as $poupado) {
                if (str_starts_with($url, $poupado)) {
                    return $achado[0];
                }
            }

            return 'href="' . htmlspecialchars($this->linkDeClique($token, $url), ENT_QUOTES) . '"';
        }, $html);
    }

    /** `/e/c/{token}?u=<url>&s=<assinatura>`: a assinatura cobre o caminho e a consulta, não o esquema e o host. */
    private function linkDeClique(string $token, string $url): string
    {
        $caminho = $this->urls->generate(self::ROTA_CLIQUE, ['token' => $token, 'u' => $url], UrlGeneratorInterface::ABSOLUTE_PATH);

        return $this->base() . $this->assinadorDeClique->sign($caminho);
    }

    /** Esquema e host da aplicação (no worker, do `DEFAULT_URI`). */
    private function base(): string
    {
        $absoluto = $this->urls->generate(self::ROTA_POLITICA, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $caminho = $this->urls->generate(self::ROTA_POLITICA, [], UrlGeneratorInterface::ABSOLUTE_PATH);

        return substr($absoluto, 0, \strlen($absoluto) - \strlen($caminho));
    }

    /** Remetente e resposta da plataforma; o `replyTo` da mensagem vence; `nomeDoRemetente` só troca o nome exibido. */
    private function aplicarIdentidade(Email $email, MensagemDeEmail $m): void
    {
        $this->identidade->aplicar($email, $m->assunto);
        if ($m->replyTo !== null) {
            $email->replyTo($m->replyTo);
        }
        $from = $email->getFrom()[0] ?? null;
        if ($m->nomeDoRemetente !== null && trim($m->nomeDoRemetente) !== '' && $from !== null) {
            $email->from(new Address($from->getAddress(), trim($m->nomeDoRemetente)));
        }
    }

    private static function primeiroNome(?string $nome): string
    {
        $partes = preg_split('/\s+/', trim((string) $nome)) ?: [];

        return ($partes[0] ?? '') !== '' ? $partes[0] : 'colega';
    }

    /** A versão texto, gerada do HTML: quebras nos blocos, sem marcação, sem linhas em branco repetidas. */
    private static function texto(string $html): string
    {
        $semCabeca = (string) preg_replace('{<(head|style)\b.*?</\1>}is', '', $html);
        $comQuebras = (string) preg_replace('{<br\s*/?>|</(p|div|tr|li|h[1-6])>}i', "\n", $semCabeca);
        $comLinks = (string) preg_replace('{<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>}is', '$2 ($1)', $comQuebras);
        $texto = html_entity_decode(strip_tags($comLinks), ENT_QUOTES | ENT_HTML5);
        $linhas = array_map('trim', explode("\n", $texto));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $linhas)));
    }
}
