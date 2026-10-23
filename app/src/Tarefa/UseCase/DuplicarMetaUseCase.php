<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Exception\MetaNaoDuplicavelException;
use App\Tarefa\Service\GravacaoDeMetaNova;

/**
 * "Duplicar" no ⋮ da meta, na aba Metas da pasta (desenho 02, D:3725 `duplicar` e D:3736; PMC-032, D-META).
 *
 * Quem dispara: quem vê a pasta e tem o módulo Tarefas (as mesmas guardas da criação de meta, aplicadas pelo
 * controller). O que quer: uma meta igual para começar de novo, sem redigitar.
 *
 * A cópia nasce NA HORA (sem janela de confirmação), na mesma pasta: leva descrição, prazo, responsáveis,
 * prioridade e tipo de demanda; o título ganha " (cópia)" (cortando o original para caber no limite de 120 do
 * desenho); nasce aberta (pendente), criada agora e por quem duplicou, no fim da lista. Grava pelo
 * `GravarMetaNovaUseCase`, para ganhar o número da sequência do escritório como toda meta nova.
 *
 * Não notifica ninguém: o desenho não dispara evento na duplicação (só o `aviso('Meta duplicada')`). O "Desfazer"
 * do aviso apaga a cópia pela exclusão de sempre (`tarefa_excluir`): quem duplicou é o criador e está na janela.
 *
 * Pasta excluída (lápide) é somente leitura: o `PastaSomenteLeituraListener` já recusa a rota, e esta é a
 * segunda trava.
 */
final class DuplicarMetaUseCase
{
    /** Limite do título da meta no desenho (D:87, `maxlength="120"`). */
    public const TAMANHO_DO_TITULO = 120;

    public const SUFIXO_DA_COPIA = ' (cópia)';

    public function __construct(
        private readonly GravacaoDeMetaNova $gravarMeta,
    ) {
    }

    /**
     * @return int o id da cópia
     *
     * @throws MetaNaoDuplicavelException a pasta da meta foi excluída (lápide)
     */
    public function executar(Tarefa $original, User $quemDuplica, Tenant $tenant): int
    {
        $this->exigirMetaDoEscritorio($original, $tenant);
        $this->exigirPastaAtiva($original);

        $copia = $this->copiar($original, $quemDuplica, $tenant);
        $this->gravarMeta->executar($copia);
        $this->entrarNaListaDaPasta($copia);

        return (int) $copia->getId();
    }

    private static function tituloDaCopia(string $original): string
    {
        $cabe = self::TAMANHO_DO_TITULO - mb_strlen(self::SUFIXO_DA_COPIA);

        return rtrim(mb_substr(trim($original), 0, $cabe)) . self::SUFIXO_DA_COPIA;
    }

    private function exigirMetaDoEscritorio(Tarefa $original, Tenant $tenant): void
    {
        $daMeta = $original->getTenant();
        $mesmo  = $daMeta === $tenant || ($daMeta !== null && $daMeta->getId() !== null && $daMeta->getId() === $tenant->getId());
        if (!$mesmo) {
            // O controller já respondeu 404 à meta de outro escritório; esta é a segunda trava.
            throw new \LogicException('Meta de outro escritório.');
        }
    }

    private function exigirPastaAtiva(Tarefa $original): void
    {
        if ($original->getPasta()->estaExcluida()) {
            throw new MetaNaoDuplicavelException('Esta pasta foi excluída e está somente para leitura. Restaure a pasta para duplicar a meta.');
        }
    }

    private function copiar(Tarefa $original, User $quemDuplica, Tenant $tenant): Tarefa
    {
        $copia = new Tarefa();
        $copia->setTitulo(self::tituloDaCopia($original->getTitulo()));
        $copia->setDescricao($original->getDescricao());
        $copia->setPrazo($original->getPrazo());
        $copia->setPrioridade($original->getPrioridade());
        $copia->setTipoDeDemanda($original->getTipoDeDemanda());
        $copia->setStatus(Tarefa::STATUS_PENDENTE);
        $copia->setPasta($original->getPasta());
        $copia->setTenant($tenant);
        $copia->setCriadoPor($quemDuplica);
        foreach ($original->getResponsaveis() as $responsavel) {
            $copia->addResponsavel($responsavel);
        }

        return $copia;
    }

    /** A aba devolvida na mesma requisição lê a coleção da pasta: a cópia precisa estar nela. */
    private function entrarNaListaDaPasta(Tarefa $copia): void
    {
        $metas = $copia->getPasta()->getTarefas();
        if (!$metas->contains($copia)) {
            $metas->add($copia);
        }
    }
}
