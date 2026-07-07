<?php

namespace App\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Ponto\Entity\JustificativaPonto;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço responsável por criar e gerenciar notificações do sistema.
 * 
 * Tipos de notificações de tarefas:
 * - TAREFA_CRIADA: Notifica usuário quando uma tarefa é atribuída a ele
 * - TAREFA_EM_REVISAO: Notifica admin quando usuário envia tarefa para revisão
 * - TAREFA_PENDENTE: Notifica usuário quando tarefa volta da revisão (pendência)
 * - TAREFA_CONCLUIDA: Notifica usuário quando tarefa é concluída pelo admin
 */
class NotificacaoService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly UserRepository $userRepository,
        private readonly PermissionChecker $permissionChecker
    ) {
    }

    /**
     * Cria uma notificação genérica.
     *
     * O $tenant é o escritório dono da notificação (Notificacao é TenantAware): isola o sino
     * por escritório para usuários multi-tenant. Quem chama deriva o tenant da entidade de
     * origem (a tarefa, o evento, o chamado).
     */
    public function criar(User $usuario, Tenant $tenant, string $tipo, string $titulo, ?string $mensagem = null, ?Tarefa $tarefa = null): Notificacao
    {
        $notificacao = new Notificacao();
        $notificacao->setUsuario($usuario);
        $notificacao->setTenant($tenant);
        $notificacao->setTipo($tipo);
        $notificacao->setTitulo($titulo);
        $notificacao->setMensagem($mensagem);
        $notificacao->setTarefa($tarefa);

        $this->entityManager->persist($notificacao);

        return $notificacao;
    }

    /**
     * Cria uma notificação com URL personalizada (para eventos e outros).
     *
     * O $tenant é o escritório dono da notificação (Notificacao é TenantAware): isola o sino
     * por escritório para usuários multi-tenant. Quem chama deriva o tenant da entidade de
     * origem (o evento, o chamado, a justificativa).
     */
    public function criarNotificacao(User $usuario, Tenant $tenant, string $tipo, string $mensagem, ?string $url = null): Notificacao
    {
        $notificacao = new Notificacao();
        $notificacao->setUsuario($usuario);
        $notificacao->setTenant($tenant);
        $notificacao->setTipo($tipo);
        $notificacao->setTitulo($mensagem);
        $notificacao->setMensagem($mensagem);
        $notificacao->setUrl($url);

        $this->entityManager->persist($notificacao);
        $this->entityManager->flush();

        return $notificacao;
    }

    /**
     * Notifica usuários atribuídos que uma nova tarefa foi criada para eles.
     * Chamado quando admin cria uma tarefa.
     */
    public function notificarTarefaCriada(Tarefa $tarefa): void
    {
        $this->notificarResponsaveis($tarefa, $tarefa->getResponsaveis());
    }

    /**
     * Notifica apenas os responsáveis recém-adicionados a uma tarefa já existente
     * (reatribuição via updateResponsaveis). Reusa o texto de "nova tarefa atribuída".
     *
     * @param iterable<User> $usuarios
     */
    public function notificarResponsaveisAdicionados(Tarefa $tarefa, iterable $usuarios): void
    {
        $this->notificarResponsaveis($tarefa, $usuarios);
    }

    /**
     * @param iterable<User> $usuarios
     */
    private function notificarResponsaveis(Tarefa $tarefa, iterable $usuarios): void
    {
        $tenant = $tarefa->getTenant();
        foreach ($usuarios as $usuario) {
            $this->criar(
                $usuario,
                $tenant,
                Notificacao::TIPO_TAREFA_CRIADA,
                'Nova tarefa atribuída',
                "A tarefa \"{$tarefa->getTitulo()}\" foi atribuída a você.",
                $tarefa
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Notifica o criador da tarefa que ela foi enviada para revisão.
     * Chamado quando o responsável clica em "Enviar para revisão".
     */
    public function notificarTarefaEmRevisao(Tarefa $tarefa): void
    {
        $criador = $tarefa->getCriadoPor();
        if ($criador === null) {
            return;
        }

        $this->criar(
            $criador,
            $tarefa->getTenant(),
            Notificacao::TIPO_TAREFA_EM_REVISAO,
            'Tarefa enviada para revisão',
            "A tarefa \"{$tarefa->getTitulo()}\" foi enviada para revisão.",
            $tarefa
        );

        $this->entityManager->flush();
    }

    /**
     * Notifica usuários atribuídos que a tarefa voltou como pendência.
     * Chamado quando admin clica em "Enviar Pendência".
     */
    public function notificarTarefaPendente(Tarefa $tarefa): void
    {
        $tenant = $tarefa->getTenant();
        foreach ($tarefa->getResponsaveis() as $usuario) {
            $this->criar(
                $usuario,
                $tenant,
                Notificacao::TIPO_TAREFA_PENDENTE,
                'Tarefa devolvida para ajustes',
                "A tarefa \"{$tarefa->getTitulo()}\" precisa de ajustes.",
                $tarefa
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Notifica usuários atribuídos que a tarefa foi concluída.
     * Chamado quando admin clica em "Encerrar Tarefa".
     */
    public function notificarTarefaConcluida(Tarefa $tarefa): void
    {
        $tenant = $tarefa->getTenant();
        foreach ($tarefa->getResponsaveis() as $usuario) {
            $this->criar(
                $usuario,
                $tenant,
                Notificacao::TIPO_TAREFA_CONCLUIDA,
                'Tarefa concluída',
                "A tarefa \"{$tarefa->getTitulo()}\" foi concluída com sucesso!",
                $tarefa
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Retorna notificações não lidas do usuário (opcionalmente de uma categoria)
     *
     * @return Notificacao[]
     */
    public function getNotificacoesNaoLidas(User $usuario, ?string $categoria = null, int $limit = 10): array
    {
        return $this->notificacaoRepository->findNaoLidasByUsuario($usuario, $categoria, $limit);
    }

    /**
     * Conta notificações não lidas (opcionalmente de uma categoria)
     */
    public function contarNaoLidas(User $usuario, ?string $categoria = null): int
    {
        return $this->notificacaoRepository->countNaoLidasByUsuario($usuario, $categoria);
    }

    /**
     * Indica se o usuário deve ver a aba de Gestão (recebe notificações de gestão)
     */
    public function podeVerGestao(User $usuario): bool
    {
        return $this->notificacaoRepository->temNotificacaoGestao($usuario);
    }

    /**
     * Marca uma notificação como lida
     */
    public function marcarComoLida(Notificacao $notificacao): void
    {
        $notificacao->setLida(true);
        $this->entityManager->flush();
    }

    /**
     * Marca todas as notificações do usuário (no escritório informado) como lidas.
     *
     * O $tenant escopa a operação em massa: como o bulk UPDATE em DQL NÃO passa pelo
     * TenantFilter, sem ele "marcar todas" logado em B zeraria também as não-lidas de A.
     */
    public function marcarTodasComoLidas(User $usuario, Tenant $tenant, ?string $categoria = null): void
    {
        $this->notificacaoRepository->marcarTodasComoLidas($usuario, $tenant, $categoria);
    }

    /**
     * Exclui as notificações selecionadas do usuário, restritas ao escritório informado.
     *
     * O $tenant escopa o bulk DELETE (que escapa o TenantFilter) — impede excluir, por id,
     * notificações do mesmo usuário em outro escritório.
     *
     * @param int[] $ids
     * @return int quantidade excluída
     */
    public function excluir(User $usuario, Tenant $tenant, array $ids): int
    {
        return $this->notificacaoRepository->excluirDoUsuario($usuario, $tenant, $ids);
    }

    public function notificarJustificativaEnviada(JustificativaPonto $justificativa, string $urlGestor, Tenant $tenant): void
    {
        $user = $justificativa->getUser();

        $usuarios = $this->userRepository->createQueryBuilder('u')
            ->join(
                'App\Entity\Auth\UserTenant',
                'ut',
                'WITH',
                'ut.user = u AND ut.tenant = :tenant AND ut.isActive = true'
            )
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        $gestores = array_filter($usuarios, fn(User $u) =>
            $this->permissionChecker->canAdminister($u, $tenant, 'admin.users.manage')
        );

        $data = $justificativa->getData()?->format('d/m/Y') ?? '';
        foreach ($gestores as $gestor) {
            $this->criarNotificacao(
                $gestor,
                $tenant,
                Notificacao::TIPO_PONTO_JUSTIFICATIVA_ENVIADA,
                sprintf('%s enviou uma justificativa de ponto para %s.', $user->getFullName(), $data),
                $urlGestor
            );
        }
    }

    /**
     * Notifica os gestores de TI do tenant (permissão admin.servicedesk.manage) sobre a
     * abertura de um novo chamado. O solicitante é excluído, mesmo que seja gestor.
     *
     * O $tenant DEVE ser o tenant do chamado (que já é TenantAware): além de filtrar os
     * gestores destinatários, ele vira o tenant das notificações criadas. O controller
     * resolve via TenantContext, consistente com $chamado->getTenant().
     */
    public function notificarNovoChamado(Chamado $chamado, Tenant $tenant, string $url): void
    {
        $usuarios = $this->userRepository->createQueryBuilder('u')
            ->join(
                'App\Entity\Auth\UserTenant',
                'ut',
                'WITH',
                'ut.user = u AND ut.tenant = :tenant AND ut.isActive = true'
            )
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        $solicitanteId = $chamado->getSolicitante()?->getId();

        $gestores = array_filter($usuarios, fn(User $u) =>
            $u->getId() !== $solicitanteId
            && $this->permissionChecker->canAdminister($u, $tenant, 'admin.servicedesk.manage')
        );

        foreach ($gestores as $gestor) {
            $this->criarNotificacao(
                $gestor,
                $tenant,
                Notificacao::TIPO_SERVICEDESK_NOVO,
                sprintf('Novo chamado #%d: %s', $chamado->getId(), $chamado->getTitulo()),
                $url
            );
        }
    }

    public function notificarJustificativaAprovada(JustificativaPonto $justificativa, string $urlPonto): void
    {
        $this->criarNotificacao(
            $justificativa->getUser(),
            $justificativa->getTenant(),
            Notificacao::TIPO_PONTO_JUSTIFICATIVA_APROVADA,
            'Sua justificativa de ponto foi abonada.',
            $urlPonto
        );
    }

    public function notificarJustificativaRejeitada(JustificativaPonto $justificativa, string $urlPonto): void
    {
        $this->criarNotificacao(
            $justificativa->getUser(),
            $justificativa->getTenant(),
            Notificacao::TIPO_PONTO_JUSTIFICATIVA_REJEITADA,
            'Sua justificativa de ponto foi rejeitada.',
            $urlPonto
        );
    }
}
