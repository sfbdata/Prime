<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O que o editor da Central recebe para abrir uma campanha em modo edição (pedido do Farlei M5.3): a cópia congelada no
 * formato do POST do editor (`modelo`), a data do próximo envio como o `datetime-local` (`AAAA-MM-DDTHH:MM`, vazia se não
 * há data futura), a frequência, o estado e o nome (o assunto).
 */
final class EdicaoDaCampanha
{
    /** @param array<string, mixed> $modelo */
    public function __construct(
        public readonly array $modelo,
        public readonly string $agendar,
        public readonly string $frequencia,
        public readonly string $estado,
        public readonly string $nome,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return ['modelo' => $this->modelo, 'agendar' => $this->agendar, 'frequencia' => $this->frequencia, 'estado' => $this->estado, 'nome' => $this->nome];
    }
}
