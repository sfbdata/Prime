<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * A janela "Pasta recebida N" (L11; desenho 03, dc L.513-516): de quem veio, cliente, ação, o que a pessoa pode e
 * quantas metas abertas a pasta tem. `metas` nulo = a aba Metas está oculta para quem recebeu (a linha some da janela).
 */
final readonly class ResumoDaPastaRecebidaOutput
{
    public function __construct(
        public int $id,
        public string $nup,
        public string $cliente,
        public string $acao,
        public string $de,
        public string $conta,
        public bool $movimentar,
        public bool $enviarDocumentos,
        public bool $baixarDocumentos,
        public ?int $metas,
        public bool $soLeitura,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'id'               => $this->id,
            'nup'              => $this->nup,
            'cliente'          => $this->cliente,
            'acao'             => $this->acao,
            'de'               => $this->de,
            'conta'            => $this->conta,
            'movimentar'       => $this->movimentar,
            'enviarDocumentos' => $this->enviarDocumentos,
            'baixarDocumentos' => $this->baixarDocumentos,
            'metas'            => $this->metas,
            'soLeitura'        => $this->soLeitura,
        ];
    }
}
