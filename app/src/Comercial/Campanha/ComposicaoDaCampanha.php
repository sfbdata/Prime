<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Envio\LayoutDoEmail;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Service\MontadorDeEmail;

/**
 * Transforma a mensagem da campanha no e-mail do Carteiro (spec `comercial-e-emails.md` §8.3 e §8.4): os blocos e o
 * simples pelo {@see RenderizadorDeBlocos} (layout `linhas`), o HTML importado pelo {@see SanitizadorDeHtmlDeEmail}
 * (layout `cru`; o montador sanitiza de novo). Sempre `lista` na lista `comercial_campanhas`.
 *
 * A prévia (dc L.537) usa o nome e o e-mail de exemplo do dc ("Dra. Ana", teste@bluejus.com.br; sem público na 2a) e
 * sai do mesmo montador do envio, com o rodapé legal: o que se vê é o que sai. O nome de exemplo entra inteiro, como no
 * dc (o envio usa o primeiro nome de quem recebe).
 */
final class ComposicaoDaCampanha
{
    public const LISTA = 'comercial_campanhas';
    public const NOME_DE_EXEMPLO = 'Dra. Ana';
    public const EMAIL_DE_EXEMPLO = 'teste@bluejus.com.br';

    public function __construct(
        private readonly RenderizadorDeBlocos $renderizador,
        private readonly SanitizadorDeHtmlDeEmail $sanitizador,
        private readonly MontadorDeEmail $montador,
    ) {
    }

    /** O e-mail para um destinatário (o `{nome}` fica para o montador trocar pelo primeiro nome). */
    public function mensagemPara(MensagemDaCampanha $m, string $para, ?string $nome, string $origem): MensagemDeEmail
    {
        try {
            return new MensagemDeEmail(
                $para,
                $m->assunto,
                $this->corpo($m),
                TipoDeEnvio::Lista,
                $origem,
                self::LISTA,
                nome: $nome,
                selo: $m->selo,
                titulo: $m->titulo,
                layout: self::layout($m),
            );
        } catch (\InvalidArgumentException) {
            throw new CampanhaRecusada('Informe o assunto e o conteúdo do e-mail.');
        }
    }

    /** O HTML da prévia, do "Baixar HTML" e do que o dc copia no "Enviar teste" (L.537, L.558). */
    public function previa(MensagemDaCampanha $m): string
    {
        $nome = htmlspecialchars(self::NOME_DE_EXEMPLO, ENT_QUOTES);
        $corpo = str_replace('{nome}', $nome, $this->corpo($m));
        $email = new MensagemDeEmail(
            self::EMAIL_DE_EXEMPLO,
            trim($m->assunto) !== '' ? $m->assunto : 'Prévia',
            trim($corpo) !== '' ? $corpo : '&nbsp;',
            TipoDeEnvio::Lista,
            'previa_campanha',
            self::LISTA,
            nome: self::NOME_DE_EXEMPLO,
            selo: $m->selo,
            titulo: str_replace('{nome}', self::NOME_DE_EXEMPLO, $m->titulo),
            layout: self::layout($m),
        );

        return (string) $this->montador->montar($email, 'previa')->getHtmlBody();
    }

    private function corpo(MensagemDaCampanha $m): string
    {
        return match ($m->modo) {
            'blocos' => $this->renderizador->blocos($m->blocos),
            'html' => $this->sanitizador->sanitizar($m->html),
            default => $this->renderizador->simples($m->corpo, $m->itens, $m->cta, $m->link),
        };
    }

    private static function layout(MensagemDaCampanha $m): LayoutDoEmail
    {
        return $m->modo === 'html' ? LayoutDoEmail::Cru : LayoutDoEmail::Linhas;
    }
}
