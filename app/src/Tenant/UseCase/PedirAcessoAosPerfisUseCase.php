<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Enum\Idioma;
use App\Profile\Repository\UserProfileRepository;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\DTO\PedirAcessoAosPerfisInput;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Pedir acesso" da tela "Acesso restrito" dos Perfis (spec perfis-1.2-permissoes-e-hierarquia C2; desenho 05 -
 * Perfis 1.2, L496 e L822): avisa pela Central cada primário ativo do escritório, com o título e o texto do desenho.
 *
 * Teto: um pedido por pessoa por dia. Sem tabela nova: o próprio aviso guarda o pedido. O link dele é a lista de
 * Perfis do escritório com quem pediu (`?pedido=<id>`, o "Conceder em Perfis" do desenho), então "já pediu hoje" é
 * existir aviso deste tipo com esse link desde a meia-noite.
 *
 * Título e texto saem do catálogo (perfis.aviso_pedido.*) no idioma de quem RECEBE (spec idioma-do-sistema §4, como
 * no e-mail), não no de quem pede: o aviso fica gravado, então o idioma é o do primário no momento do pedido.
 */
final class PedirAcessoAosPerfisUseCase
{
    public const TIPO_NOTIFICACAO = 'perfis_pedido_acesso';
    public const CHAVE_DO_TITULO = 'perfis.aviso_pedido.titulo';
    public const CHAVE_DO_TEXTO = 'perfis.aviso_pedido.texto';
    public const CHAVE_SEM_NOME = 'perfis.aviso_pedido.sem_nome';

    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly NotificacaoService $notificacaoService,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $relogio,
        private readonly TranslatorInterface $tradutor,
        private readonly UserProfileRepository $perfis,
    ) {
    }

    /**
     * @return int quantos primários foram avisados (0 quando a pessoa já pediu hoje ou não há primário ativo)
     */
    public function executar(PedirAcessoAosPerfisInput $input): int
    {
        if ($this->jaPediuHoje($input->tenant, $input->solicitante)) {
            return 0;
        }

        $nome = trim((string) $input->solicitante->getFullName());
        $url  = $this->linkDoPedido($input->tenant, $input->solicitante);

        $avisados = 0;
        foreach ($this->userTenantRepository->findUsuariosAdminsAtivos($input->tenant) as $primario) {
            // Conta desativada não é primário (RegraDosPrimarios::ehPrimario): não recebe.
            if (!$primario->isActive() || self::mesmoUsuario($primario, $input->solicitante)) {
                continue;
            }
            $locale = $this->localeDe($primario);
            $titulo = $this->tradutor->trans(self::CHAVE_DO_TITULO, [], null, $locale);
            $texto  = $this->tradutor->trans(self::CHAVE_DO_TEXTO, [
                'nome' => $nome !== '' ? $nome : $this->tradutor->trans(self::CHAVE_SEM_NOME, [], null, $locale),
            ], null, $locale);
            $this->notificacaoService->criarNotificacao($primario, $input->tenant, self::TIPO_NOTIFICACAO, $titulo, $url, $texto);
            ++$avisados;
        }

        return $avisados;
    }

    public function jaPediuHoje(Tenant $tenant, User $solicitante): bool
    {
        $meiaNoite = $this->relogio->now()->setTime(0, 0);

        return $this->notificacaoRepository->existeDoTipoComUrlNoEscritorioDesde(
            $tenant,
            self::TIPO_NOTIFICACAO,
            $this->linkDoPedido($tenant, $solicitante),
            $meiaNoite,
        );
    }

    /** O idioma escolhido no Meu Perfil de quem recebe; sem perfil, o padrão (português). */
    private function localeDe(User $destinatario): string
    {
        return ($this->perfis->buscarPorUsuario($destinatario)?->getIdioma() ?? Idioma::PADRAO)->locale();
    }

    /** Por id só quando os dois têm id: sem id, null === null daria "mesmo usuário" para qualquer um. */
    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    private function linkDoPedido(Tenant $tenant, User $solicitante): string
    {
        return $this->urls->generate('app_tenant_role_index', ['tenantId' => $tenant->getId(), 'pedido' => $solicitante->getId()]);
    }
}
