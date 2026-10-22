<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\PrazosDoPush;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Processo\Entity\Processo;
use App\Twig\NumeroProcessoExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Desvincula um processo específico de uma pasta.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: remover um processo da pasta — o
 * registro do Processo permanece (pode estar vinculado a outras pastas). Idempotente: se o
 * processo não estava vinculado, é no-op. Se o desvinculado era o principal, só é promovido o
 * primeiro vínculo SEM tipo de apenso (vínculo antigo); apensado com tipo nunca vira principal, então a
 * pasta com principal e apensados fica sem principal e com os apensados intactos (decisão do P4f,
 * spec §2: embargos não é a ação principal).
 *
 * Apensado (P4f): desvincular um apensado não mexe no principal e escreve o cartão do sistema
 * "apensado X desvinculado." na timeline de Dados (`SYS.proc`, N:6711), em nome de quem desvinculou.
 *
 * Os demais (P5d, desenho `autoReg('proc', 'processo N desvinculado da pasta.')`): o cartão do sistema
 * "processo N desvinculado da pasta." na timeline, em nome de quem desvinculou. Os dois cartões são
 * auditados pelo AuditLogSubscriber (PastaMensagem).
 *
 * Prazos do Push (docs/specs/prazo-do-push.md): a pasta é ressincronizada no mesmo flush, e os prazos calculados das
 * publicações do processo que saiu são apagados (salvo os cumpridos). Falha no cálculo vai ao log e não impede o
 * desvínculo.
 */
final class DesvincularProcessoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?RegistroDoSistemaNaPasta $registro = null,
        private readonly NumeroProcessoExtension $numero = new NumeroProcessoExtension(),
        private readonly ?PrazosDoPush $prazosDoPush = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function executar(Pasta $pasta, Processo $processo, ?User $usuario = null): void
    {
        $estava = $pasta->temProcesso($processo);
        $eraApensado = $pasta->ehApensado($processo);

        $pasta->desvincularProcesso($processo);
        if ($estava && $usuario !== null) {
            $numero = $this->numero->numeroCnj($processo->getNumeroProcesso());
            // Apensado com o texto do P4f (#91); os demais com o do P5d.
            $texto = $eraApensado ? 'apensado ' . $numero . ' desvinculado.' : 'processo ' . $numero . ' desvinculado da pasta.';
            $this->registro?->registrar($pasta, $usuario, 'proc', $texto);
        }
        if ($estava) {
            $this->sincronizarPrazosDoPush($pasta);
        }
        $this->em->flush();
    }

    private function sincronizarPrazosDoPush(Pasta $pasta): void
    {
        try {
            $this->prazosDoPush?->sincronizar($pasta);
        } catch (\Throwable $e) {
            $this->logger?->error('Prazos do Push: falha ao calcular ao desvincular processo da pasta {pasta} ({classe}).', [
                'pasta' => $pasta->getId(), 'classe' => $e::class,
            ]);
        }
    }
}
