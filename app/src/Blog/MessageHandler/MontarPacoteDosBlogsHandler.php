<?php

declare(strict_types=1);

namespace App\Blog\MessageHandler;

use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Exception\PacoteDosBlogsVazio;
use App\Blog\Message\MontarPacoteDosBlogs;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Blog\Service\MontadorDoPacoteDosBlogs;
use App\Blog\Service\PastaDosPedidos;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Monta o pacote do pedido e guarda os slugs que entraram (spec painel-artigos-blogs, seção 6.1).
 *
 * Antes de ler os artigos, marca o pedido como em montagem, com flush: um Publicar que chega durante a montagem não o
 * reaproveita (a mudança dele ficaria de fora) e pede outra geração.
 *
 * Idempotente: mensagem de pedido que terminou não faz nada; se a pasta `{id}` já existe (o flush anterior caiu depois
 * do rename), o pedido é dado como montado e os slugs saem do `pedido.json`.
 *
 * Uma falha na montagem (imagem que sumiu do armazenamento, nenhum artigo publicado, disco) marca o pedido como falhou,
 * com o motivo no log, e não sobe para o Messenger: sem isso o pedido ficaria na fila sem ser gerado. O próximo
 * Publicar pede uma geração nova.
 */
#[AsMessageHandler]
final class MontarPacoteDosBlogsHandler
{
    public function __construct(
        private readonly PublicacaoDosBlogsRepository $publicacoes,
        private readonly MontadorDoPacoteDosBlogs $montador,
        private readonly PastaDosPedidos $pasta,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(MontarPacoteDosBlogs $mensagem): void
    {
        $publicacao = $this->publicacoes->porId($mensagem->publicacaoId);
        if ($publicacao === null || !$publicacao->estaAberta() || $publicacao->pacoteMontado()) {
            return;
        }
        if ($this->pasta->existe($mensagem->publicacaoId)) {
            $this->jaMontado($publicacao);

            return;
        }

        $publicacao->marcarEmMontagem();
        $this->gravar($publicacao);
        try {
            $publicacao->registrarPacote($this->montador->montar($publicacao));
        } catch (\Throwable $e) {
            $this->logger->error('Não foi possível montar o pacote dos blogs {id}: {erro}', [
                'id' => $mensagem->publicacaoId,
                'erro' => $e->getMessage(),
                'exception' => $e,
            ]);
            $motivo = $e instanceof PacoteDosBlogsVazio ? $e->getMessage() : 'Não foi possível montar o pacote dos blogs: ' . $e->getMessage();
            $publicacao->falhar($motivo, $this->relogio->now());
        }
        $this->gravar($publicacao);
    }

    /** A pasta já foi renomeada numa tentativa anterior: grava os slugs do `pedido.json`. */
    private function jaMontado(PublicacaoDosBlogs $publicacao): void
    {
        $slugs = $this->pasta->slugsDoPedido((int) $publicacao->getId());
        if ($slugs === null) {
            return; // pedido.json ilegível: a conferência dá o prazo do builder
        }
        $publicacao->registrarPacote($slugs);
        $this->gravar($publicacao);
    }

    private function gravar(PublicacaoDosBlogs $publicacao): void
    {
        $this->escopoDePlataforma->executar(fn () => $this->publicacoes->salvar($publicacao, flush: true));
    }
}
