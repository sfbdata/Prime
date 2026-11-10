<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Enum\SituacaoDeHojeNaEquipe;

/**
 * Uma linha da tabela da aba Equipe (desenho 16 L215; spec `docs/specs/ponto-desenho-0811.md`, E5, P54 e P56 a).
 * `entrada` e `ultimoRegistro` são `null` sem a batida (a tela escreve "Sem registro"); `bancoMinutos` é o mesmo número
 * do topo da /ponto da pessoa; `pendencias`, as dela neste escritório.
 */
final readonly class MembroDaEquipeOutput
{
    /** @param list<OpcaoDeEscalaOutput> $escalas */
    public function __construct(
        public int $userId,
        public string $nome,
        public ?string $subtitulo,
        public SituacaoDeHojeNaEquipe $situacao,
        public ?string $entrada,
        public ?string $ultimoRegistro,
        public int $bancoMinutos,
        public int $pendencias,
        public array $escalas,
    ) {
    }
}
