<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PermissoesDoCompartilhamento;

/**
 * O que a janela "Compartilhar pasta" envia (desenho L3): aba Equipe do escritório (`destinoId`) ou Outra conta
 * (`outraConta`, com o `codigo`; o "1 clique" do menu manda o `destinoId` de quem já recebeu por código).
 */
final readonly class CompartilharPastaInput
{
    public function __construct(
        public ?int $destinoId,
        public bool $movimentar = false,
        public bool $enviarDocumentos = false,
        public bool $baixarDocumentos = false,
        public ?\DateTimeImmutable $validoAte = null,
        public ?string $mensagem = null,
        public bool $outraConta = false,
        public ?string $codigo = null,
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
            ($dados['outraConta'] ?? false) === true,
            is_string($dados['codigo'] ?? null) ? $dados['codigo'] : null,
        );
    }

    public function permissoes(): PermissoesDoCompartilhamento
    {
        return new PermissoesDoCompartilhamento($this->movimentar, $this->enviarDocumentos, $this->baixarDocumentos);
    }
}
