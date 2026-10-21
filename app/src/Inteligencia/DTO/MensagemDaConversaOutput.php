<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;

/**
 * Uma pergunta da conversa e o que a tela mostra da resposta (spec inteligencia-drawer-da-pasta, F3):
 * o `texto_da_analise` quando concluída (sem ele, o `resumo`, que também é do modelo); em andamento,
 * nada (a tela mostra o cartão "Consultando os dados desta pasta"); falha e indisponível, a falha
 * dita como falha, nunca um texto que finja ser resposta (regra 1 da Inteligência).
 */
final readonly class MensagemDaConversaOutput
{
    public const FALHA = 'Não foi possível responder.';

    public function __construct(
        public int $perguntaId,
        public int $analiseId,
        public string $texto,
        public \DateTimeImmutable $criadaEm,
        public string $status,
        public bool $emAndamento,
        public ?string $resposta,
        public ?string $falha,
    ) {
    }

    public static function fromEntity(PerguntaDaConversa $pergunta): self
    {
        $analise = $pergunta->getAnalise();
        $status = $analise->getStatus();
        $resposta = $status === StatusDaAnalise::Concluida ? ($analise->getTextoDaAnalise() ?? $analise->getResumo()) : null;

        return new self(
            perguntaId: (int) $pergunta->getId(),
            analiseId: (int) $analise->getId(),
            texto: $pergunta->getTexto(),
            criadaEm: $pergunta->getCriadaEm(),
            status: $status->value,
            emAndamento: $status->emAndamento(),
            resposta: $resposta,
            falha: self::falhaDe($status),
        );
    }

    private static function falhaDe(StatusDaAnalise $status): ?string
    {
        return match ($status) {
            StatusDaAnalise::Falhou => self::FALHA,
            StatusDaAnalise::Indisponivel => 'Não foi possível responder: ' . Disponibilidade::NaoConfiguradaNaPlataforma->mensagem() . '.',
            default => null,
        };
    }
}
