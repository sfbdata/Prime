<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Ponto\Service\RegistroDePontoInicial;
use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Auth\Event\ConviteDeEscritorioAceito;
use App\Entity\Auth\Invitation;
use App\Auth\Service\DadosDaContaDoConvite;
use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Service\PoliticaDeCadastro;
use App\Auth\Service\VagaNoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AceitarConviteEscritorioSemContaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly EventDispatcherInterface $eventos,
        private readonly VagaNoEscritorio $vagaNoEscritorio,
        private readonly RegistroDePontoInicial $registroDePontoInicial,
        private readonly PoliticaDeCadastro $politicaDeCadastro,
    ) {}

    /** @throws CadastroNaoDisponivel cadastro fechado (`off`): nem o convite de escritório cria conta nova */
    public function executar(AceitarConviteEscritorioSemContaInput $input): User
    {
        $this->politicaDeCadastro->exigirConviteDeContaNova();

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

        // O `required` do HTML não protege um POST direto: a mesma regra do aceite de plataforma.
        DadosDaContaDoConvite::exigirSenha($input->senha);
        $fullName = DadosDaContaDoConvite::nome($input->fullName, $invitation->getFullName());
        $genero = Genero::informado($input->genero);   // spec genero-no-cadastro: obrigatório, só F ou M

        $tenant = $invitation->getTenant();
        if ($tenant === null) {
            throw new \DomainException('Convite inválido: escritório não encontrado.');
        }

        // Convite de primário: quem convidou ainda pode dar esse acesso? (spec perfis-master D4)
        $this->regraDosPrimarios->garantirQueConviteDePrimarioSegueValido($invitation);

        // Limite de usuários também no aceite (spec perfis-1.2-planos-e-limites §6): o convite pode ter saído com vaga.
        $this->vagaNoEscritorio->exigir((int) $tenant->getId());

        if (!$input->aceiteTermos) {
            throw new \DomainException('É necessário aceitar os Termos de Uso para criar a conta.');
        }

        $user = $this->novoUsuario($invitation, $fullName, $input->senha, $genero);

        $userTenant = $this->novoVinculo($user, $tenant);
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
        $this->avisarAceite($invitation, $user, $tenant);

        return $user;
    }

    private function novoUsuario(Invitation $invitation, string $fullName, string $senha, Genero $genero): User
    {
        $user = new User();
        // Normaliza a caixa: o e-mail é a identidade da conta, e `Ana@` gravado aqui viraria
        // uma conta que some de qualquer busca normalizada — inclusive da recuperação de senha.
        $user->setEmail(mb_strtolower(trim((string) $invitation->getEmail())));
        $user->setFullName($fullName);
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $senha));
        UserProfile::daContaNova($user, $genero);   // persistido em cascata com o User

        return $user;
    }

    /** Depois do último flush: as boas-vindas do Comercial (comercial-e-emails.md §3.5). */
    private function avisarAceite(Invitation $invitation, User $user, Tenant $tenant): void
    {
        $this->eventos->dispatch(new ConviteDeEscritorioAceito(
            (int) $user->getId(),
            (int) $tenant->getId(),
            $invitation->getTenantRole()?->isSystem() === true,
        ));
    }

    /** O vínculo novo, com a chave do ponto no estado inicial (spec seguranca-b-jornada, regra 5: ligada salvo choque). */
    private function novoVinculo(User $user, Tenant $tenant): UserTenant
    {
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->alterarRegistroDePonto($this->registroDePontoInicial->registraPontoInicial($user, $tenant));

        return $vinculo;
    }
}
