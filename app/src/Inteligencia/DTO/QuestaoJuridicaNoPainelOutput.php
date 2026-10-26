<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Juridico\QuestaoJuridica;

/** Uma questão jurídica como o bloco do Master a mostra: o conteúdo do catálogo, o status e o histórico. */
final readonly class QuestaoJuridicaNoPainelOutput
{
    /**
     * @param list<MudancaDeStatusDaQuestaoOutput> $historico a mais recente primeiro
     */
    public function __construct(
        public QuestaoJuridica $questao,
        public StatusDaQuestaoJuridica $status,
        public array $historico,
    ) {
    }

    public function resolvida(): bool
    {
        return $this->status === StatusDaQuestaoJuridica::Resolvida;
    }
}
