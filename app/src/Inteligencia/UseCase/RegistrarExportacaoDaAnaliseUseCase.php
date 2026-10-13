<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registro de auditoria do download e da impressão da análise (C6-IA-01; desenho, dc L5742-5743:
 * `iaAudit('Download: ' + titulo)` e `iaAudit('Impressão: ' + titulo)`). Quem: quem pode ver a
 * pasta e usar a IA (guarda do controller), por POST com CSRF da análise. O quê: 1 linha no
 * `audit_log`, gravada à mão como o .zip da pasta (`MontarZipDeDocumentosUseCase`): não há
 * entidade mudando, então o `AuditLogSubscriber` não a veria. Por quê: o rodapé "Registro de
 * auditoria (N)" do drawer mostra quem tirou a análise do sistema.
 *
 * O GET do .txt continua PURO (não grava): uma navegação de topo, um `<img>` ou um prefetch não
 * deixam linha falsa. A cópia não é registrada, como no desenho.
 *
 * `changes` leva só metadados (`pasta_id`, `agente`); o texto do modelo nunca vai para o
 * `audit_log`. Mesma recusa do .txt: análise de outro escritório, de outra pasta, excluída ou sem
 * resultado → {@see AnaliseNaoEncontradaException} (404), ANTES de gravar.
 *
 * Recebe a Pasta já resolvida e conferida pelo chamador (tenant + `canAccessResource`).
 */
final class RegistrarExportacaoDaAnaliseUseCase
{
    public const PARA_DOWNLOAD = 'download';
    public const PARA_IMPRESSAO = 'impressao';

    /** A ação gravada no `audit_log` (coluna VARCHAR(10)). */
    public const ACAO_DOWNLOAD = 'ia_baixar';
    public const ACAO_IMPRESSAO = 'ia_imprime';

    /** O que a tela pede → a ação gravada. Qualquer outro valor é recusado pelo chamador (422). */
    public const ACOES = [
        self::PARA_DOWNLOAD => self::ACAO_DOWNLOAD,
        self::PARA_IMPRESSAO => self::ACAO_IMPRESSAO,
    ];

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function aceita(string $para): bool
    {
        return array_key_exists($para, self::ACOES);
    }

    public function executar(int $analiseId, Pasta $pasta, Tenant $tenant, User $autor, string $para): void
    {
        if (!self::aceita($para)) {
            throw new \InvalidArgumentException(sprintf('Ação de exportação desconhecida: "%s".', $para));
        }

        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, (int) $pasta->getId());
        if ($analise === null || $analise->estaExcluida() || !$analise->estaConcluida()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $log = (new AuditLog())
            ->setAction(self::ACOES[$para])
            ->setEntityClass(AnaliseDeInteligencia::class)
            ->setEntityId((string) $analise->getId())
            ->setTenantId($tenant->getId())
            ->setActorUserId($autor->getId())
            ->setActorEmail($autor->getEmail())
            ->setChanges([
                'pasta_id' => (int) $pasta->getId(),
                'agente'   => $analise->getAgente()?->value ?? 'push',
            ]);

        $this->em->persist($log);
        $this->em->flush();
    }
}
