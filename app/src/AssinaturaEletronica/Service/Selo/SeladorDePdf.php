<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

/**
 * O selo PAdES do BlueJus no PDF final (spec assinatura-documentos-etapa-8, protocolo da seção 2). O contêiner
 * `validador_pdf` sela por acréscimo incremental (E8-1): o PDF selado começa com os bytes do original.
 *
 * Implementação em uso: a fábrica {@see EscolhaDoSeladorDePdf}, em `config/services.yaml`. Sem a URL ou sem o token do
 * validador, vale a {@see SeladorDePdfNaoConfigurado} e nenhum pedido sai.
 */
interface SeladorDePdf
{
    /**
     * @param string $pdf os bytes do PDF final da etapa 3 (até 20 MB)
     *
     * @return string|null o PDF selado; nulo quando o selo não está configurado (sem URL ou token no PHP, ou
     *                     `503 selo_nao_configurado` no validador)
     *
     * @throws FalhaNoSelo qualquer outra resposta que não seja o PDF selado, com o código e a classe da tabela 2.1
     */
    public function selar(string $pdf): ?string;
}
