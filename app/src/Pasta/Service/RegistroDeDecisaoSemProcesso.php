<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\DecisaoSemProcessoDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\DecisaoSemProcessoDaPastaRepository;
use Psr\Clock\ClockInterface;

/**
 * O aprendizado do relatório de pastas sem processo (desenho 1.2.3, `spMarcar` L4510): ao marcar ou
 * desmarcar "Administrativo sem processo", se a análise da pasta achou termos judiciais, a equipe
 * acabou de decidir sobre eles. Marcar grava `administrativo` (os termos não bastaram para ser
 * judicial), desmarcar grava `precisa`. Uma decisão por pasta: a nova sobrescreve.
 *
 * Grava por upsert no banco (não pelo flush do ORM): uma corrida no UNIQUE da pasta nunca derruba a
 * marcação de "Administrativo sem processo" que chamou isto.
 */
final class RegistroDeDecisaoSemProcesso
{
    public function __construct(
        private readonly ColetorDePastasSemProcesso $coletor,
        private readonly AnalisadorDePastasSemProcesso $analisador,
        private readonly DecisaoSemProcessoDaPastaRepository $decisaoRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function registrar(Pasta $pasta, bool $administrativa, User $por, Tenant $tenant): void
    {
        $termos = $this->analisador->termosJudiciais($this->coletor->fontesDaPasta($pasta, $tenant));
        if ($termos === []) {
            return;
        }

        $decisao = $administrativa ? DecisaoSemProcessoDaPasta::ADMINISTRATIVO : DecisaoSemProcessoDaPasta::PRECISA;
        $this->decisaoRepository->gravarDecisao($pasta, $tenant, $decisao, $termos, $por, $this->clock->now());
    }
}
