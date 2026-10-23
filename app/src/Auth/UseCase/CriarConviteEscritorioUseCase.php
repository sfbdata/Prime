<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Auth\DTO\ConviteEmitido;
use App\Auth\Service\EmailDoConvite;
use App\Auth\Service\VagaNoEscritorio;
use App\Entity\Auth\Invitation;
use App\Entity\Tenant\Tenant;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\HierarquiaDePerfis;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;

final class CriarConviteEscritorioUseCase
{
    /** Conta com o e-mail a confirmar não manda e-mail a terceiros (spec login-desenho-10, decisão c). */
    public const RECUSA_EMAIL_A_CONFIRMAR = 'Confirme o seu e-mail para convidar pessoas. Reenviamos o link de confirmação se você pedir.';

    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly VagaNoEscritorio $vagaNoEscritorio,
        private readonly HierarquiaDePerfis $hierarquia,
    ) {}

    public function executar(CriarConviteEscritorioInput $input): ConviteEmitido
    {
        if (!$input->criadoPor->isEmailConfirmado()) {
            throw new \DomainException(self::RECUSA_EMAIL_A_CONFIRMAR);
        }

        $email = EmailDoConvite::normalizar($input->email);

        // Convite com o perfil de sistema faz do convidado um primário: só primário ou Master
        // (spec perfis-master D4). OperacaoDePrimarioRecusada é DomainException: vira flash.
        if ($input->tenantRole->isSystem()) {
            $this->regraDosPrimarios->garantirQuePodeGerirPrimarios($input->criadoPor, $input->tenant);
        }

        $this->exigirQueOPerfilCabeNoDeQuemConvida($input);

        // Limite de usuários do plano ou do Master (P8): só com o bloqueio ligado.
        $this->vagaNoEscritorio->exigir((int) $input->tenant->getId());

        $this->exigirQueAindaNaoEstaNaEquipe($email, $input->tenant);

        // O token puro só existe aqui e no e-mail: o banco guarda o hash (spec seguranca-a-convites, raiz B).
        $token      = Invitation::gerarToken();
        $invitation = new Invitation(
            email: $email,
            tokenHash: Invitation::hashDoToken($token),
            type: Invitation::TIPO_ESCRITORIO,
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setFullName($input->fullName);
        $invitation->setTenant($input->tenant);
        $invitation->setTenantRole($input->tenantRole);
        $invitation->setCreatedBy($input->criadoPor);

        $this->em->persist($invitation);
        $this->em->flush();

        return new ConviteEmitido($invitation, $token);
    }

    /**
     * C5 (spec perfis-1.2-permissoes-e-hierarquia): quem não é primário nem Master só convida com perfil cujas
     * permissões estão contidas nas dele. Sem isso, um secundário com admin.users.invite daria a um cúmplice (ou a
     * uma segunda conta dele) um perfil acima do seu.
     */
    private function exigirQueOPerfilCabeNoDeQuemConvida(CriarConviteEscritorioInput $input): void
    {
        if (!$this->hierarquia->podeAtribuirPerfil($input->criadoPor, $input->tenant, $input->tenantRole)) {
            throw new \DomainException(HierarquiaDePerfis::RECUSA_PERFIL_ACIMA_DO_SEU);
        }
    }

    /** Já ativo, ou suspenso / em análise (só volta pela equipe da conta, pelo primário; spec equipe-da-conta §2). */
    private function exigirQueAindaNaoEstaNaEquipe(string $email, Tenant $tenant): void
    {
        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser === null) {
            return;
        }
        if ($this->userTenantRepository->existeVinculoAtivo($existingUser, $tenant)) {
            throw new \DomainException('Este usuário já é colaborador ativo deste escritório.');
        }
        $situacao = $this->userTenantRepository->findPorUserETenant($existingUser, $tenant)?->getSituacao();
        if ($situacao !== null && $situacao !== SituacaoNaEquipe::Aprovado) {
            throw new \DomainException('Esta pessoa está com o acesso suspenso ou em análise neste escritório. Altere a situação dela na equipe da conta.');
        }
    }
}
