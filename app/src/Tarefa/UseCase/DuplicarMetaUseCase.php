<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Exception\MetaNaoDuplicavelException;
use App\Tarefa\Service\GravacaoDeMetaNova;
use App\Tenant\Service\ColaboradoresAtribuiveis;

/**
 * "Duplicar" no ⋮ da meta, na aba Metas da pasta (desenho 02, D:3725 `duplicar` e D:3736; PMC-032, D-META).
 *
 * Quem dispara: quem vê a pasta e tem o módulo Tarefas (as mesmas guardas da criação de meta, aplicadas pelo
 * controller). O que quer: uma meta igual para começar de novo, sem redigitar.
 *
 * A cópia nasce NA HORA (sem janela de confirmação), na mesma pasta: leva descrição, prazo (mesmo vencido, como o
 * desenho), os responsáveis que a Nova meta aceitaria hoje, a prioridade exata e o tipo de demanda; o título ganha " (cópia)" (cortando o original para caber no limite de 120 do
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
        private readonly ColaboradoresAtribuiveis $atribuiveis,
    ) {
    }

    /**
     * @return int o id da cópia
     *
     * @throws MetaNaoDuplicavelException a pasta da meta foi excluída (lápide), ou nenhum responsável dela pode
     *                                     receber metas agora
     */
    public function executar(Tarefa $original, User $quemDuplica, Tenant $tenant): int
    {
        $this->exigirMetaDoEscritorio($original, $tenant);
        $this->exigirPastaAtiva($original);

        $responsaveis = $this->responsaveisAtribuiveis($original, $tenant);
        $copia = $this->copiar($original, $quemDuplica, $tenant, $responsaveis);
        // A prioridade é a da original, exatamente (o desenho copia a meta inteira): sem herdar a da pasta.
        $this->gravarMeta->executar($copia, herdarPrioridadeDaPasta: false);
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

    /**
     * Só quem a Nova meta aceitaria hoje (aprovado no escritório; sem "atuais", como no `criarParaPasta`): o suspenso
     * continua na original, mas não entra na meta nova. Sem ninguém, a duplicação é recusada (meta nova sem
     * responsável não existe, D-META).
     *
     * @return list<User>
     */
    private function responsaveisAtribuiveis(Tarefa $original, Tenant $tenant): array
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $original->getResponsaveis()->toArray());
        $responsaveis = $this->atribuiveis->resolverVarios(array_values($ids), $tenant);
        if ($responsaveis === []) {
            throw new MetaNaoDuplicavelException('Nenhum responsável desta meta pode receber metas agora. Crie pela Nova meta.');
        }

        return $responsaveis;
    }

    /** @param list<User> $responsaveis */
    private function copiar(Tarefa $original, User $quemDuplica, Tenant $tenant, array $responsaveis): Tarefa
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
        foreach ($responsaveis as $responsavel) {
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
