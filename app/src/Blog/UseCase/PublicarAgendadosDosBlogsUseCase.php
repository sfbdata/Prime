<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Entity\ArtigoDoBlog;
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
 * Cada artigo é relido antes de publicar: o que já não está agendado (publicado ou cancelado à mão) ou ainda não
 * venceu é pulado, sem erro.
 * Fluxos alternativos: um artigo que falha não trava os outros. Erro de regra (\DomainException: zod que deixou de
 * valer, slug tomado) volta a rascunho (o agendamento cai); erro de infraestrutura (qualquer outro \Throwable) mantém
 * o artigo agendado para a próxima rodada. Nos dois casos a falha entra no log e no resumo, que o comando devolve como
 * erro para o aviso de falha do agendador chegar ao Master.
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

    /** @return array{publicados: int, falhas: list<string>} falhas: uma frase por artigo que não foi ao ar */
    public function executar(): array
    {
        $publicados = 0;
        $falhas = [];
        foreach ($this->artigos->idsAgendadosVencidos($this->relogio->now()) as $id) {
            // A lista pode ter ficado velha (o Master publicou ou cancelou à mão no meio da rodada): relê cada artigo.
            $artigo = $this->artigos->porId($id);
            if ($artigo === null || !$this->venceu($artigo)) {
                continue;
            }

            try {
                $this->publicar->executar($id, null, MotivoDaPublicacao::Agendado);
                ++$publicados;
            } catch (\DomainException $erro) {
                // Regra: o artigo não passa mais (zod, slug tomado). Não adianta tentar de novo: volta a rascunho.
                $falhas[] = $this->voltarARascunho($id, $erro);
            } catch (\Throwable $erro) {
                // Infraestrutura (banco, fila): o artigo continua agendado e a próxima rodada tenta de novo.
                $falhas[] = $this->tentarDeNovo($id, $erro);
            }
        }

        return ['publicados' => $publicados, 'falhas' => $falhas];
    }

    private function venceu(ArtigoDoBlog $artigo): bool
    {
        return SituacaoDoArtigo::Agendado === $artigo->getSituacao()
            && $artigo->getPublicarEm() !== null
            && $artigo->getPublicarEm() <= $this->relogio->now();
    }

    private function voltarARascunho(int $id, \DomainException $erro): string
    {
        $motivo = $erro instanceof ArtigoDoBlogNaoPublicavel
            ? 'faltou corrigir: ' . implode(' ', array_merge(...array_values($erro->erros)))
            : $erro->getMessage();
        $this->logger->error('Blog: o agendamento do artigo falhou e ele voltou a rascunho.', ['artigoId' => $id, 'erro' => $erro::class, 'motivo' => $motivo]);

        try {
            $this->artigos->recuperarDoErro();
            $artigo = $this->artigos->porId($id);
            if ($artigo !== null && $artigo->getSituacao() === SituacaoDoArtigo::Agendado) {
                $artigo->cancelarAgendamento();
                $this->escopoDePlataforma->executar(fn () => $this->artigos->salvar($artigo, flush: true));
            }
        } catch (\Throwable $segunda) {
            $this->artigos->recuperarDoErro();
            $this->logger->error('Blog: não foi possível devolver o artigo agendado a rascunho.', ['artigoId' => $id, 'erro' => $segunda::class]);
        }

        return sprintf('Artigo %d voltou a rascunho (%s)', $id, $motivo);
    }

    private function tentarDeNovo(int $id, \Throwable $erro): string
    {
        $this->logger->error('Blog: erro ao publicar o artigo agendado; ele continua agendado e será tentado de novo.', ['artigoId' => $id, 'erro' => $erro::class, 'mensagem' => $erro->getMessage()]);
        $this->artigos->recuperarDoErro();

        return sprintf('Artigo %d continua agendado, erro ao publicar: %s', $id, $erro->getMessage());
    }
}
