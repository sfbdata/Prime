<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * Uma página da aba Auditoria do Ponto (E9): os eventos já em texto de tela (o mais recente primeiro), a página e o
 * total, e a conferência da cadeia para o cabeçalho.
 */
final class PaginaDaTrilhaDoPontoOutput
{
    /**
     * @param list<array{sequencia: int, dataHora: string, autor: string, acao: string, detalhe: string,
     *     selo: ?string, seloCurto: ?string}> $eventos
     */
    public function __construct(
        public readonly array $eventos,
        public readonly int $pagina,
        public readonly int $paginas,
        public readonly int $total,
        public readonly ConferenciaDaTrilhaDoPontoOutput $conferencia,
    ) {
    }

    public function temAnterior(): bool
    {
        return $this->pagina > 1;
    }

    public function temProxima(): bool
    {
        return $this->pagina < $this->paginas;
    }
}
