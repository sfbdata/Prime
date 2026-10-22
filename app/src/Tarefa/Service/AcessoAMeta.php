<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A guarda da tela da meta, num lugar só: a própria página (`TarefaController`), o Extrato e o Lembrar usam a mesma.
 *
 * SEC-013: a posse vem ANTES do vínculo. Conferir só se o criador ou o responsável da pasta tem vínculo com o
 * escritório da sessão deixa passar a meta de OUTRO escritório quando essa pessoa está nos dois (e o TenantFilter
 * falhou). Fora do escritório: 404, como todo recurso cross-tenant (403 confirmaria que o id existe em algum lugar).
 */
final class AcessoAMeta
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /**
     * @throws NotFoundHttpException meta de outro escritório
     * @throws AccessDeniedException nem o criador nem o responsável da pasta têm vínculo ativo com o escritório
     */
    public function exigir(Tarefa $meta, Tenant $escritorio): void
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw new NotFoundHttpException('Meta não encontrada.');
        }

        $pasta = $meta->getPasta();
        $criador = $pasta->getCriadoPor();
        $responsavel = $pasta->getResponsavel();
        $criadorPertence = $criador !== null && $this->vinculos->existeVinculoAtivo($criador, $escritorio);
        $responsavelPertence = $responsavel !== null && $this->vinculos->existeVinculoAtivo($responsavel, $escritorio);

        if (!$criadorPertence && !$responsavelPertence) {
            throw new AccessDeniedException('Acesso negado.');
        }
    }
}
