<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Exception\DescadastroRecusado;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\Entity\EmailDescadastro;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O que as escritas do Master na lista de Descadastrados têm em comum: o e-mail validado e a linha no `audit_log`
 * (o descadastro não é entidade auditada; o desenho pede `auditar`, L694).
 */
final class EscritaManualDoDescadastro
{
    /** Ações do `audit_log` (a coluna tem 10 caracteres, como o `modelo_del` dos modelos de campanha). */
    public const REGISTRADO = 'dsc_reg';
    public const REATIVADO = 'dsc_reat';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws DescadastroRecusado */
    public function exigirEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new DescadastroRecusado('Informe um e-mail válido.');
        }

        return $email;
    }

    public function auditar(string $acao, string $email, string $assunto, User $master): void
    {
        $this->em->persist((new AuditLog())
            ->setAction($acao)
            ->setEntityClass(EmailDescadastro::class)
            ->setEntityId($email)
            ->setActorUserId($master->getId())
            ->setActorEmail($master->getEmail())
            ->setChanges(['email' => $email, 'assunto' => $assunto, 'origem' => DescadastroDeEmailInterface::ORIGEM_MANUAL_MASTER]));
        $this->em->flush();
    }
}
