<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoPublicavel;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * O agendador dos blogs (spec painel-artigos-blogs, P5): publica os artigos agendados cuja hora chegou.
 *
 * Quem dispara: o comando `blog:publicar-agendados`, a cada 5 minutos pela {@see \App\Shared\Scheduler\AgendaDeTarefas}.
 * Fluxo principal: para cada artigo agendado com publicarEm <= agora, um por vez, pelo mesmo caminho do Publicar agora
 * ({@see PublicarArtigoDoBlogUseCase}), com motivo {@see MotivoDaPublicacao::Agendado} e sem ator. Várias publicações
 * no mesmo ciclo viram um pedido só ao builder (o pedido na fila é reaproveitado).
 * Fluxos alternativos: um artigo que falha (regra do zod que deixou de valer, slug tomado, erro inesperado) não trava
 * os outros: volta a rascunho (o agendamento cai) e a falha entra no log e no resumo, que o comando devolve como erro
 * para o aviso de falha do agendador chegar ao Master.
 */
final class PublicarAgendadosDosBlogsUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly PublicarArtigoDoBlogUseCase $publicar,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{publicados: int, falhas: list<string>} falhas: uma frase por artigo que voltou a rascunho */
    public function executar(): array
    {
        $publicados = 0;
        $falhas = [];
        foreach ($this->artigos->idsAgendadosVencidos($this->relogio->now()) as $id) {
            try {
                $this->publicar->executar($id, null, MotivoDaPublicacao::Agendado);
                ++$publicados;
            } catch (\Throwable $erro) {
                $falhas[] = $this->voltarARascunho($id, $erro);
            }
        }

        return ['publicados' => $publicados, 'falhas' => $falhas];
    }

    private function voltarARascunho(int $id, \Throwable $erro): string
    {
        $motivo = $erro instanceof ArtigoDoBlogNaoPublicavel
            ? 'faltou corrigir: ' . implode(' ', array_merge(...array_values($erro->erros)))
            : $erro->getMessage();
        $this->logger->error('Blog: o agendamento do artigo falhou e ele voltou a rascunho.', ['artigoId' => $id, 'erro' => $erro::class, 'motivo' => $motivo]);

        try {
            // A falha pode ter vindo no meio do flush: o estado do EntityManager não é confiável, relê o artigo.
            $this->artigos->esquecerTudo();
            $artigo = $this->artigos->porId($id);
            if ($artigo !== null && $artigo->getSituacao() === SituacaoDoArtigo::Agendado) {
                $artigo->cancelarAgendamento();
                $this->escopoDePlataforma->executar(fn () => $this->artigos->salvar($artigo, flush: true));
            }
        } catch (\Throwable $segunda) {
            $this->logger->error('Blog: não foi possível devolver o artigo agendado a rascunho.', ['artigoId' => $id, 'erro' => $segunda::class]);
        }

        return sprintf('Artigo %d voltou a rascunho (%s)', $id, $motivo);
    }
}
