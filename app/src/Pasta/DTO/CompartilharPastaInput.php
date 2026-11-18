<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PermissoesDoCompartilhamento;

/** O que a janela "Compartilhar pasta" envia, aba Equipe do escritório (desenho L3). */
final readonly class CompartilharPastaInput
{
    public function __construct(
        public ?int $destinoId,
        public bool $movimentar = false,
        public bool $enviarDocumentos = false,
        public bool $baixarDocumentos = false,
        public ?\DateTimeImmutable $validoAte = null,
        public ?string $mensagem = null,
    ) {
    }

    /** @param array<string, mixed> $dados o corpo JSON da janela */
    public static function doJson(array $dados): self
    {
        $validade = is_string($dados['validoAte'] ?? null) && $dados['validoAte'] !== ''
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $dados['validoAte']) : null;

        return new self(
            is_numeric($dados['destinoId'] ?? null) ? (int) $dados['destinoId'] : null,
            ($dados['movimentar'] ?? false) === true,
            ($dados['enviarDocumentos'] ?? false) === true,
            ($dados['baixarDocumentos'] ?? false) === true,
            $validade === false ? null : $validade,
            is_string($dados['mensagem'] ?? null) ? $dados['mensagem'] : null,
        );
    }

    public function permissoes(): PermissoesDoCompartilhamento
    {
        return new PermissoesDoCompartilhamento($this->movimentar, $this->enviarDocumentos, $this->baixarDocumentos);
    }
}
