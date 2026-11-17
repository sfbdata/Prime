<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Trilha das ocorrências e das avaliações do conselho (spec voto-avaliacoes-do-conselho-1611, seção 4), no molde de
 * {@see RegistrarAuditoriaDaRelacaoDeVotantesUseCase}: "Ocorrência registrada", "Inaptidão alterada pelo conselho" e
 * "Inaptidão mantida pelo conselho". A coluna `action` tem 10 caracteres, então a ação é fixa e a frase do desenho vai em
 * `changes`, só com os ids (assembleia, unidade e ocorrência): nenhum nome de votante, nenhum texto livre nem
 * justificativa. A data e a hora (com segundos) vêm do próprio registro do audit_log.
 *
 * Só persiste: quem chama faz o flush, na mesma transação da ação.
 */
final class RegistrarAuditoriaDasAvaliacoesUseCase
{
    public const ACAO_OCORRENCIA = 'voto_ocorr';
    public const ACAO_DECISAO = 'voto_avali';
    public const EVENTO_OCORRENCIA = 'Ocorrência registrada';
    public const EVENTO_ALTERADA = 'Inaptidão alterada pelo conselho';
    public const EVENTO_MANTIDA = 'Inaptidão mantida pelo conselho';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function ocorrenciaRegistrada(Tenant $tenant, User $ator, Assembleia $assembleia, OcorrenciaDaUnidade $ocorrencia): void
    {
        $this->gravar(self::ACAO_OCORRENCIA, self::EVENTO_OCORRENCIA, $tenant, $ator, $assembleia, $ocorrencia);
    }

    public function avaliacaoDecidida(Tenant $tenant, User $ator, Assembleia $assembleia, OcorrenciaDaUnidade $ocorrencia): void
    {
        $evento = AvaliacaoDaOcorrencia::TornadaApta === $ocorrencia->getAvaliacao() ? self::EVENTO_ALTERADA : self::EVENTO_MANTIDA;
        $this->gravar(self::ACAO_DECISAO, $evento, $tenant, $ator, $assembleia, $ocorrencia);
    }

    private function gravar(string $acao, string $evento, Tenant $tenant, User $ator, Assembleia $assembleia, OcorrenciaDaUnidade $ocorrencia): void
    {
        $log = (new AuditLog())
            ->setAction($acao)
            ->setEntityClass(Assembleia::class)
            ->setEntityId((string) $assembleia->getId())
            ->setTenantId($tenant->getId())
            ->setActorUserId($ator->getId())
            ->setActorEmail($ator->getEmail())
            ->setChanges([
                'evento' => $evento,
                'assembleia_id' => $assembleia->getId(),
                'unidade_id' => $ocorrencia->getUnidade()->getId(),
                'ocorrencia_id' => $ocorrencia->getId(),
            ]);

        $this->em->persist($log);
    }
}
