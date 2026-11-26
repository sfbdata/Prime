<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\ArtigoAgendadoOutput;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogNaoPublicavel;
use App\Blog\Exception\ArtigoDoBlogRecusado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * "Agendar" (spec painel-artigos-blogs, seção 1): marca a data e a hora (Brasília) em que o artigo vai ao ar.
 *
 * Quem dispara: o Master, na lista ou no editor (o controller exige ROLE_SUPER_ADMIN e o CSRF).
 * Pré-condições: o artigo salvo passa nas mesmas regras de Publicar ({@see VerificarArtigoDoBlogUseCase} e slug que
 * não é de outro artigo), porque o agendador publica sozinho, sem ninguém para corrigir; e a data é futura.
 * Fluxo principal: {@see \App\Blog\Entity\ArtigoDoBlog::agendar()} e grava no escopo de plataforma. Quem publica na
 * hora é o agendador ({@see PublicarAgendadosDosBlogsUseCase}, comando `blog:publicar-agendados`).
 * Fluxos alternativos: id inexistente (404); regras do zod (422 com os erros por campo); data passada (422, campo
 * `publicarEm`); artigo que não é rascunho nem agendado (409, \DomainException da entidade).
 */
final class AgendarArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly VerificarArtigoDoBlogUseCase $verificar,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws ArtigoDoBlogNaoPublicavel|ArtigoDoBlogRecusado|\DomainException */
    public function executar(int $id, \DateTimeImmutable $publicarEm): ArtigoAgendadoOutput
    {
        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $agora = $this->relogio->now();
        if ($publicarEm <= $agora) {
            throw new ArtigoDoBlogRecusado('Escolha uma data e uma hora no futuro.', 'publicarEm');
        }

        $erros = $this->verificar->executar($id);
        if ($this->artigos->existeSlug($artigo->getSlug(), $id)) {
            $erros['slug'][] = sprintf('O slug "%s" é de outro artigo (ou da versão que está no ar de outro artigo).', $artigo->getSlug());
        }
        if ($erros !== []) {
            throw new ArtigoDoBlogNaoPublicavel($erros);
        }

        $artigo->agendar($publicarEm, $agora);
        $this->escopoDePlataforma->executar(fn () => $this->artigos->salvar($artigo, flush: true));

        return ArtigoAgendadoOutput::de($artigo);
    }
}
