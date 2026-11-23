<?php

declare(strict_types=1);

namespace App\Backup\UseCase;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\MessageHandler\GerarBackupHandler;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Tarefa agendada do backup (spec backup-e-exportacao-de-dados, seção 5): o backup pronto que passou da validade vira
 * `vencido` e o ZIP é apagado DEPOIS do commit; o pedido que ficou em `montando` há mais de 2 h (o worker caiu) vira
 * `falhou`, senão a pessoa não conseguiria pedir outro.
 *
 * É comando de sistema: lê entre escritórios de propósito, mas cada arquivo é apagado pelo escopo do PRÓPRIO pedido
 * ({@see ChavesDeBackup::zip()}).
 */
final class VencerBackupsUseCase
{
    public const LIMITE_EM_PREPARO = '-2 hours';

    public function __construct(
        private readonly PedidoDeBackupRepository $pedidos,
        private readonly RemocaoAposTransacao $remocao,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{vencidos: int, falhos: int} */
    public function executar(): array
    {
        $agora = \DateTimeImmutable::createFromInterface($this->clock->now());

        /** @var list<ChaveDeArquivo> $paraApagar */
        $paraApagar = [];
        $vencidos = $this->vencerOsProntos($agora, $paraApagar);
        $falhos = $this->falharOsPresos($agora);

        $this->em->flush();

        // Só depois do commit: se o flush falhar, nenhum arquivo foi apagado e o pedido ainda aponta para ele.
        $this->remocao->remover($paraApagar, 'backup:vencer');

        return ['vencidos' => $vencidos, 'falhos' => $falhos];
    }

    /**
     * @param list<ChaveDeArquivo> $paraApagar recebe as chaves dos ZIPs a apagar depois do commit
     */
    private function vencerOsProntos(\DateTimeImmutable $agora, array &$paraApagar): int
    {
        $vencidos = 0;
        foreach ($this->pedidos->prontosVencidos($agora) as $pedido) {
            try {
                // A chave sai ANTES de vencer (o pedido deixa de apontar para o ZIP). Pronto sem chave vence sem apagar nada.
                $chave = null !== $pedido->getChaveArquivo() ? ChavesDeBackup::zip($pedido) : null;
                $pedido->vencer($agora);
            } catch (\Throwable $e) {
                $this->registrarErro((int) $pedido->getId(), $e);

                continue;
            }
            if ($chave !== null) {
                $paraApagar[] = $chave;
            }
            ++$vencidos;
        }

        return $vencidos;
    }

    private function falharOsPresos(\DateTimeImmutable $agora): int
    {
        $falhos = 0;
        foreach ($this->pedidos->montandoIniciadosAntesDe($agora->modify(self::LIMITE_EM_PREPARO)) as $pedido) {
            try {
                $this->marcarComoFalho($pedido, $agora);
            } catch (\Throwable $e) {
                $this->registrarErro((int) $pedido->getId(), $e);

                continue;
            }
            ++$falhos;
        }

        return $falhos;
    }

    /** Só id, classe e local: a mensagem da exceção pode ter dado de cliente. */
    private function registrarErro(int $pedidoId, \Throwable $e): void
    {
        $this->logger->error('Backup {id} não pôde ser vencido: {classe} em {local}', [
            'id' => $pedidoId,
            'classe' => $e::class,
            'local' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    private function marcarComoFalho(PedidoDeBackup $pedido, \DateTimeImmutable $agora): void
    {
        $pedido->falhar(GerarBackupHandler::MOTIVO_INTERROMPIDO, $agora);
    }
}
