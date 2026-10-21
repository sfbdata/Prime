<?php

declare(strict_types=1);

namespace App\Tests\Agenda;

use App\Agenda\Contract\DatasParaOGoogle;
use App\Agenda\Entity\ConexaoGoogleAgenda;

/** Sem datas do sistema: os testes de compromissos e prazos no Google contam só o que é deles. */
final class DatasParaOGoogleFalsas implements DatasParaOGoogle
{
    /** @param array<string, array{data: string, titulo: string, observacao: ?string}> $datas */
    public function __construct(public array $datas = [])
    {
    }

    public function daConexao(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        return $this->datas;
    }
}
