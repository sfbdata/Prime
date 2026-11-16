<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tenant\Enum\SituacaoNaEquipe;

/**
 * As barreiras de acesso a um escritório, sem sessão (spec perfis-1.2-contas C13; spec mcp-remoto-oauth 4.1).
 *
 * Saíram do TenantContextValidatorListener sem mudar a lógica, para que o navegador (o listener, a cada requisição,
 * com o escritório da sessão) e o conector de I.A. (o PortaoDoConector, com o escritório do token) apliquem as
 * MESMAS barreiras. Duplicar a regra no conector seria o jeito de as duas divergirem; o
 * `PortaoDoConectorArquiteturaTest` exige que as duas portas dependam daqui.
 *
 * Ordem (vale a mensagem da primeira que falhar):
 *  (a) a conta: suspensa pelo Master ou excluída (quarentena);
 *  (b) pedido de exclusão pendente ("suspensa a pedido"): o primário é o caso à parte de {@see ResultadoDasBarreiras};
 *      quem não é primário é recusado;
 *  (c) o vínculo: removido, ou inativo de legado;
 *  (d) a situação na equipe: em análise ou suspenso. O Master não passa por esta no navegador; quem chama decide se o
 *      passe vale (`$passeDoMaster`): o listener do navegador, sim, como sempre; o conector, não (spec
 *      mcp-remoto-oauth, seção 4, regra 1: vínculo ativo para todos).
 */
final class BarreirasDoEscritorio
{
    public const ACESSO_INDISPONIVEL = 'Seu acesso a este escritório não está mais disponível. Selecione outro escritório.';

    public function __construct(
        private readonly BloqueioPorPedidoDeExclusao $bloqueioPorPedido,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    /**
     * @param ?UserTenant $userTenant o vínculo da pessoa com o escritório (nulo: removido)
     * @param ?Tenant     $conta      o escritório a que a pessoa quer chegar (nulo: já purgado)
     * @param bool        $passeDoMaster o Master com vínculo em análise ou suspenso passa pela barreira (d)
     */
    public function conferir(?UserTenant $userTenant, ?Tenant $conta, User $user, bool $passeDoMaster): ResultadoDasBarreiras
    {
        $recusa = $this->barreiraDaConta($conta);
        if ($recusa === null && $this->primarioNaContaSuspensaAPedido($userTenant)) {
            return ResultadoDasBarreiras::primarioNaContaSuspensaAPedido();
        }
        $recusa ??= $this->barreiraDoPedidoDeExclusao($userTenant);
        $recusa ??= $this->barreiraDoVinculo($userTenant);
        $recusa ??= $this->barreiraDaSituacao($userTenant, $user, $passeDoMaster);

        return $recusa === null ? ResultadoDasBarreiras::liberado() : ResultadoDasBarreiras::recusado($recusa);
    }

    /**
     * (a) A conta: escritório suspenso pelo Master (isActive=false, excluidoEm nulo, aviso da C9) ou
     * excluído, em quarentena (aviso da C9b). Sem bypass para o Master (spec perfis-1.2-contas C12).
     */
    private function barreiraDaConta(?Tenant $conta): ?string
    {
        if ($conta !== null && $conta->isActive() === true) {
            return null;
        }

        if ($conta === null) {
            return self::ACESSO_INDISPONIVEL; // já purgada: não há mais nome a dizer
        }

        return $conta->getExcluidoEm() === null
            ? AvisoDeContaSuspensa::para((string) $conta->getName())
            : AvisoDeContaSuspensa::paraExcluida((string) $conta->getName());
    }

    /**
     * (b) Pedido de exclusão pendente ("suspensa a pedido", PR 5): quem não é primário não entra
     * ({@see BloqueioPorPedidoDeExclusao}). O primário é tratado antes, em
     * {@see primarioNaContaSuspensaAPedido()}.
     */
    private function barreiraDoPedidoDeExclusao(?UserTenant $userTenant): ?string
    {
        return $userTenant !== null && $userTenant->isActive() && $this->bloqueioPorPedido->bloqueia($userTenant)
            ? BloqueioPorPedidoDeExclusao::MENSAGEM
            : null;
    }

    /**
     * O primário de uma conta "suspensa a pedido" continua no escritório, mas só no Meu Perfil (onde desiste): quem o
     * prende lá é o navegador. Só vale com o vínculo ATIVO: o primário em análise ou suspenso pela equipe (vínculo
     * inativo) segue para a (c) e a (d), como qualquer pessoa.
     */
    private function primarioNaContaSuspensaAPedido(?UserTenant $userTenant): bool
    {
        if ($userTenant === null || !$userTenant->isActive() || !$this->regraDosPrimarios->ehPrimario($userTenant)) {
            return false;
        }
        $tenantId = $userTenant->getTenant()->getId();

        return $tenantId !== null && $this->bloqueioPorPedido->suspensaAPedido($tenantId);
    }

    /** (c) O vínculo: removido, ou inativo de legado (inativo sem ser por causa da situação na equipe). */
    private function barreiraDoVinculo(?UserTenant $userTenant): ?string
    {
        if ($userTenant === null) {
            return self::ACESSO_INDISPONIVEL;
        }

        return !$userTenant->isActive() && $userTenant->getSituacao() === SituacaoNaEquipe::Aprovado ? self::ACESSO_INDISPONIVEL : null;
    }

    /**
     * (d) Situação na equipe: em análise ou suspenso pelo primário (spec docs/specs/equipe-da-conta.md §3). O Master
     * não passa por esta barreira quando o passe vale (navegador).
     */
    private function barreiraDaSituacao(?UserTenant $userTenant, User $user, bool $passeDoMaster): ?string
    {
        if ($userTenant === null || $userTenant->isActive() || ($passeDoMaster && in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true))) {
            return null;
        }

        return $userTenant->getSituacao()->mensagemDeAcesso((string) $userTenant->getTenant()->getName());
    }
}
