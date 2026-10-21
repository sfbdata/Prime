<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma linha do cartão "Avisar quando chegar" (dc L.2631-2635): rótulo exato do desenho, o estado da
 * chave e, quando a chave não pode mudar por motivo DELA (e-mail sem o aviso ao responsável, IA
 * indisponível), `travado` com a dica do motivo real. A trava por permissão ou lápide é da tela.
 */
final readonly class ItemDoAvisoDoPushOutput
{
    public function __construct(
        public string $chave,
        public string $rotulo,
        public bool $ligado,
        public bool $travado,
        public ?string $dica,
    ) {
    }

    /** @return array{chave: string, ligado: bool, travado: bool, dica: ?string} */
    public function paraJson(): array
    {
        return ['chave' => $this->chave, 'ligado' => $this->ligado, 'travado' => $this->travado, 'dica' => $this->dica];
    }
}
