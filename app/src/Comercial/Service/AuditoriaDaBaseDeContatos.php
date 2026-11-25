<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Entity\ContatoComercial;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A linha no `audit_log` de cada escrita do Master na Base de contatos (a entidade não é auditada por listener: uma
 * importação de milhares de linhas vira UMA linha de trilha, com os números). Sem `final` para os testes de UseCase a dublarem.
 */
class AuditoriaDaBaseDeContatos
{
    /** Ações do `audit_log` (a coluna tem 10 caracteres). */
    public const INCLUIDO = 'ctt_inc';
    public const RENOMEADO = 'ctt_nome';
    public const EXCLUIDO = 'ctt_exc';
    public const IMPORTADO = 'ctt_imp';
    public const EXPORTADO = 'ctt_exp';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $mudancas */
    public function registrar(string $acao, ?string $entidadeId, array $mudancas, User $master): void
    {
        $this->em->persist((new AuditLog())
            ->setAction($acao)
            ->setEntityClass(ContatoComercial::class)
            ->setEntityId($entidadeId)
            ->setActorUserId($master->getId())
            ->setActorEmail($master->getEmail())
            ->setChanges($mudancas));
        $this->em->flush();
    }
}
