<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Message\MontarPacoteDosBlogs;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Entity\Auth\User;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pede uma geração dos blogs (spec painel-artigos-blogs, seção 6.1, "Duas gerações ao mesmo tempo").
 *
 * Se já há um pedido na fila com o pacote ainda por montar, ele é reaproveitado: o worker monta o pacote com os
 * artigos do banco na hora de mandar, então o estado novo já vai junto. Senão, cria um pedido na fila e manda a
 * mensagem {@see MontarPacoteDosBlogs} ao worker.
 *
 * Grava com um flush só, no escopo de plataforma: quem chama muda o artigo antes, e a mudança do artigo e o pedido
 * entram na mesma transação. A mensagem sai depois do COMMIT (o worker não pode ler um pedido que ainda não existe).
 */
final class PedirPublicacaoDosBlogs
{
    public function __construct(
        private readonly PublicacaoDosBlogsRepository $publicacoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function pedir(MotivoDaPublicacao $motivo, ?User $por, \DateTimeImmutable $agora): PublicacaoDosBlogs
    {
        $naFila = $this->publicacoes->naFilaSemPacote();
        $publicacao = $naFila ?? new PublicacaoDosBlogs($agora, $por, $motivo);
        $this->escopoDePlataforma->executar(fn () => $this->publicacoes->salvar($publicacao, flush: true));
        if ($naFila === null) {
            $this->bus->dispatch(new MontarPacoteDosBlogs((int) $publicacao->getId()));
        }

        return $publicacao;
    }
}
