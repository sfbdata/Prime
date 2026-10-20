<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que o modal "Enviar ao cliente" manda (desenho, dc L.2836-2863): a análise, os destinatários
 * escolhidos, o assunto e a mensagem revisados e o checkbox "Revisei e corrigi o conteúdo".
 */
final readonly class EnviarAnaliseAoClienteInput
{
    /** @param list<string> $para */
    public function __construct(
        public int $analiseId,
        public array $para,
        public string $assunto,
        public string $corpo,
        public bool $revisado,
    ) {
    }
}
