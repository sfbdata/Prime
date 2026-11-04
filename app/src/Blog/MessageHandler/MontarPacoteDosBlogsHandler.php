<?php

declare(strict_types=1);

namespace App\Blog\MessageHandler;

use App\Blog\Message\MontarPacoteDosBlogs;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Blog\Service\MontadorDoPacoteDosBlogs;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Monta o pacote do pedido e guarda os slugs que entraram (spec painel-artigos-blogs, seção 6.1).
 *
 * Só monta pedido na fila sem pacote: mensagem repetida, ou de pedido que já terminou (30 minutos sem resposta), não
 * faz nada. Uma falha na montagem (imagem que sumiu do armazenamento, disco) marca o pedido como falhou, com o motivo
 * no log, e não sobe para o Messenger: sem isso o pedido ficaria na fila para sempre, e todo Publicar seguinte o
 * reaproveitaria sem gerar nada. O próximo Publicar pede uma geração nova.
 */
#[AsMessageHandler]
final class MontarPacoteDosBlogsHandler
{
    public function __construct(
        private readonly PublicacaoDosBlogsRepository $publicacoes,
        private readonly MontadorDoPacoteDosBlogs $montador,
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

        try {
            $publicacao->registrarPacote($this->montador->montar($publicacao));
        } catch (\Throwable $e) {
            $this->logger->error('Não foi possível montar o pacote dos blogs {id}: {erro}', [
                'id' => $mensagem->publicacaoId,
                'erro' => $e->getMessage(),
                'exception' => $e,
            ]);
            $publicacao->falhar('Não foi possível montar o pacote dos blogs: ' . $e->getMessage(), $this->relogio->now());
        }

        $this->escopoDePlataforma->executar(fn () => $this->publicacoes->salvar($publicacao, flush: true));
    }
}
