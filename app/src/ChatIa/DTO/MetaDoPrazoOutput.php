<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

/**
 * Uma meta na consulta de prazos e metas do chat: o que a linha da tela Minhas Metas mostra (número, título, pasta, cliente,
 * prazo, prioridade e o "quem" da coluna da aba). Valores CRUS do banco: quem os põe no prompt passa cada um pela proteção do
 * chat (neutraliza e mascara). `dias` < 0 = atrasada; 0 = vence hoje.
 */
final readonly class MetaDoPrazoOutput
{
    /** @param list<string> $quem os nomes da coluna "quem" da aba: quem delegou (aba Sou responsável) ou os responsáveis */
    public function __construct(
        public ?int $numero,
        public string $titulo,
        public string $pasta,
        public string $cliente,
        public \DateTimeImmutable $prazo,
        public int $dias,
        public string $prioridade,
        public array $quem,
    ) {
    }
}
