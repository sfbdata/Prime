<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\AcompanhamentoDaPessoaOutput;
use App\Dashboard\DTO\GerirAcompanhamentoInput;
use App\Dashboard\Entity\AcompanhamentoDaPessoa;
use App\Dashboard\Exception\AcompanhamentoEmConflitoException;
use App\Dashboard\Exception\AcompanhamentoInvalidoException;
use App\Dashboard\Repository\AcompanhamentoDaPessoaRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Criar acompanhamento" do perfil individual (spec painel-acompanhamento-redistribuir, fatia A): o gestor
 * registra que vai acompanhar o trabalho de uma pessoa, com revisão em 1, 2 ou 7 dias. Só grava o registro
 * e a auditoria (`acomp_cria`), na mesma transação: não avisa a pessoa, não cria nota nem mexe em meta.
 * Nunca é chamado por Command, listener ou agendamento (LGPD art. 20: a decisão é do gestor).
 *
 * Erros: sem permissão → AcompanhamentoNaoPermitidoException; pessoa fora do escritório →
 * ColaboradorForaDoEscritorioException; prazo fora de 1/2/7, o próprio gestor ou o Master →
 * AcompanhamentoInvalidoException; já há um aberto → AcompanhamentoEmConflitoException. Nada é gravado.
 */
final class CriarAcompanhamentoDaPessoaUseCase
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
        $pessoa = $this->guarda->exigirAlvo($input->tenant, $input->ator, $input->pessoaId);
        if (!in_array($input->prazoEmDias, AcompanhamentoDaPessoa::PRAZOS_EM_DIAS, true)) {
            throw new AcompanhamentoInvalidoException('Escolha um prazo de revisão de 1, 2 ou 7 dias.');
        }
        if ($this->repository->buscarAbertoDaPessoa($input->tenant, $input->pessoaId) !== null) {
            throw new AcompanhamentoEmConflitoException('Esta pessoa já está em acompanhamento.');
        }

        $acompanhamento = new AcompanhamentoDaPessoa($input->tenant, $pessoa, $input->ator, $input->agora, $input->prazoEmDias);
        try {
            $this->em->wrapInTransaction(function () use ($acompanhamento, $input): void {
                $this->repository->salvar($acompanhamento);
                $this->auditoria->criou($input->tenant, $input->ator, $acompanhamento);
                $this->em->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw new AcompanhamentoEmConflitoException('Esta pessoa já está em acompanhamento.');
        }

        return AcompanhamentoDaPessoaOutput::deEntidade($acompanhamento, $input->agora);
    }
}
