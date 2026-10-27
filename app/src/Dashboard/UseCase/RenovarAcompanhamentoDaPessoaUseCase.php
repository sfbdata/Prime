<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\AcompanhamentoDaPessoaOutput;
use App\Dashboard\DTO\GerirAcompanhamentoInput;
use App\Dashboard\Entity\AcompanhamentoDaPessoa;
use App\Dashboard\Exception\AcompanhamentoEmConflitoException;
use App\Dashboard\Exception\AcompanhamentoInvalidoException;
use App\Dashboard\Repository\AcompanhamentoDaPessoaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Renovar" o acompanhamento aberto: novo prazo de revisão (1, 2 ou 7 dias a partir de hoje) na MESMA
 * linha, sem criar outra. Audita `acomp_renov`. As guardas são as de {@see GuardaDoAcompanhamento}; sem
 * acompanhamento aberto → AcompanhamentoEmConflitoException. Nada acontece sozinho no vencimento.
 */
final class RenovarAcompanhamentoDaPessoaUseCase
{
    public function __construct(
        private readonly GuardaDoAcompanhamento $guarda,
        private readonly AcompanhamentoDaPessoaRepository $repository,
        private readonly RegistrarAuditoriaDoAcompanhamentoUseCase $auditoria,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(GerirAcompanhamentoInput $input): AcompanhamentoDaPessoaOutput
    {
        $this->guarda->exigirAlvo($input->tenant, $input->ator, $input->pessoaId);
        if (!in_array($input->prazoEmDias, AcompanhamentoDaPessoa::PRAZOS_EM_DIAS, true)) {
            throw new AcompanhamentoInvalidoException('Escolha um prazo de revisão de 1, 2 ou 7 dias.');
        }
        $acompanhamento = $this->repository->buscarAbertoDaPessoa($input->tenant, $input->pessoaId);
        if ($acompanhamento === null) {
            throw new AcompanhamentoEmConflitoException('Esta pessoa não está em acompanhamento.');
        }

        $this->em->wrapInTransaction(function () use ($acompanhamento, $input): void {
            $acompanhamento->renovar($input->agora, $input->prazoEmDias);
            $this->auditoria->renovou($input->tenant, $input->ator, $acompanhamento);
            $this->em->flush();
        });

        return AcompanhamentoDaPessoaOutput::deEntidade($acompanhamento, $input->agora);
    }
}
