<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\UserTenantRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A guarda da tela da meta, num lugar só: a própria página (`TarefaController`), o Extrato e o Lembrar usam a mesma.
 *
 * SEC-013: a posse vem ANTES do vínculo. Conferir só se o criador ou o responsável da pasta tem vínculo com o
 * escritório da sessão deixa passar a meta de OUTRO escritório quando essa pessoa está nos dois (e o TenantFilter
 * falhou). Fora do escritório: 404, como todo recurso cross-tenant (403 confirmaria que o id existe em algum lugar).
 *
 * Cadeado (spec pasta-cadeado D3): a meta de pasta OCULTA para quem acessa também é 404, a mesma regra da lista
 * (`AcessoAPasta::idsOcultosPara`, nível `Oculto`). Vale para tudo que passa por aqui: a página da meta, o despacho
 * (com ou sem anexo), a ciência, a certidão, o Extrato e o Lembrar.
 *
 * Recurso-item (auditoria 3.12; docs/AUTORIZACAO.md, seção 7, "Meta aberta por id"): passado o cadeado, só abre
 * quem pode VER a pasta (`AcessoAPasta::podeVer`, o `canAccessResource` completo) OU cuida da meta (responsável ou
 * criador, {@see QuemCuidaDaMeta}). Os demais recebem 404, como a meta de pasta oculta: quem só tem o módulo
 * Tarefas não lê nem escreve na meta de uma pasta que não pode abrir (o mesmo limiar do SEC-015 e das rotas irmãs).
 */
final class AcessoAMeta
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly QuemCuidaDaMeta $quemCuida,
    ) {
    }

    /**
     * @throws NotFoundHttpException meta de outro escritório, de pasta oculta pelo cadeado para quem acessa, ou de
     *                               pasta que a pessoa não pode ver sem cuidar da meta
     * @throws AccessDeniedException nem o criador nem o responsável da pasta têm vínculo ativo com o escritório
     */
    public function exigir(Tarefa $meta, Tenant $escritorio, User $quemAcessa): void
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw new NotFoundHttpException('Meta não encontrada.');
        }

        $pasta = $meta->getPasta();
        if ($this->acessoAPasta->nivel($quemAcessa, $pasta) === NivelDeAcesso::Oculto) {
            throw new NotFoundHttpException('Meta não encontrada.');
        }
        if (!$this->quemCuida->ehResponsavelOuCriador($meta, $quemAcessa) && !$this->acessoAPasta->podeVer($quemAcessa, $pasta)) {
            throw new NotFoundHttpException('Meta não encontrada.');
        }

        $criador = $pasta->getCriadoPor();
        $responsavel = $pasta->getResponsavel();
        $criadorPertence = $criador !== null && $this->vinculos->existeVinculoAtivo($criador, $escritorio);
        $responsavelPertence = $responsavel !== null && $this->vinculos->existeVinculoAtivo($responsavel, $escritorio);

        if (!$criadorPertence && !$responsavelPertence) {
            throw new AccessDeniedException('Acesso negado.');
        }
    }
}
