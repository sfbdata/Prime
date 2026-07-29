<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Service;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Exception\ImportacaoIndicesException;

/**
 * Contrato de leitura das séries oficiais, para o importador não depender da implementação HTTP.
 *
 * A indireção existe pelo mesmo motivo da do DJEN: mantém o cliente concreto substituível no teste
 * do command (um serviço privado sem outro consumidor é inlinado pelo container e deixa de ser
 * substituível) e deixa a troca de fonte possível sem tocar no command.
 */
interface ClienteSgsBcbInterface
{
    /**
     * Competências **em ordem crescente** — o importador usa a primeira e a última chave para
     * relatar a cobertura da série, então a ordenação faz parte do contrato, não é detalhe da
     * implementação. Valores como o BCB publica ('0.14', não '0.140000'), mas garantidamente
     * armazenáveis em `numeric(12,6)`.
     *
     * @return array<string, string> competência 'Y-m-01' => variação percentual (string, para BCMath)
     *
     * @throws ImportacaoIndicesException em qualquer falha de rede ou de conteúdo
     */
    public function baixarSerie(SerieIndice $serie): array;
}
