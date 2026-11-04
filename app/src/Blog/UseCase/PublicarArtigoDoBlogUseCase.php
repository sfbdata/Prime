<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\ArtigoPublicadoOutput;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogNaoPublicavel;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\PedirPublicacaoDosBlogs;
use App\Blog\Service\ValidadorDoArtigo;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Publicar agora" (spec painel-artigos-blogs, seções 1, 2.2 e 6.1).
 *
 * Quem dispara: o Master, no editor (o controller exige ROLE_SUPER_ADMIN e o CSRF).
 * Objetivo: pôr o artigo no ar. Rascunho, agendado, despublicado ou publicado com alterações viram publicado.
 * Pré-condições: o artigo salvo passa em todas as regras do zod ({@see ValidadorDoArtigo}), menos a da data: Publicar
 * agora dá a data ao artigo (a de agora, ou a marcada, se já passou).
 * Fluxo principal: valida; {@see \App\Blog\Entity\ArtigoDoBlog::publicar()} (o `publicadoEm` e o `atualizadoEm` seguem
 * a entidade: `atualizadoEm` só muda em republicação com texto novo); pede a geração dos blogs
 * ({@see PedirPublicacaoDosBlogs}, que grava tudo num flush só, no escopo de plataforma).
 * Fluxos alternativos: id inexistente (404); regras do zod (422 com os erros por campo); artigo publicado sem
 * alterações (409, \DomainException da entidade).
 * Pós-condições: artigo publicado e um pedido na fila; o site muda quando o builder terminar.
 */
final class PublicarArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ValidadorDoArtigo $validador,
        private readonly PedirPublicacaoDosBlogs $pedir,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws ArtigoDoBlogNaoPublicavel|\DomainException */
    public function executar(int $id, ?User $ator): ArtigoPublicadoOutput
    {
        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $erros = $this->validador->validar($artigo, $this->artigos->slugsExistentes());
        unset($erros['publicarEm']);
        if ($erros !== []) {
            throw new ArtigoDoBlogNaoPublicavel($erros);
        }

        $agora = $this->relogio->now();
        $artigo->publicar($agora);
        $publicacao = $this->pedir->pedir(MotivoDaPublicacao::Publicar, $ator, $agora);

        return ArtigoPublicadoOutput::de($artigo, $publicacao);
    }
}
