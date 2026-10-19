<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O resultado do envio da análise: o aviso do desenho (dc L.5290) e o "Enviado dd/mm às HH:MM" que o
 * cartão da análise passa a mostrar. `jaEnviados` são os destinatários ignorados pela idempotência
 * (a mesma análise para o mesmo endereço nos últimos 2 minutos).
 */
final readonly class AnaliseEnviadaAoClienteOutput
{
    /**
     * @param list<string> $enviados
     * @param list<string> $jaEnviados
     */
    public function __construct(
        public array $enviados,
        public bool $comCopia,
        public EnvioDaAnaliseOutput $envio,
        public array $jaEnviados = [],
    ) {
    }

    public function mensagem(): string
    {
        $n = \count($this->enviados);
        if ($n === 0) {
            return 'Esta análise já foi enviada a estes e-mails há menos de 2 minutos.';
        }

        return 'Análise enviada para ' . $n . ($n === 1 ? ' e-mail' : ' e-mails') . ' do cliente'
            . ($this->comCopia ? ', com cópia para a Controladoria' : '');
    }
}
