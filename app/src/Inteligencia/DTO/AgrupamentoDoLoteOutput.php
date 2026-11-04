<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O agrupamento sugerido pela IA, já validado pelo servidor e traduzido de volta para caminhos (a tela não conhece
 * os números da lista enviada). Cada caso é UMA pasta do computador; os arquivos dela são os de caminho que começa
 * com `pasta + '/'`. `soltos` são os arquivos enviados que não ficaram em caso; `enviados` é quantas linhas foram ao
 * modelo e `truncado` diz que o lote passou do teto.
 */
final readonly class AgrupamentoDoLoteOutput
{
    /**
     * @param list<array{pasta: string, cliente: string, mesmoClienteQue: ?string, motivo: string}> $casos
     * @param list<string>                                                                         $soltos
     * @param list<array{caminho: string, texto: string}>                                          $duvidas
     */
    public function __construct(
        public array $casos,
        public array $soltos,
        public array $duvidas,
        public bool $truncado = false,
        public int $enviados = 0,
    ) {
    }

    public function comEnvio(bool $truncado, int $enviados): self
    {
        return new self($this->casos, $this->soltos, $this->duvidas, $truncado, $enviados);
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'status' => 'ok',
            'casos' => $this->casos,
            'soltos' => $this->soltos,
            'duvidas' => $this->duvidas,
            'truncado' => $this->truncado,
            'enviados' => $this->enviados,
        ];
    }
}
