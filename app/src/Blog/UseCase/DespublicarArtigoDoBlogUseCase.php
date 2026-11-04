<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\ArtigoPublicadoOutput;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\PedirPublicacaoDosBlogs;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Despublicar" (spec painel-artigos-blogs, seções 1 e 6.1): tira do ar um artigo publicado.
 *
 * Quem dispara: o Master, no editor (o controller exige ROLE_SUPER_ADMIN e o CSRF).
 * Fluxo principal: {@see \App\Blog\Entity\ArtigoDoBlog::despublicar()} e o pedido de geração dos blogs
 * ({@see PedirPublicacaoDosBlogs}): o pacote leva só os publicados, e o `rsync --delete` do builder tira a página.
 * Fluxos alternativos: id inexistente (404); artigo que não está publicado (409, \DomainException da entidade).
 * Pós-condições: artigo despublicado (o texto e as datas ficam; a versão publicada é zerada) e um pedido na fila. Depois disso ele pode ser excluído.
 */
final class DespublicarArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly PedirPublicacaoDosBlogs $pedir,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws \DomainException */
    public function executar(int $id, ?User $ator): ArtigoPublicadoOutput
    {
        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $artigo->despublicar();
        $publicacao = $this->pedir->pedir(MotivoDaPublicacao::Despublicar, $ator, $this->relogio->now());

        return ArtigoPublicadoOutput::de($artigo, $publicacao);
    }
}
