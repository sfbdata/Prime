<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Repository\CadastroPendenteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Service\TenantBootstrapService;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Passo 2 do auto-cadastro público: confirma o cadastro pelo token e cria, numa
 * única transação, o User (ativo) + Tenant + vínculo dono (via TenantBootstrapService)
 * + registro do aceite dos Termos. O escritório nasce isolado, com seed próprio.
 */
final class ConfirmarCadastroUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CadastroPendenteRepository $cadastroPendenteRepository,
        private readonly UserRepository $userRepository,
        private readonly TenantBootstrapService $bootstrap,
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
    ) {}

    public function executar(string $token): User
    {
        $cadastro = $this->cadastroPendenteRepository->encontrarPorToken($token);

        if ($cadastro === null) {
            throw new \DomainException('Cadastro não encontrado.');
        }

        if ($cadastro->getStatus() !== 'pending') {
            throw new \DomainException('Este cadastro já foi confirmado.');
        }

        if ($cadastro->isExpired()) {
            throw new \DomainException('Este link de confirmação expirou. Refaça o cadastro.');
        }

        if ($this->userRepository->findOneBy(['email' => $cadastro->getEmail()]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        try {
            return $this->em->wrapInTransaction(function () use ($cadastro): User {
                $user = new User();
                $user->setEmail($cadastro->getEmail());
                $user->setFullName($cadastro->getNomeCompleto());
                $user->setIsActive(true);
                $user->setRoles(['ROLE_USER']);
                $user->setPassword($cadastro->getSenhaHash());
                $user->setOabNumero($cadastro->getOabNumero());
                $user->setOabUf($cadastro->getOabUf());
                $this->em->persist($user);

                $tenant = new Tenant();
                $tenant->setName($cadastro->getNomeEscritorio());
                $this->em->persist($tenant);

                // bootstrap seta criadoPor, cria perfil admin + vínculo dono + seed, e faz flush().
                $this->bootstrap->bootstrap($tenant, $user);

                $this->registrarAceite->executar(new RegistrarAceiteTermoInput(
                    user: $user,
                    ip: $cadastro->getIp(),
                    userAgent: $cadastro->getUserAgent(),
                    tenant: $tenant,
                ));

                $cadastro->confirmar();
                $this->em->flush();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida: outro cadastro do mesmo e-mail confirmou entre o SELECT e o INSERT.
            // O UNIQUE de user.email protege o banco; aqui traduzimos para erro amigável (não 500).
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }
    }
}
