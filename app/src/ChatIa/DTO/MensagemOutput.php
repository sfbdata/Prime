<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Entity\MensagemDeChat;

/** Uma fala na leitura da conversa: `{id, papel, conteudo, pasta?: {id, nup}, anexos: [{id, nome}], interrompida, criadaEm}`. */
final readonly class MensagemOutput
{
    /**
     * @param list<AnexoOutput> $anexos
     */
    public function __construct(
        public int $id,
        public string $papel,
        public string $conteudo,
        public ?int $pastaId,
        public ?string $pastaNup,
        public array $anexos,
        public bool $interrompida,
        public string $criadaEm,
    ) {
    }

    /** @param list<AnexoOutput> $anexos */
    public static function de(MensagemDeChat $mensagem, array $anexos): self
    {
        $pasta = $mensagem->getPasta();

        return new self(
            (int) $mensagem->getId(),
            $mensagem->getPapel()->value,
            $mensagem->getConteudo(),
            $pasta?->getId(),
            $pasta !== null ? (string) $pasta->getNup() : null,
            $anexos,
            $mensagem->foiInterrompida(),
            $mensagem->getCriadaEm()->format(\DATE_ATOM),
        );
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        $saida = [
            'id' => $this->id,
            'papel' => $this->papel,
            'conteudo' => $this->conteudo,
            'anexos' => array_map(static fn (AnexoOutput $a): array => ['id' => $a->id, 'nome' => $a->nome], $this->anexos),
            'interrompida' => $this->interrompida,
            'criadaEm' => $this->criadaEm,
        ];
        if ($this->pastaId !== null) {
            $saida['pasta'] = ['id' => $this->pastaId, 'nup' => $this->pastaNup];
        }

        return $saida;
    }
}
