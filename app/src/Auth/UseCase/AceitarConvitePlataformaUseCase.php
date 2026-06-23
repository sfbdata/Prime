<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConvitePlataformaInput;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AceitarConvitePlataformaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
    ) {}

    public function executar(AceitarConvitePlataformaInput $input): User
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

        if ($this->userRepository->findOneBy(['email' => $invitation->getEmail()]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        if (!preg_match('/^\d+$/', $input->oabNumero)) {
            throw new \InvalidArgumentException('Número da OAB deve conter apenas dígitos.');
        }

        if (!preg_match('/^[A-Z]{2}$/', $input->oabUf)) {
            throw new \InvalidArgumentException('UF da OAB deve ter exatamente 2 letras maiúsculas.');
        }

        if (!$input->aceiteTermos) {
            throw new \DomainException('É necessário aceitar os Termos de Uso para criar a conta.');
        }

        $user = new User();
        $user->setEmail($invitation->getEmail());
        $user->setFullName($input->fullName);
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->senha));
        $user->setOabNumero($input->oabNumero);
        $user->setOabUf($input->oabUf);

        $invitation->aceitar($user);

        $this->em->persist($user);
        $this->em->flush();

        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(
            user: $user,
            ip: $input->ip,
            userAgent: $input->userAgent,
        ));

        return $user;
    }
}
