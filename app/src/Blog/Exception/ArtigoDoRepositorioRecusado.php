<?php

declare(strict_types=1);

namespace App\Blog\Exception;

/**
 * Recusa ao importar um artigo do repositório dos sites (`blog:importar-do-repositorio`, fatia P2): o arquivo não tem
 * o formato esperado, usa algo que o editor do BlueJus ainda não guarda (imagem, código) ou não passa nas regras do zod.
 * O comando mostra a mensagem e segue para o próximo arquivo; nada do artigo recusado é gravado.
 */
final class ArtigoDoRepositorioRecusado extends \DomainException
{
    public static function semFrontmatter(): self
    {
        return new self('O arquivo não começa com o frontmatter entre linhas "---".');
    }

    public static function frontmatterInvalido(string $detalhe): self
    {
        return new self(sprintf('O frontmatter não é um YAML válido: %s', $detalhe));
    }

    public static function slugInvalido(string $slug): self
    {
        return new self(sprintf('Slug inválido no frontmatter: "%s". Use letras minúsculas sem acento e números, separados por hífen.', $slug));
    }

    public static function dataInvalida(string $campo): self
    {
        return new self(sprintf('A data "%s" do frontmatter precisa estar no formato AAAA-MM-DD.', $campo));
    }

    public static function imagemNoCorpo(): self
    {
        return new self('O texto tem imagem. As imagens do corpo ainda não são importadas: tire a imagem do arquivo e envie-a pelo editor depois.');
    }

    public static function foraDoSubconjunto(string $oQue): self
    {
        return new self(sprintf('O texto usa %s, que o editor do blog não guarda. Ajuste o arquivo e rode de novo.', $oQue));
    }

    public static function diretivaDesconhecida(string $linha): self
    {
        return new self(sprintf('Bloco desconhecido no texto: "%s". O blog aceita :::aviso, :::etapas, :::checklist, ::cta-meio e ::leia-tambem{artigo=slug}.', $linha));
    }

    public static function diretivaSemFechamento(string $nome): self
    {
        return new self(sprintf('O bloco :::%s não tem a linha ":::" que o fecha.', $nome));
    }

    public static function leiaTambemInvalido(string $slug): self
    {
        return new self(sprintf('O "Leia também" aponta para um slug inválido: "%s".', $slug));
    }

    /** @param array<string, list<string>> $erros campo => mensagens do ValidadorDoArtigo */
    public static function naoPassaNasRegras(array $erros): self
    {
        $mensagens = [];
        foreach ($erros as $lista) {
            foreach ($lista as $mensagem) {
                $mensagens[] = $mensagem;
            }
        }

        return new self('O artigo não passa nas regras do blog: ' . implode(' ', $mensagens));
    }

    public static function textosDiferentesNosDoisBlogs(string $slug): self
    {
        return new self(sprintf('O artigo "%s" está nos dois blogs com textos diferentes. Deixe os dois arquivos iguais (ou só um) e rode de novo.', $slug));
    }
}
