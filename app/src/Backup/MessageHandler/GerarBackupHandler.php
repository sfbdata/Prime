<?php

declare(strict_types=1);

namespace App\Backup\MessageHandler;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Backup\Message\GerarBackup;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Backup\Service\DonoDoEscritorio;
use App\Backup\UseCase\MontarBackupUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Monta o ZIP de um pedido de backup em segundo plano (spec backup-e-exportacao-de-dados, seção 5).
 *
 * Roda no worker, sem sessão: nada de `TenantContext` nem `Security`; o pedido é recarregado pelo id da mensagem.
 * Pedido inexistente ou que já terminou: ignora. Pedido já em `montando` (o worker caiu depois de começar e a fila
 * reentregou): vira `falhou`, não monta de novo. Em `pedido`: marca `montando`, monta, marca `pronto` (validade de 7
 * dias) e avisa pelo sino. Quem pediu e deixou de ser dono do escritório: vira `falhou` e nada é montado.
 *
 * Qualquer erro da montagem marca `falhou` com motivo genérico e NÃO sobe para o Messenger: sem retry e sem derrubar a
 * fila. O log leva só o id do pedido, a classe da exceção e arquivo:linha, nunca a mensagem dela (pode ter nome de
 * arquivo de cliente). Erro de banco fecha o EntityManager: antes de marcar `falhou` o handler o reabre
 * (`resetManager()`), recarrega o pedido pelo id (o banco ainda o tem em `montando`) e grava a falha, para a mensagem
 * seguinte do mesmo worker também funcionar. Se o ZIP foi gravado e o flush do `pronto` falha, o arquivo é apagado
 * depois (fora da transação, pela {@see RemocaoAposTransacao}) e o pedido também vira `falhou`.
 */
#[AsMessageHandler]
final class GerarBackupHandler
{
    public const VALIDADE = '+7 days';
    public const TIPO_PRONTO = 'backup_pronto';
    public const TIPO_FALHOU = 'backup_falhou';
    /** O aviso do sino abre o Meu Perfil na seção "Backup e Exportação de Dados" (`id="mpBackup"`). */
    public const URL_DA_SECAO = '/perfil#mpBackup';
    public const MOTIVO_INTERROMPIDO = 'A montagem foi interrompida. Peça um novo backup.';
    public const MOTIVO_NAO_E_DONO = 'Só o dono do escritório pode gerar o backup.';
    private const MOTIVO_GENERICO = 'Não foi possível montar o backup. Peça um novo backup.';

    public function __construct(
        private readonly PedidoDeBackupRepository $pedidos,
        private readonly MontarBackupUseCase $montar,
        private readonly RemocaoAposTransacao $remocao,
        private readonly NotificacaoService $notificacoes,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly DonoDoEscritorio $dono,
    ) {
    }

    public function __invoke(GerarBackup $mensagem): void
    {
        $id = $mensagem->pedidoId;
        $pedido = $this->pedidos->find($id);
        if ($pedido === null) {
            return;
        }

        if ($pedido->getSituacao() === SituacaoDoBackup::Montando) {
            $this->logger->warning('Backup {id} reentregue em montagem: marcado como falhou.', ['id' => $id]);
            $this->falhar($id, self::MOTIVO_INTERROMPIDO);

            return;
        }
        if ($pedido->getSituacao() !== SituacaoDoBackup::Pedido) {
            return;
        }

        if (!$this->dono->ehDono($pedido->getUsuario(), $pedido->getTenant())) {
            $this->falhar($id, self::MOTIVO_NAO_E_DONO);

            return;
        }

        try {
            $pedido->iniciarMontagem($this->agora());
            $this->em->flush();
            $resultado = $this->montar->executar($pedido);
        } catch (\Throwable $e) {
            $this->registrarErro($id, $e);
            $this->falhar($id, self::MOTIVO_GENERICO);

            return;
        }

        $this->concluir($pedido, $resultado);
    }

    private function concluir(PedidoDeBackup $pedido, ResultadoDoBackup $resultado): void
    {
        $id = (int) $pedido->getId();
        $usuario = $pedido->getUsuario();
        $tenant = $pedido->getTenant();
        $zip = ChavesDeBackup::zipPorNome($pedido, $resultado->chaveArquivo);
        try {
            $agora = $this->agora();
            $pedido->concluir($resultado, $agora, $agora->modify(self::VALIDADE));
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->registrarErro($id, $e);
            $this->remocao->remover([$zip], 'backup:zip-orfao');
            $this->falhar($id, self::MOTIVO_GENERICO);

            return;
        }

        $this->avisar($id, $usuario, $tenant, self::TIPO_PRONTO, 'Seu backup está pronto para baixar.');
    }

    /** Marca `falhou` (reabrindo o EntityManager se o erro de banco o fechou) e avisa a pessoa pelo sino. */
    private function falhar(int $id, string $motivo): void
    {
        try {
            $estavaFechado = !$this->em->isOpen();
            if ($estavaFechado) {
                $this->doctrine->resetManager();
            }
            $pedido = $this->pedidos->find($id);
            if ($pedido === null) {
                return;
            }
            if (!$estavaFechado) {
                $this->em->refresh($pedido); // descarta o que a tentativa que falhou deixou na memória
            }
            $pedido->falhar($motivo, $this->agora());
            $this->em->flush();
            $usuario = $pedido->getUsuario();
            $tenant = $pedido->getTenant();
        } catch (\Throwable $e) {
            // Banco fora do ar: o `montando` velho é resolvido por app:backup:vencer.
            $this->registrarErro($id, $e);

            return;
        }

        $this->avisar($id, $usuario, $tenant, self::TIPO_FALHOU, 'Não foi possível gerar seu backup. Peça um novo backup.');
    }

    private function avisar(int $id, User $usuario, Tenant $tenant, string $tipo, string $texto): void
    {
        try {
            $this->notificacoes->criarNotificacao($usuario, $tenant, $tipo, $texto, self::URL_DA_SECAO);
        } catch (\Throwable $e) {
            $this->registrarErro($id, $e);
        }
    }

    /** Só id, classe e local: a mensagem da exceção pode ter nome de arquivo ou dado de cliente. */
    private function registrarErro(int $pedidoId, \Throwable $e): void
    {
        $this->logger->error('Falha no backup {id}: {classe} em {local}', [
            'id' => $pedidoId,
            'classe' => $e::class,
            'local' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    private function agora(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
