<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Service\PermissionChecker;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Repository\PastaAndamentoRepository;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\RegistroDeDecisaoSemProcesso;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marca ou desmarca a pasta como "Administrativo sem processo" (interruptor no cabeçalho da aba
 * Processo, desenho 1.2.3).
 *
 * Quem: quem pode EDITAR a pasta. O quê: declarar que a pasta é de atuação administrativa
 * (consultoria, extrajudicial, contrato) e não terá processo judicial. Por quê: separar a pasta
 * que "ainda não tem processo vinculado" (pendência) da que nunca vai ter (não é pendência).
 *
 * Recebe o ESTADO FINAL, não "alterna": dois cliques (ou duas abas abertas) não se desfazem um
 * ao outro — cada POST diz o que o usuário viu no interruptor ao clicar. Estado igual ao atual
 * não grava nada (nem flush, nem linha de auditoria). Pasta com processo vinculado PODE ser
 * marcada: o desenho pede só uma confirmação na tela, não proíbe.
 *
 * Guardas, nesta ordem:
 *   1. a pasta é do escritório da sessão — senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o usuário pode editar a pasta — senão {@see SemPermissaoParaEditarPastaException} (403).
 *
 * Auditoria: `Pasta` implementa `Auditavel`; a troca do campo entra no `audit_log` pelo
 * subscriber, como qualquer outra edição da pasta.
 *
 * Aprendizado do relatório de pastas sem processo (`spMarcar` L4510): se a pasta tinha termos
 * judiciais, a decisão (`administrativo` ao marcar, `precisa` ao desmarcar) é gravada DEPOIS do flush
 * da marcação, por upsert ({@see RegistroDeDecisaoSemProcesso}): só existe decisão de marcação gravada.
 *
 * @return bool true quando o valor mudou
 */
final class DefinirPastaAdministrativaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissionChecker,
        private readonly ?RegistroDoSistemaNaPasta $registro = null,
        private readonly ?PastaAndamentoRepository $andamentos = null,
        private readonly ?RegistroDeDecisaoSemProcesso $decisoes = null,
    ) {
    }

    public function executar(Pasta $pasta, bool $administrativa, User $usuario, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        if ($pasta->isAdministrativa() === $administrativa) {
            return false;
        }

        $pasta->setAdministrativa($administrativa);
        // Andamento (E12b, desenho `spMarcar`): a trilha troca de via, e o Registro conta isso.
        $this->registro?->registrar($pasta, $usuario, 'fase', $administrativa
            ? 'pasta marcada como administrativa: o andamento passa para a via administrativa (Requerimento, Em análise, Exigência, Parecer). O andamento judicial fica guardado.'
            : ($this->andamentos?->daPasta($pasta, PastaAndamento::VIA_JUDICIAL, $tenant) !== null
                ? 'pasta voltou para a via judicial: o andamento judicial anterior foi restaurado.'
                : 'pasta voltou para a via judicial.'));
        $this->recalcularFinalizadaEm($pasta, $tenant);
        $this->em->flush();
        $this->decisoes?->registrar($pasta, $administrativa, $usuario, $tenant);

        return true;
    }

    /**
     * "Pastas concluídas" do Dashboard (spec dashboard-parte-1): a data de finalização segue a trilha da via
     * NOVA. Sem andamento gravado nela, a pasta arquivada mantém a data que tinha (regra do dono: arquivada sem
     * trilho conta na data do arquivamento) e a não arquivada deixa de contar.
     */
    private function recalcularFinalizadaEm(Pasta $pasta, Tenant $tenant): void
    {
        if ($this->andamentos === null) {
            return;
        }
        $linha = $this->andamentos->daPasta($pasta, AndamentoDaPasta::via($pasta), $tenant);
        if ($linha !== null) {
            $pasta->setFinalizadaEm($linha->finalizadaEm());

            return;
        }
        if ($pasta->getSituacao() !== Pasta::SITUACAO_ARQUIVADA) {
            $pasta->setFinalizadaEm(null);
        }
    }
}
