<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O resultado do envio da análise: o aviso do desenho (dc L.5290) e o "Enviado dd/mm às HH:MM" que o
 * cartão da análise passa a mostrar.
 */
final readonly class AnaliseEnviadaAoClienteOutput
{
    /** @param list<string> $enviados */
    public function __construct(
        public array $enviados,
        public bool $comCopia,
        public EnvioDaAnaliseOutput $envio,
    ) {
    }

    public function mensagem(): string
    {
        $n = \count($this->enviados);

        return 'Análise enviada para ' . $n . ($n === 1 ? ' e-mail' : ' e-mails') . ' do cliente'
            . ($this->comCopia ? ', com cópia para a Controladoria' : '');
    }
}
