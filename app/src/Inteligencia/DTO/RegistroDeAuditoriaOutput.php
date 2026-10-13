<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;

/**
 * Uma linha do "Registro de auditoria" do drawer (desenho, dc L1030-1035: hora + o quê). Só
 * metadados da linha de `inteligencia_analise`: quem pediu, o quê (agente ou Push), quando e o
 * status. Nenhum texto do modelo entra aqui, nem da excluída.
 */
final readonly class RegistroDeAuditoriaOutput
{
    public function __construct(
        public int $analiseId,
        public \DateTimeImmutable $quando,
        public string $oQue,
        public string $status,
        public string $statusRotulo,
        public ?string $solicitanteNome,
        public bool $excluida,
    ) {
    }

    public static function fromEntity(AnaliseDeInteligencia $analise): self
    {
        $status = $analise->getStatus();

        return new self(
            analiseId: (int) $analise->getId(),
            quando: $analise->getCriadaEm(),
            oQue: $analise->getAgente()?->nome() ?? match ($analise->getTipo()) {
                TipoDeAnalise::ResumoPush => 'Análise IA do Push',
                TipoDeAnalise::AnalisePasta => 'Análise de agente',
                TipoDeAnalise::PerguntaLivre => 'Pergunta à IA',
                TipoDeAnalise::ResumoDocumento => 'Resumo de documento',
            },
            status: $status->value,
            statusRotulo: $status->rotulo(),
            solicitanteNome: $analise->getSolicitante()?->getFullName(),
            excluida: $analise->estaExcluida(),
        );
    }
}
