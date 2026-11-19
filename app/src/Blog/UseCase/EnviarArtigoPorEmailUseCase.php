<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\CadastradosRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Twig\Environment;

/**
 * Artigo novo dos blogs por e-mail (spec blog-artigos-por-email).
 *
 * Quem dispara: a fila `comercial`, com atraso, depois do primeiro "Publicar agora" do artigo
 * ({@see PublicarArtigoDoBlogUseCase}).
 * Objetivo: avisar cada usuário com e-mail confirmado que saiu artigo novo: título, resumo e link.
 * Fluxo: artigo que não está mais publicado não sai; o público é a lista de cadastrados com e-mail confirmado
 * ({@see CadastradosRepository::comEmailConfirmado()}), sem quem já recebeu este artigo (a origem `blog_artigo:<id>` em
 * `comercial_envio`, o que torna a mensagem idempotente) e sem quem saiu da lista `blog_artigos` ou de todos os
 * e-mails da plataforma. Cada e-mail entra pela porta única ({@see CarteiroInterface}), tipo lista: a cota da
 * Hostinger, o rodapé com o descadastro e o List-Unsubscribe vêm de lá.
 * Pós-condição: um envio enfileirado por destinatário; o handler do Comercial entrega dentro da cota.
 */
final class EnviarArtigoPorEmailUseCase
{
    public const LISTA = 'blog_artigos';

    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly CadastradosRepository $cadastrados,
        private readonly EnvioDeEmailRepository $envios,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly CarteiroInterface $carteiro,
        private readonly Environment $twig,
    ) {
    }

    /** @return int quantos e-mails entraram na fila */
    public function executar(int $artigoId): int
    {
        $artigo = $this->artigos->porId($artigoId);
        if ($artigo === null || $artigo->getSituacao() !== SituacaoDoArtigo::Publicado) {
            return 0;
        }

        $origem = self::origem($artigoId);
        $jaEnviados = array_flip($this->envios->destinatariosDaOrigem($origem));
        $pendentes = array_values(array_filter(
            $this->cadastrados->comEmailConfirmado(),
            static fn (array $d): bool => !isset($jaEnviados[$d['email']]),
        ));
        $fora = array_flip($this->descadastro->filtrarDescadastrados(null, array_column($pendentes, 'email'), self::LISTA));

        $quantos = 0;
        foreach ($pendentes as $destinatario) {
            if (isset($fora[$destinatario['email']])) {
                continue;
            }
            $this->carteiro->enviar($this->mensagem($artigo, $destinatario['email'], $destinatario['nome'], $origem));
            ++$quantos;
        }

        return $quantos;
    }

    public static function origem(int $artigoId): string
    {
        return 'blog_artigo:' . $artigoId;
    }

    /**
     * Endereço público do artigo: o Blog BlueJus, ou o BlueJus Grátis quando o artigo é só de lá. O slug é o da versão
     * que está no ar (congelada no Publicar): uma troca de slug salva depois, ainda não publicada, não quebra o link.
     */
    public static function endereco(ArtigoDoBlog $artigo): string
    {
        $pasta = $artigo->getBlogs() === BlogsDoArtigo::Gratis ? 'gratis' : 'blog';
        $slug = (string) ($artigo->getVersaoPublicada()['slug'] ?? $artigo->getSlug());

        return sprintf('https://bluejus.com.br/%s/%s/%s', $pasta, $artigo->getCategoria(), $slug);
    }

    private function mensagem(ArtigoDoBlog $artigo, string $email, string $nome, string $origem): MensagemDeEmail
    {
        $corpo = $this->twig->render('email/blog/artigo.html.twig', [
            'titulo' => $artigo->getTitulo(),
            'resumo' => $artigo->getResumo(),
            'endereco' => self::endereco($artigo),
            'descadastro' => $this->descadastro->gerarLink(null, $email, self::LISTA),
        ]);

        return new MensagemDeEmail(
            para: $email,
            assunto: 'Artigo novo no blog: ' . $artigo->getTitulo(),
            corpoHtml: $corpo,
            tipo: TipoDeEnvio::Lista,
            origem: $origem,
            lista: self::LISTA,
            nome: $nome !== '' ? $nome : null,
            titulo: $artigo->getTitulo(),
            motivoDoEnvio: 'Você recebeu este e-mail porque tem cadastro no BlueJus e avisamos quando sai artigo novo nos blogs.',
        );
    }
}
