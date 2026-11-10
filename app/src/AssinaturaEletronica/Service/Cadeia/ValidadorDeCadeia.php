<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

/**
 * A conferência da cadeia das assinaturas de um PDF no contêiner `validador_pdf` (contrato da etapa 6, seção 2,
 * protocolo `v1`; contrato da etapa 5, seção 2, item 7): cadeia até a âncora, revogação, carimbo do tempo, cobertura e
 * modificações. A regra que aceita ou recusa (E6-6) é de quem chama.
 *
 * Sem configuração (E6-4), gov.br e ICP não são liberados no envio: {@see configurado()} é o que o envio consulta.
 * Implementação em uso: o alias em `config/services.yaml`.
 */
interface ValidadorDeCadeia
{
    public const ANCORA_ICP = 'icp';
    public const ANCORA_GOVBR = 'govbr';
    public const ANCORA_QUALQUER = 'qualquer';

    /** Se o endereço e o token do validador estão configurados (não diz se ele está no ar). */
    public function configurado(): bool;

    /**
     * Confere as assinaturas do PDF contra a âncora pedida.
     *
     * @param string $pdf    os bytes do PDF (até 20 MB)
     * @param string $ancora {@see ANCORA_ICP}, {@see ANCORA_GOVBR} ou {@see ANCORA_QUALQUER}
     *
     * @return ResultadoDaCadeia as assinaturas conferidas ou o erro do PDF (`pdf_ilegivel`)
     *
     * @throws CadeiaIndisponivel sem configuração, fora do ar, token recusado, tempo esgotado, ocupado ou resposta fora
     *                            do protocolo
     */
    public function conferir(string $pdf, string $ancora): ResultadoDaCadeia;
}
