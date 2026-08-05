<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AceitarConviteEscritorioSemContaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
    ) {}

    public function executar(AceitarConviteEscritorioSemContaInput $input): User
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Este convite não está mais disponível.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Este convite está expirado.');
        }

        // Ignora a caixa: o UNIQUE do banco é case-sensitive, então uma busca exata deixaria
        // nascer `Ana@` ao lado de `ana@` — duas contas que ninguém consegue distinguir depois.
        if ($this->userRepository->encontrarPorEmailIgnorandoCaixa($invitation->getEmail()) !== []) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        $fullName = $input->fullName !== '' ? $input->fullName : $invitation->getFullName();
        if ($fullName === null || $fullName === '') {
            throw new \InvalidArgumentException('Nome completo é obrigatório.');
        }

        $tenant = $invitation->getTenant();
        if ($tenant === null) {
            throw new \DomainException('Convite inválido: escritório não encontrado.');
        }

        if (!$input->aceiteTermos) {
            throw new \DomainException('É necessário aceitar os Termos de Uso para criar a conta.');
        }

        $user = new User();
        // Normaliza a caixa: o e-mail é a identidade da conta, e `Ana@` gravado aqui viraria
        // uma conta que some de qualquer busca normalizada — inclusive da recuperação de senha.
        $user->setEmail(mb_strtolower(trim((string) $invitation->getEmail())));
        $user->setFullName($fullName);
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->senha));

        $userTenant = new UserTenant($user, $tenant);
        if ($invitation->getTenantRole() !== null) {
            $userTenant->setTenantRole($invitation->getTenantRole());
        }

        $invitation->aceitar($user);

        $this->em->persist($user);
        $this->em->persist($userTenant);
        $this->em->flush();

        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(
            user: $user,
            ip: $input->ip,
            userAgent: $input->userAgent,
            tenant: $tenant,
        ));

        return $user;
    }
}
