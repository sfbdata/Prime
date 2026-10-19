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
 * Só persiste: o flush é do UseCase que chamou (uma transação com a marcação).
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
        $atual   = $this->decisaoRepository->daPasta($pasta, $tenant);
        if ($atual !== null) {
            $atual->redefinir($decisao, $termos, $por, $this->clock->now());

            return;
        }

        $this->decisaoRepository->salvar(new DecisaoSemProcessoDaPasta($tenant, $pasta, $decisao, $termos, $por, $this->clock->now()));
    }
}
