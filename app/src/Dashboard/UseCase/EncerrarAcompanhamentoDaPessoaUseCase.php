<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\AcompanhamentoDaPessoaOutput;
use App\Dashboard\DTO\EncerrarAcompanhamentoInput;
use App\Dashboard\Exception\AcompanhamentoEmConflitoException;
use App\Dashboard\Repository\AcompanhamentoDaPessoaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Encerrar acompanhamento": grava `encerrado_em` e `encerrado_por` (a linha fica para a auditoria) e
 * audita `acomp_fim`. As guardas são as de {@see GuardaDoAcompanhamento}; sem acompanhamento aberto →
 * AcompanhamentoEmConflitoException.
 */
final class EncerrarAcompanhamentoDaPessoaUseCase
{
    public function __construct(
        private readonly GuardaDoAcompanhamento $guarda,
        private readonly AcompanhamentoDaPessoaRepository $repository,
        private readonly RegistrarAuditoriaDoAcompanhamentoUseCase $auditoria,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(EncerrarAcompanhamentoInput $input): AcompanhamentoDaPessoaOutput
    {
        $this->guarda->exigirAlvo($input->tenant, $input->ator, $input->pessoaId);
        $acompanhamento = $this->repository->buscarAbertoDaPessoa($input->tenant, $input->pessoaId);
        if ($acompanhamento === null) {
            throw new AcompanhamentoEmConflitoException('Esta pessoa não está em acompanhamento.');
        }

        $this->em->wrapInTransaction(function () use ($acompanhamento, $input): void {
            $acompanhamento->encerrar($input->ator, $input->agora);
            $this->auditoria->encerrou($input->tenant, $input->ator, $acompanhamento);
            $this->em->flush();
        });

        return AcompanhamentoDaPessoaOutput::semAcompanhamento($input->pessoaId);
    }
}
