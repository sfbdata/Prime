<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gera as notificações internas de novas publicações do DJEN. Decisão de produto: notificar TODOS os
 * usuários do escritório com acesso ao módulo DJEN. Reusa o NotificacaoService existente (não introduz
 * barramento de eventos — o projeto não usa messenger). Uma notificação-resumo por usuário, por sync.
 */
final class NotificadorPublicacoesDjen implements NotificadorPublicacoesDjenInterface
{
    private const MODULO = 'djen';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly NotificacaoService $notificacaoService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Cria uma notificação por destinatário do escritório. Retorna quantos foram notificados.
     * O $tenant é sempre o dono das publicações (a notificação é TenantAware).
     */
    public function notificar(Tenant $tenant, int $quantidadeNovas): int
    {
        if ($quantidadeNovas < 1) {
            return 0;
        }

        $destinatarios = $this->destinatariosDoModulo($tenant);
        if ($destinatarios === []) {
            return 0;
        }

        $mensagem = $quantidadeNovas === 1
            ? 'Nova publicação capturada.'
            : sprintf('%d novas publicações capturadas.', $quantidadeNovas);
        $url = $this->urlPublicacoes();

        $notificados = 0;
        foreach ($destinatarios as $usuario) {
            $this->notificacaoService->criarNotificacao(
                $usuario,
                $tenant,
                Notificacao::TIPO_DJEN_PUBLICACAO,
                $mensagem,
                $url,
            );
            $notificados++;
        }

        return $notificados;
    }

    /**
     * Usuários com vínculo ativo no escritório E acesso ao módulo DJEN (isSystem inclusive, via checker).
     *
     * @return User[]
     */
    private function destinatariosDoModulo(Tenant $tenant): array
    {
        $usuarios = $this->userRepository->createQueryBuilder('u')
            ->join(
                'App\Entity\Auth\UserTenant',
                'ut',
                'ON',
                'ut.user = u AND ut.tenant = :tenant AND ut.isActive = true'
            )
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $usuarios,
            fn (User $u): bool => $this->permissionChecker->canAccessModule($u, $tenant, self::MODULO),
        ));
    }

    /**
     * Path da tela de publicações. Gera pela rota; cai para o path fixo se a rota ainda não existir
     * (ex.: contexto CLI sem router carregado) — o link nunca fica vazio.
     */
    private function urlPublicacoes(): string
    {
        try {
            return $this->urlGenerator->generate('push_processual_index');
        } catch (\Throwable) {
            return '/push-processual';
        }
    }
}
