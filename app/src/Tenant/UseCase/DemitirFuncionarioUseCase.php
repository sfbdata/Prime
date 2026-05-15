<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Tenant\DTO\DemitirFuncionarioInput;
use Doctrine\ORM\EntityManagerInterface;

final class DemitirFuncionarioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(DemitirFuncionarioInput $input): void
    {
        $this->validarInput($input);

        if ($input->substituto !== null) {
            $this->transferirResponsabilidades($input->funcionario, $input->substituto);
        } else {
            $this->removerResponsabilidades($input->funcionario);
        }

        $input->funcionario->demitir();
        $this->em->flush();
    }

    /**
     * IMPORTANTE: este UseCase assume que o controller chamador já
     * validou via Guarda 1 (B-route) que $input->funcionario tem
     * vínculo ativo com $input->tenant. Sem essa validação prévia,
     * a verificação de "criador do tenant" abaixo pode falhar
     * silenciosamente quando tenant não pertencer ao funcionário.
     * Idem para $input->substituto: o controller valida
     * existeVinculoAtivo($substituto, $tenant) antes de criar o input.
     */
    private function validarInput(DemitirFuncionarioInput $input): void
    {
        if ($input->executor === $input->funcionario) {
            throw new \InvalidArgumentException('Não é permitido demitir a si mesmo.');
        }

        if ($input->tenant->getCriadoPor() === $input->funcionario) {
            throw new \InvalidArgumentException('Não é permitido demitir o criador do escritório.');
        }

        if ($input->substituto === null) {
            return;
        }

        if (!$input->substituto->isActive()) {
            throw new \InvalidArgumentException('O substituto deve estar ativo.');
        }
    }

    private function removerResponsabilidades(User $funcionario): void
    {
        $uid = $funcionario->getId();

        $this->em->createQuery(
            'UPDATE App\Entity\Pasta\Pasta p SET p.responsavel = NULL WHERE p.responsavel = :user'
        )->setParameter('user', $funcionario)->execute();

        $this->em->createQuery(
            'UPDATE App\Entity\ServiceDesk\Chamado c SET c.responsavel = NULL WHERE c.responsavel = :user'
        )->setParameter('user', $funcionario)->execute();

        $conn = $this->em->getConnection();

        $conn->executeStatement(
            'DELETE FROM tarefa_responsaveis WHERE user_id = :uid',
            ['uid' => $uid]
        );

        $conn->executeStatement(
            'DELETE FROM evento_participante WHERE user_id = :uid',
            ['uid' => $uid]
        );
    }

    private function transferirResponsabilidades(User $funcionario, User $substituto): void
    {
        $uid = $funcionario->getId();
        $sub = $substituto->getId();

        $this->em->createQuery(
            'UPDATE App\Entity\Pasta\Pasta p SET p.responsavel = :sub WHERE p.responsavel = :user'
        )->setParameter('sub', $substituto)->setParameter('user', $funcionario)->execute();

        $this->em->createQuery(
            'UPDATE App\Entity\ServiceDesk\Chamado c SET c.responsavel = :sub WHERE c.responsavel = :user'
        )->setParameter('sub', $substituto)->setParameter('user', $funcionario)->execute();

        $conn = $this->em->getConnection();

        $conn->executeStatement(
            'INSERT INTO tarefa_responsaveis (tarefa_id, user_id)
             SELECT tarefa_id, :sub FROM tarefa_responsaveis
             WHERE user_id = :uid
               AND tarefa_id NOT IN (
                   SELECT tarefa_id FROM tarefa_responsaveis WHERE user_id = :sub2
               )',
            ['sub' => $sub, 'uid' => $uid, 'sub2' => $sub]
        );

        $conn->executeStatement(
            'DELETE FROM tarefa_responsaveis WHERE user_id = :uid',
            ['uid' => $uid]
        );

        $conn->executeStatement(
            'INSERT INTO evento_participante (evento_id, user_id)
             SELECT evento_id, :sub FROM evento_participante
             WHERE user_id = :uid
               AND evento_id NOT IN (
                   SELECT evento_id FROM evento_participante WHERE user_id = :sub2
               )',
            ['sub' => $sub, 'uid' => $uid, 'sub2' => $sub]
        );

        $conn->executeStatement(
            'DELETE FROM evento_participante WHERE user_id = :uid',
            ['uid' => $uid]
        );
    }
}
