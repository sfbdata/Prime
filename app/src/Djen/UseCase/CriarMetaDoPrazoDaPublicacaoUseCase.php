<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\PrazoDaPublicacaoCalculador;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Service\GravacaoDeMetaNova;

/**
 * "Criar meta com este prazo" (desenho 11). A meta nasce na pasta do processo da publicação, com o
 * vencimento do prazo, e fica amarrada à publicação (uma por publicação). Publicação avulsa, sem pasta,
 * não cria meta: a Tarefa exige pasta (decisão do dono, 27/10).
 */
final class CriarMetaDoPrazoDaPublicacaoUseCase
{
    private const URGENTE_ATE_DIAS_UTEIS = 5;

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acesso,
        private readonly PrazoDaPublicacaoCalculador $calculador,
        private readonly GravacaoDeMetaNova $gravarMeta,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $prazoDoTeor data do prazo do Push na pasta, se o usuário não escolheu dias
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executar(Tenant $tenant, User $usuario, int $publicacaoId, ?\DateTimeImmutable $prazoDoTeor, \DateTimeImmutable $hoje): Tarefa
    {
        $publicacao = $this->publicacoes->findOneByIdDoTenant($publicacaoId, $tenant)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');
        if ($publicacao->getTarefa() !== null) {
            throw new VinculoDaPublicacaoInvalidoException('Esta publicação já tem uma meta.');
        }

        $local = $this->publicacoes->pastaDaPublicacao($publicacao);
        if ($local === null) {
            throw new VinculoDaPublicacaoInvalidoException('Vincule uma pasta para criar a meta.');
        }
        $pasta = $this->pastas->findOneBy(['id' => $local['id'], 'tenant' => $tenant]);
        if (!$pasta instanceof Pasta || !$this->acesso->podeVer($usuario, $pasta)) {
            throw new VinculoDaPublicacaoInvalidoException('Você não tem permissão para criar meta nesta pasta.');
        }

        $prazo = $this->calculador->calcular($publicacao->getDataDisponibilizacao(), $publicacao->getPrazoDias(), $prazoDoTeor, false, $tenant->getEstado(), $hoje)
            ?? throw new VinculoDaPublicacaoInvalidoException('Escolha o prazo antes de criar a meta.');

        $tarefa = (new Tarefa())
            ->setTitulo(sprintf('Manifestação: intimação de %s · %s', $publicacao->getDataDisponibilizacao()?->format('d/m/Y') ?? '', $publicacao->getNumeroProcessoComMascara() ?? $publicacao->getNumeroProcesso()))
            ->setDescricao(self::descricao($prazo->dias, $prazo->inicio, $prazo->vencimento))
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
        $this->publicacoes->flush();

        return $tarefa;
    }

    private static function descricao(?int $dias, ?\DateTimeImmutable $inicio, \DateTimeImmutable $vencimento): string
    {
        $base = 'Gerada a partir da publicação do Push Processual.';

        return $dias !== null && $inicio !== null
            ? sprintf('%s Prazo de %d dias úteis (%s a %s).', $base, $dias, $inicio->format('d/m/Y'), $vencimento->format('d/m/Y'))
            : sprintf('%s Prazo lido do teor, até %s.', $base, $vencimento->format('d/m/Y'));
    }
}
