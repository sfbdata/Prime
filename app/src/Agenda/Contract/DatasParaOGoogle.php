<?php

declare(strict_types=1);

namespace App\Agenda\Contract;

use App\Agenda\Entity\ConexaoGoogleAgenda;

/**
 * As datas do sistema que vão à agenda do escritório no Google de uma conexão (spec agenda-google §9). Contrato para os
 * testes da sincronia de compromissos e prazos poderem trabalhar sem as dezenas de datas do ano.
 */
interface DatasParaOGoogle
{
    /** @return array<string, array{data: string, titulo: string, observacao: ?string}> chave da data na Agenda => data */
    public function daConexao(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $de, \DateTimeImmutable $ate): array;
}
