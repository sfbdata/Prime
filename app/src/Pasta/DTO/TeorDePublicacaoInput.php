<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma publicação do Push Processual reduzida ao que a leitura de determinações precisa: de onde
 * veio (id, tipo, data, ID do documento) e o teor bruto, como o DJEN entregou (texto ou HTML).
 *
 * Existe para o `DeterminacoesDoJuizo` ser testável sem banco: o teste monta o teor, o serviço
 * monta estes objetos a partir de `PublicacaoDjen`.
 */
final class TeorDePublicacaoInput
{
    public function __construct(
        public readonly int $publicacaoId,
        public readonly ?string $tipoDocumento,
        public readonly ?string $tipoComunicacao,
        public readonly ?\DateTimeImmutable $data,
        public readonly ?string $idDoDocumento,
        public readonly string $texto,
    ) {
    }

    /** Como a origem aparece na tela: o tipo do documento ("Decisão"), senão o da comunicação. */
    public function rotuloDoTipo(): string
    {
        foreach ([$this->tipoDocumento, $this->tipoComunicacao] as $tipo) {
            if ($tipo !== null && trim($tipo) !== '') {
                return trim($tipo);
            }
        }

        return 'Publicação';
    }
}
