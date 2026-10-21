<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
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
 * A prévia (dc L.537) usa o nome e o e-mail do primeiro destinatário do público escolhido (o primeiro nome, como o dc);
 * sem público, ou sem nome, os de exemplo do dc ("Dra. Ana", teste@bluejus.com.br). Sai do mesmo montador do envio,
 * com o rodapé legal: o que se vê é o que sai. O nome de exemplo entra inteiro, como no dc.
 *
 * A campanha (2b) congela uma cópia do conteúdo ({@see self::congelar()}) e o expansor a descongela a cada lote
 * ({@see self::descongelar()}): editar ou excluir o modelo depois não muda o que sai.
 *
 * @phpstan-import-type Campanha from \App\Comercial\Repository\CampanhaDeEmailRepository
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

    /**
     * O e-mail para um destinatário (o `{nome}` fica para o montador trocar pelo primeiro nome). A campanha pede o
     * rastreio (só vale com as duas chaves da 1a) e o reply-to da {@see PoliticaDeDestinatarios}; o teste, não.
     */
    public function mensagemPara(MensagemDaCampanha $m, string $para, ?string $nome, string $origem, bool $rastrear = false, ?string $replyTo = null): MensagemDeEmail
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
                replyTo: $replyTo,
                rastrear: $rastrear,
                layout: self::layout($m),
            );
        } catch (\InvalidArgumentException) {
            throw new CampanhaRecusada('Informe o assunto e o conteúdo do e-mail.');
        }
    }

    /**
     * O HTML da prévia, do "Baixar HTML" e do que o dc copia no "Enviar teste" (L.537, L.558). `exemplo` é o primeiro
     * destinatário do público escolhido (dc L.537: o primeiro nome dele, ou "Dra. Ana" sem nome; o e-mail dele).
     */
    /** `$nomeDaPrevia` (dc 21.10 L270, "NOME NA PRÉVIA, substitui {nome} só aqui") vale como foi digitado, só na prévia. */
    public function previa(MensagemDaCampanha $m, ?DestinatarioDaCampanha $exemplo = null, string $nomeDaPrevia = ''): string
    {
        [$nomeDeExemplo, $para] = self::exemplo($exemplo);
        $digitado = mb_substr(trim($nomeDaPrevia), 0, 60);
        $nomeDeExemplo = $digitado !== '' ? $digitado : $nomeDeExemplo;
        $nome = htmlspecialchars($nomeDeExemplo, ENT_QUOTES);
        $corpo = str_replace('{nome}', $nome, $this->corpo($m));
        $email = new MensagemDeEmail(
            $para,
            trim($m->assunto) !== '' ? $m->assunto : 'Prévia',
            trim($corpo) !== '' ? $corpo : '&nbsp;',
            TipoDeEnvio::Lista,
            'previa_campanha',
            self::LISTA,
            nome: $nomeDeExemplo,
            selo: $m->selo,
            titulo: str_replace('{nome}', $nomeDeExemplo, $m->titulo),
            layout: self::layout($m),
        );

        return (string) $this->montador->montar($email, 'previa')->getHtmlBody();
    }

    /** A cópia do conteúdo que a campanha guarda (`comercial_campanha.conteudo`): o HTML já sanitizado, ou o JSON. */
    public function congelar(MensagemDaCampanha $m): string
    {
        return ModeloDaGrade::conteudoParaGravar($m, $m->modo === 'html' ? $this->sanitizador->sanitizar($m->html) : '');
    }

    /**
     * A mensagem da campanha a partir da cópia guardada (validada de novo, como a tela mandaria).
     *
     * @param Campanha $campanha
     */
    public function descongelar(array $campanha): MensagemDaCampanha
    {
        $base = [
            'chave' => $campanha['modelo_chave'], 'nome' => $campanha['modelo_nome'], 'modo' => $campanha['modo'],
            'assunto' => $campanha['assunto'], 'selo' => $campanha['selo'], 'titulo' => $campanha['titulo'],
        ];
        if ($campanha['modo'] === 'html') {
            return MensagemDaCampanha::deArray($base + ['html' => $campanha['conteudo']]);
        }
        $conteudo = json_decode($campanha['conteudo'], true);
        $conteudo = \is_array($conteudo) ? $conteudo : [];

        return MensagemDaCampanha::deArray($base + ($campanha['modo'] === 'blocos' ? ['blocos' => array_values($conteudo)] : $conteudo));
    }

    /** @return array{0: string, 1: string} o nome e o e-mail da prévia */
    private static function exemplo(?DestinatarioDaCampanha $exemplo): array
    {
        if ($exemplo === null || !$exemplo->valido()) {
            return [self::NOME_DE_EXEMPLO, self::EMAIL_DE_EXEMPLO];
        }
        $primeiroNome = explode(' ', $exemplo->nome)[0];

        return [$primeiroNome !== '' ? $primeiroNome : self::NOME_DE_EXEMPLO, $exemplo->email];
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
