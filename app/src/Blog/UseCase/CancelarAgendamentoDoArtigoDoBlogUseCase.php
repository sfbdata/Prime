<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\ArtigoAgendadoOutput;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * "Cancelar agendamento": o artigo agendado volta a rascunho (a data marcada fica guardada, mas sem efeito).
 *
 * Quem dispara: o Master (o controller exige ROLE_SUPER_ADMIN e o CSRF).
 * Fluxos alternativos: id inexistente (404); artigo que não está agendado (409, \DomainException da entidade).
 */
final class CancelarAgendamentoDoArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /** @throws \DomainException */
    public function executar(int $id): ArtigoAgendadoOutput
    {
        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $artigo->cancelarAgendamento();
        $this->escopoDePlataforma->executar(fn () => $this->artigos->salvar($artigo, flush: true));

        return ArtigoAgendadoOutput::de($artigo);
    }
}
