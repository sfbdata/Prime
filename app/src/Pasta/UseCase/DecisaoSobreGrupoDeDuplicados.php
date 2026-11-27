<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

/**
 * O que a limpeza de duplicados decidiu para UM grupo (mesmo `sha256` na mesma pasta). Só ids e
 * tamanhos: nunca nome de cliente nem de arquivo (vai para o CSV e para o log do operador).
 */
final readonly class DecisaoSobreGrupoDeDuplicados
{
    public const MAIS_ANTIGA         = 'sem_vinculo_fica_a_mais_antiga';
    public const COM_VINCULO         = 'fica_a_copia_com_vinculo';
    public const VARIOS_VINCULOS     = 'pulado_mais_de_uma_copia_com_vinculo';
    public const NAO_ENCONTRADO      = 'pulado_documento_mudou_durante_a_rodada';

    /**
     * @param list<int> $enviadosIds documentos que vão (ou iriam, na simulação) para a lixeira; vazio se pulado
     */
    public function __construct(
        public int $tenantId,
        public int $pastaId,
        public string $motivo,
        public ?int $mantidoId,
        public array $enviadosIds,
        public int $bytesEnviados,
    ) {
    }

    public function foiPulado(): bool
    {
        return $this->enviadosIds === [];
    }
}
