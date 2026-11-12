<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\EstadoDaPauta;

/**
 * Uma pauta na cédula do morador (spec docs/specs/voto-assembleia-condominio.md, seção 1, Morador): o estado, quando a
 * unidade votou (nunca em quê), as opções só enquanto aberta e o resultado só depois de encerrada.
 */
final readonly class PautaNaCedulaOutput
{
    /**
     * @param list<OpcaoNaCedulaOutput> $opcoes            opções de voto pelo número e a abstenção por último; vazia fora de "aberta"
     * @param int|null                  $segundosAtePrazo  segundos até o prazo da pauta aberta; null sem prazo
     */
    public function __construct(
        public int $id,
        public string $titulo,
        public ?string $descricao,
        public EstadoDaPauta $estado,
        public ?\DateTimeImmutable $votouEm,
        public ?int $segundosAtePrazo,
        public array $opcoes,
        public ?ResultadoDaPauta $resultado,
        /** Quantos nomes a pauta elege: o eleitor escolhe até esse tanto. */
        public int $vagas = 1,
        /** Dígitos do número de cada opção (o número se mostra com zeros à esquerda). */
        public int $digitos = 1,
    ) {
    }

    public function variasVagas(): bool
    {
        return $this->vagas > 1;
    }

    public function aguardando(): bool
    {
        return EstadoDaPauta::Aguardando === $this->estado;
    }

    public function aberta(): bool
    {
        return EstadoDaPauta::Aberta === $this->estado;
    }

    public function encerrada(): bool
    {
        return EstadoDaPauta::Encerrada === $this->estado;
    }
}
