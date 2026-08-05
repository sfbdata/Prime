<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConvitePlataformaInput;
use App\Auth\Enum\StatusOab;
use App\Auth\Service\ValidadorOab;
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
        private readonly ValidadorOab $validadorOab,
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

        // Ignora a caixa: o UNIQUE do banco é case-sensitive, então uma busca exata deixaria
        // nascer `Ana@` ao lado de `ana@` — duas contas que ninguém consegue distinguir depois.
        if ($this->userRepository->encontrarPorEmailIgnorandoCaixa($invitation->getEmail()) !== []) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        // OAB é obrigatória neste fluxo (Passo 1; vira opcional só no Passo 3). O ValidadorOab
        // trata ausência como opcional, então o "obrigatória" fica como guard aqui.
        if ($input->oabNumero === '' && $input->oabUf === '') {
            throw new \InvalidArgumentException('Informe a OAB (número e UF).');
        }

        $this->validadorOab->validarFormato($input->oabNumero, $input->oabUf);

        if (!$input->aceiteTermos) {
            throw new \DomainException('É necessário aceitar os Termos de Uso para criar a conta.');
        }

        $user = new User();
        // Normaliza a caixa: o e-mail é a identidade da conta, e `Ana@` gravado aqui viraria
        // uma conta que some de qualquer busca normalizada — inclusive da recuperação de senha.
        $user->setEmail(mb_strtolower(trim((string) $invitation->getEmail())));
        $user->setFullName($input->fullName);
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->senha));
        $user->setOabNumero($input->oabNumero);
        $user->setOabUf($input->oabUf);

        // Verifica a OAB e grava o status (dormente por ora → nao_verificada).
        $resultado = $this->validadorOab->verificar($input->oabNumero, $input->oabUf, $input->fullName);
        $user->setOabStatus($resultado->status);
        $user->setOabNomeOficial($resultado->nomeOficial);

        if ($resultado->status === StatusOab::Confirmada) {
            $user->setOabVerificadaEm(new \DateTimeImmutable());
        }

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
