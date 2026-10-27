<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Djen\Service\PrazoDaPublicacaoCalculador;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Service\GravacaoDeMetaNova;

/**
 * "Criar meta com este prazo" na publicação do Diário da OAB. A meta nasce na pasta vinculada, com o
 * vencimento do prazo escolhido, e fica amarrada à publicação (uma por publicação). Sem pasta não há meta
 * (a Tarefa exige pasta). O texto da publicação, que pode ser sigiloso, não vai para a meta.
 */
final class CriarMetaDaPublicacaoDoDiarioUseCase
{
    private const URGENTE_ATE_DIAS_UTEIS = 5;

    public function __construct(
        private readonly PublicacaoDoDiarioDaOabRepository $diario,
        private readonly AcessoAPasta $acesso,
        private readonly PrazoDaPublicacaoCalculador $calculador,
        private readonly GravacaoDeMetaNova $gravarMeta,
    ) {
    }

    /** @throws VinculoDaPublicacaoInvalidoException */
    public function executar(Tenant $tenant, User $usuario, int $publicacaoId, \DateTimeImmutable $hoje): Tarefa
    {
        $publicacao = $this->diario->acharDoUsuario($publicacaoId, $tenant, $usuario)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');
        if ($publicacao->getTarefa() !== null) {
            throw new VinculoDaPublicacaoInvalidoException('Esta publicação já tem uma meta.');
        }
        $pasta = $publicacao->getPasta();
        if ($pasta === null) {
            throw new VinculoDaPublicacaoInvalidoException('Vincule a uma pasta para criar a meta.');
        }
        if (!$this->acesso->podeVer($usuario, $pasta)) {
            throw new VinculoDaPublicacaoInvalidoException('Você não tem permissão para criar meta nesta pasta.');
        }
        $prazo = $this->calculador->calcular(\DateTimeImmutable::createFromInterface($publicacao->getDataPublicacao()), $publicacao->getPrazoDias(), null, false, $tenant->getEstado(), $hoje)
            ?? throw new VinculoDaPublicacaoInvalidoException('Esta publicação não tem prazo. Registre o prazo em dias úteis.');

        $tarefa = (new Tarefa())
            ->setTitulo(sprintf('Manifestação: ato de %s · %s', $publicacao->getDataPublicacao()->format('d/m/Y'), $publicacao->getAto()))
            ->setDescricao(sprintf('Gerada a partir do Diário da OAB no Push Processual. Prazo de %d dias úteis (%s a %s).', $prazo->dias ?? 0, $prazo->inicio?->format('d/m/Y') ?? '', $prazo->vencimento->format('d/m/Y')))
            ->setStatus(Tarefa::STATUS_PENDENTE)
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setCriadoPor($usuario)
            ->setPrazo($prazo->vencimento)
            ->setEtapa(EtapaDaPasta::Juridico)
            ->setPrioridade($prazo->restante <= self::URGENTE_ATE_DIAS_UTEIS ? PrioridadeDaMeta::Urgente : PrioridadeDaMeta::Alta)
            ->addResponsavel($usuario);

        $this->gravarMeta->executar($tarefa, false);
        $publicacao->vincularTarefa($tarefa);
        $this->diario->sincronizar();

        return $tarefa;
    }
}
