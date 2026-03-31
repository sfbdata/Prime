<?php

namespace App\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Ponto\JustificativaPonto;
use App\Entity\Tarefa\Tarefa;
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
     * Cria uma notificação genérica
     */
    public function criar(User $usuario, string $tipo, string $titulo, ?string $mensagem = null, ?Tarefa $tarefa = null): Notificacao
    {
        $notificacao = new Notificacao();
        $notificacao->setUsuario($usuario);
        $notificacao->setTipo($tipo);
        $notificacao->setTitulo($titulo);
        $notificacao->setMensagem($mensagem);
        $notificacao->setTarefa($tarefa);

        $this->entityManager->persist($notificacao);

        return $notificacao;
    }

    /**
     * Cria uma notificação com URL personalizada (para eventos e outros)
     */
    public function criarNotificacao(User $usuario, string $tipo, string $mensagem, ?string $url = null): Notificacao
    {
        $notificacao = new Notificacao();
        $notificacao->setUsuario($usuario);
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
        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            $usuario = $atribuicao->getUsuario();
            if ($usuario === null) {
                continue;
            }

            $this->criar(
                $usuario,
                Notificacao::TIPO_TAREFA_CRIADA,
                'Nova tarefa atribuída',
                "A tarefa \"{$tarefa->getTitulo()}\" foi atribuída a você.",
                $tarefa
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Notifica admins do tenant que um usuário enviou tarefa para revisão.
     * Chamado quando funcionário clica em "Enviar para Revisão".
     */
    public function notificarTarefaEmRevisao(Tarefa $tarefa, User $usuarioQueEnviou): void
    {
        // Buscar admins do mesmo tenant
        $tenant = $usuarioQueEnviou->getTenant();
        if ($tenant === null) {
            return;
        }

        // Buscar todos os usuários do tenant e filtrar admins em PHP
        // (evita problemas com LIKE em campos JSON no PostgreSQL)
        $usuarios = $this->userRepository->createQueryBuilder('u')
            ->where('u.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        $admins = array_filter($usuarios, function(User $user) {
            return $this->permissionChecker->canAdminister($user, 'admin.tarefas.manage');
        });

        foreach ($admins as $admin) {
            if ($admin->getId() === $usuarioQueEnviou->getId()) {
                continue; // Não notifica o próprio usuário
            }

            $this->criar(
                $admin,
                Notificacao::TIPO_TAREFA_EM_REVISAO,
                'Tarefa aguardando revisão',
                "{$usuarioQueEnviou->getFullName()} enviou a tarefa \"{$tarefa->getTitulo()}\" para revisão.",
                $tarefa
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Notifica usuários atribuídos que a tarefa voltou como pendência.
     * Chamado quando admin clica em "Enviar Pendência".
     */
    public function notificarTarefaPendente(Tarefa $tarefa): void
    {
        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            $usuario = $atribuicao->getUsuario();
            if ($usuario === null) {
                continue;
            }

            $this->criar(
                $usuario,
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
        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            $usuario = $atribuicao->getUsuario();
            if ($usuario === null) {
                continue;
            }

            $this->criar(
                $usuario,
                Notificacao::TIPO_TAREFA_CONCLUIDA,
                'Tarefa concluída',
                "A tarefa \"{$tarefa->getTitulo()}\" foi concluída com sucesso!",
                $tarefa
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Retorna notificações não lidas do usuário
     * 
     * @return Notificacao[]
     */
    public function getNotificacoesNaoLidas(User $usuario, int $limit = 10): array
    {
        return $this->notificacaoRepository->findNaoLidasByUsuario($usuario, $limit);
    }

    /**
     * Conta notificações não lidas
     */
    public function contarNaoLidas(User $usuario): int
    {
        return $this->notificacaoRepository->countNaoLidasByUsuario($usuario);
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
     * Marca todas as notificações do usuário como lidas
     */
    public function marcarTodasComoLidas(User $usuario): void
    {
        $this->notificacaoRepository->marcarTodasComoLidas($usuario);
    }

    public function notificarJustificativaAprovada(JustificativaPonto $justificativa, string $urlPonto): void
    {
        $this->criarNotificacao(
            $justificativa->getUser(),
            Notificacao::TIPO_PONTO_JUSTIFICATIVA_APROVADA,
            'Sua justificativa de ponto foi aprovada.',
            $urlPonto
        );
    }

    public function notificarJustificativaRejeitada(JustificativaPonto $justificativa, string $urlPonto): void
    {
        $this->criarNotificacao(
            $justificativa->getUser(),
            Notificacao::TIPO_PONTO_JUSTIFICATIVA_REJEITADA,
            'Sua justificativa de ponto foi rejeitada.',
            $urlPonto
        );
    }
}
