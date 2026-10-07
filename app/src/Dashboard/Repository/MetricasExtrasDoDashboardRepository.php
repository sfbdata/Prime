<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * As consultas das colunas extras da tabela Desempenho ("Adicionar coluna" do menu ⋮).
 *
 * Cada método é UMA consulta agregada (GROUP BY pessoa) — nada de laço por colaborador — e o
 * UseCase só chama as das colunas que o usuário ligou. Toda consulta recebe o Tenant e filtra por
 * ele EXPLICITAMENTE (além do TenantFilter): nenhum número de outro escritório entra.
 *
 * Período: a MESMA régua das colunas que já existem — metas por `t.dataCriacao`, pastas por
 * `p.dataAbertura` — com o mesmo formato estrito ('!Y-m-d', fim do dia na borda final). Agenda
 * usa `e.dataInicio` (o compromisso cai no período em que acontece). Sem período, conta tudo,
 * como as outras colunas.
 *
 * "Metas concluídas" e "Taxa de conclusão" não têm consulta própria: saem de Total metas − Metas
 * ativas, que o painel já calcula (o desenho define assim: "Total menos ativas").
 */
// Não-final: permite substituição por mock nos testes de UseCase (como DashboardFotoRepository).
class MetricasExtrasDoDashboardRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Metas em revisão (aguardando o gestor) por responsável, criadas no período.
     *
     * @param array<string, mixed> $filtros data_de, data_ate (Y-m-d)
     *
     * @return array<int, int> userId => quantidade
     */
    public function contarEmRevisaoPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id AS pessoa_id, COUNT(t.id) AS total')
            ->from(Tarefa::class, 't')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status = :emRevisao')
            ->setParameter('tenant', $tenant)
            ->setParameter('emRevisao', Tarefa::STATUS_EM_REVISAO)
            ->groupBy('u.id');

        $this->aplicarPeriodo($qb, 't.dataCriacao', $filtros);

        return $this->mapaDeContagem($qb);
    }

    /**
     * Base do "Tempo médio": para cada responsável, a SOMA dos dias entre criar e concluir e
     * QUANTAS metas entraram na conta. Devolver soma e quantidade (e não a média pronta) deixa o
     * Total ser a média de todas as metas (Σ dias ÷ Σ metas), e não a média das médias.
     *
     * Só metas concluídas COM `dataConclusao`: as concluídas antes de a coluna existir não têm a
     * data e ficariam com "0 dias" se entrassem. Dias corridos de calendário (DATE − DATE).
     *
     * @param array<string, mixed> $filtros data_de, data_ate (Y-m-d)
     *
     * @return array<int, array{dias: int, metas: int}> userId => soma de dias e quantidade
     */
    public function tempoDeConclusaoPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id AS pessoa_id, SUM(DATE_DIFF(t.dataConclusao, t.dataCriacao)) AS dias, COUNT(t.id) AS metas')
            ->from(Tarefa::class, 't')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status = :concluida')
            ->andWhere('t.dataConclusao IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('u.id');

        $this->aplicarPeriodo($qb, 't.dataCriacao', $filtros);

        $mapa = [];
        foreach ($qb->getQuery()->getArrayResult() as $linha) {
            $mapa[(int) $linha['pessoa_id']] = [
                'dias'  => (int) $linha['dias'],
                'metas' => (int) $linha['metas'],
            ];
        }

        return $mapa;
    }

    /**
     * Pastas com prioridade Urgente por responsável, abertas no período — o mesmo critério do card
     * "Demandas urgentes" (PastaRepository::countUrgentes), agora por pessoa.
     *
     * @param array<string, mixed> $filtros data_de, data_ate (Y-m-d)
     *
     * @return array<int, int> userId => quantidade
     */
    public function contarUrgentesPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r.id AS pessoa_id, COUNT(p.id) AS total')
            ->from(Pasta::class, 'p')
            ->join('p.responsavel', 'r')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.prioridade = :urgente')
            ->setParameter('tenant', $tenant)
            ->setParameter('urgente', PrioridadePasta::Urgente)
            ->groupBy('r.id');

        $this->aplicarPeriodo($qb, 'p.dataAbertura', $filtros);

        return $this->mapaDeContagem($qb);
    }

    /**
     * Compromissos da agenda por pessoa, no período (pela data de início).
     *
     * Conta para a pessoa o evento que ela CRIOU ou do qual PARTICIPA — cada evento uma vez por
     * pessoa, mesmo sendo as duas coisas. Só eventos visíveis à equipe (`visibilidade = todos`):
     * o "somente eu" é privado de quem o criou e não aparece no painel de ninguém. Cancelados
     * ficam fora. Evento recorrente conta uma vez (a ocorrência-base), não cada repetição.
     *
     * $pessoas é o universo da tabela (colaboradores ativos do escritório): a junção com o
     * usuário fica restrita a ele. Universo vazio devolve mapa vazio sem consultar.
     *
     * @param array<string, mixed> $filtros data_de, data_ate (Y-m-d)
     * @param list<int>            $pessoas
     *
     * @return array<int, int> userId => quantidade
     */
    public function contarEventosPorPessoa(Tenant $tenant, array $filtros, array $pessoas): array
    {
        if ($pessoas === []) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('u.id AS pessoa_id, COUNT(DISTINCT e.id) AS total')
            ->from(Evento::class, 'e')
            ->leftJoin('e.participantes', 'part')
            ->join(User::class, 'u', 'WITH', 'u.id = IDENTITY(e.criador) OR u.id = part.id')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.visibilidade = :todos')
            ->andWhere('e.status != :cancelado')
            ->andWhere('u.id IN (:pessoas)')
            ->setParameter('tenant', $tenant)
            ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
            ->setParameter('cancelado', Evento::STATUS_CANCELADO)
            ->setParameter('pessoas', array_values($pessoas))
            ->groupBy('u.id');

        $this->aplicarPeriodo($qb, 'e.dataInicio', $filtros);

        return $this->mapaDeContagem($qb);
    }

    /** @return array<int, int> */
    private function mapaDeContagem(QueryBuilder $qb): array
    {
        $mapa = [];
        foreach ($qb->getQuery()->getArrayResult() as $linha) {
            $mapa[(int) $linha['pessoa_id']] = (int) $linha['total'];
        }

        return $mapa;
    }

    /**
     * Período do painel sobre o campo de data informado. Data inválida (ou fora do formato
     * 'Y-m-d') é ignorada — mesma regra dos repositórios de Tarefa e Pasta.
     *
     * @param array<string, mixed> $filtros
     */
    private function aplicarPeriodo(QueryBuilder $qb, string $campo, array $filtros): void
    {
        $de = $this->lerData((string) ($filtros['data_de'] ?? ''), false);
        if ($de !== null) {
            $qb->andWhere($campo . ' >= :fExtraDe')->setParameter('fExtraDe', $de);
        }

        $ate = $this->lerData((string) ($filtros['data_ate'] ?? ''), true);
        if ($ate !== null) {
            $qb->andWhere($campo . ' <= :fExtraAte')->setParameter('fExtraAte', $ate);
        }
    }

    private function lerData(string $valor, bool $fimDoDia): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fimDoDia ? $data->setTime(23, 59, 59) : $data;
    }
}
