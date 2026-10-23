<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pílula "Situação da pasta" (desenho 1.2.3, `situacaoVals`): Ativo, Suspenso, Cancelado ou Arquivado.
 *
 * História: o cliente pediu para pausar o caso; o advogado escolhe "Suspenso". A pílula e o carimbo mudam e o
 * Registro ganha "Situação da pasta: alterada de "Ativo" para "Suspenso".". Como no desenho, não há confirmação nem
 * motivo, toda transição vale e Suspenso/Cancelado não travam a pasta nem a tiram das listas. Arquivar leva o
 * andamento a Finalizada ("Motivo: pasta arquivada."); desarquivar não reverte a fase. Precisa poder EDITAR a pasta.
 */
final class DefinirSituacaoDaPastaUseCase
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly AndamentoDaPasta $andamento,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Devolve a situação gravada (igual à atual quando nada mudou). */
    public function executar(Pasta $pasta, User $usuario, Tenant $tenant, string $situacao): string
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
        if (!in_array($situacao, Pasta::SITUACOES, true)) {
            throw new \DomainException('Situação inválida.');
        }
        if ($pasta->estaExcluida()) {
            throw new \DomainException('Pasta excluída não muda de situação.');
        }

        $de = $pasta->getSituacaoRotulo();
        if ($situacao === $pasta->getSituacao()) {
            return $situacao;
        }

        $pasta->setSituacao($situacao);
        $this->registro->registrar($pasta, $usuario, 'situacao', 'alterada de "' . $de . '" para "' . $pasta->getSituacaoRotulo() . '".');
        if ($situacao === Pasta::SITUACAO_ARQUIVADA) {
            $this->andamento->finalizarPorArquivamento($pasta, $usuario, $tenant);
        }
        $this->em->flush();

        return $situacao;
    }

    /**
     * "Arquivar pasta" / "Desarquivar pasta" do menu ⋮: arquivada volta a Ativo; qualquer outra vai a Arquivado.
     * (Antes era "ativa vira arquivada, o resto vira ativa", que devolvia Suspenso e Cancelado a Ativo sem querer.)
     */
    public static function alternada(string $atual): string
    {
        return $atual === Pasta::SITUACAO_ARQUIVADA ? Pasta::SITUACAO_ATIVA : Pasta::SITUACAO_ARQUIVADA;
    }
}
