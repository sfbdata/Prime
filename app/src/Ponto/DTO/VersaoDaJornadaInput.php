<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\BlocoJornadaColaborador;

/**
 * Uma versão da jornada pessoal a gravar (spec `docs/specs/ponto-jornada-vigencia.md` §10.2). Sem `versaoId` é o modo
 * "nova versão": cria uma versão na data, ou atualiza a que já começa nela. Com `versaoId`, edita aquela versão.
 * Os blocos já chegam validados pelo controller (dias, horários, soma igual à carga).
 */
final readonly class VersaoDaJornadaInput
{
    /**
     * @param list<BlocoJornadaColaborador> $blocos
     * @param ?string                       $escala o rótulo da escala do catálogo, quando a versão vem da troca na aba
     *                                              Equipe ("44h · seg a sáb (7h20)"): vai para a trilha (desenho L778)
     */
    public function __construct(
        public \DateTimeImmutable $valeAPartirDe,
        public int $cargaHorariaSemanal,
        public bool $alertaHabilitado,
        public array $blocos,
        public ?int $versaoId = null,
        public ?string $escala = null,
    ) {
    }
}
