<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Criterio\PastaFinalizadaNoPeriodo;
use App\Pasta\Criterio\RegistroDePastaNoPeriodo;
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
 * `p.dataAbertura` (as concluídas por `p.finalizadaEm`) — com o mesmo formato estrito ('!Y-m-d', fim do dia na borda final). Agenda
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
     * @param array<string, mixed> $filtros       data_de, data_ate (Y-m-d)
     * @param list<int>            $pastasOcultas metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     *
     * @return array<int, int> userId => quantidade
     */
    public function contarEmRevisaoPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id AS pessoa_id, COUNT(t.id) AS total')
            ->from(Tarefa::class, 't')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // meta de pasta excluída (lápide) não conta, como nas colunas de metas
            ->andWhere('t.status = :emRevisao')
            ->setParameter('tenant', $tenant)
            ->setParameter('emRevisao', Tarefa::STATUS_EM_REVISAO)
            ->groupBy('u.id');

        $this->aplicarPeriodo($qb, 't.dataCriacao', $filtros);
        $this->semPastasOcultas($qb, $pastasOcultas);

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
     * @param array<string, mixed> $filtros       data_de, data_ate (Y-m-d)
     * @param list<int>            $pastasOcultas metas de pasta oculta pelo cadeado para quem olha não contam (D1, D3)
     *
     * @return array<int, array{dias: int, metas: int}> userId => soma de dias e quantidade
     */
    public function tempoDeConclusaoPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id AS pessoa_id, SUM(DATE_DIFF(t.dataConclusao, t.dataCriacao)) AS dias, COUNT(t.id) AS metas')
            ->from(Tarefa::class, 't')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL') // meta de pasta excluída (lápide) não conta, como nas colunas de metas
            ->andWhere('t.status = :concluida')
            ->andWhere('t.dataConclusao IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('u.id');

        $this->aplicarPeriodo($qb, 't.dataCriacao', $filtros);
        $this->semPastasOcultas($qb, $pastasOcultas);

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
     * Metas concluídas NO período, pela DATA DE CONCLUSÃO (`Tarefa.dataConclusao`), por responsável: a
     * quantidade e a soma dos dias entre criar e concluir. É o recorte que se repete igual no período
     * anterior (a data de conclusão é um fato do passado), ao contrário do recorte por criação com o
     * status de hoje (DASH-010b). Só metas hoje concluídas COM `dataConclusao`; a meta reaberta perde a
     * data e sai da conta nos dois lados. Com `contar_desde` (Zerar), só as concluídas a partir do corte.
     *
     * @param array<string, mixed> $filtros       data_de, data_ate (Y-m-d)
     * @param list<int>            $pastasOcultas cadeado (D1, D3)
     *
     * @return array<int, array{dias: int, metas: int}> userId => quantidade e soma de dias
     */
    public function concluidasNoPeriodoPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u.id AS pessoa_id, SUM(DATE_DIFF(t.dataConclusao, t.dataCriacao)) AS dias, COUNT(t.id) AS metas')
            ->from(Tarefa::class, 't')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('t.status = :concluida')
            ->andWhere('t.dataConclusao IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('u.id');

        $this->aplicarPeriodo($qb, 't.dataConclusao', $filtros);
        $this->semPastasOcultas($qb, $pastasOcultas);

        $mapa = [];
        foreach ($qb->getQuery()->getArrayResult() as $linha) {
            $mapa[(int) $linha['pessoa_id']] = ['dias' => (int) $linha['dias'], 'metas' => (int) $linha['metas']];
        }

        return $mapa;
    }

    /**
     * A primeira `dataConclusao` guardada no escritório: antes dela o sistema não registrava quando a meta
     * foi concluída, então contar conclusões por data num período anterior a ela subestimaria o passado.
     */
    public function primeiraDataDeConclusao(Tenant $tenant): ?\DateTimeImmutable
    {
        $valor = $this->em->createQueryBuilder()
            ->select('MIN(t.dataConclusao)')
            ->from(Tarefa::class, 't')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.dataConclusao IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return $valor === null ? null : new \DateTimeImmutable((string) $valor);
    }

    /**
     * Pastas com prioridade Urgente por responsável, abertas no período — o mesmo critério do card
     * "Demandas urgentes" (PastaRepository::countUrgentes), agora por pessoa.
     *
     * @param array<string, mixed> $filtros       data_de, data_ate (Y-m-d)
     * @param list<int>            $pastasOcultas pastas ocultas pelo cadeado para quem olha não contam (D1)
     *
     * @return array<int, int> userId => quantidade
     */
    public function contarUrgentesPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
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
        if ($pastasOcultas !== []) {
            $qb->andWhere('p.id NOT IN (:pastasOcultas)')->setParameter('pastasOcultas', $pastasOcultas);
        }

        return $this->mapaDeContagem($qb);
    }

    /**
     * "Pastas concluídas" ("Demandas encerradas no período" do desenho): pastas que chegaram à etapa
     * Finalizada do andamento no período, por responsável, pela data da chegada (`finalizadaEm`), e
     * não pela abertura nem pela situação. Voltar de fase ou reativar apaga a data, então a pasta sai
     * da conta; pasta excluída (lápide) e pasta oculta pelo cadeado para quem olha ficam fora.
     *
     * @param array<string, mixed> $filtros       data_de, data_ate (Y-m-d)
     * @param list<int>            $pastasOcultas
     *
     * @return array<int, int> userId => quantidade
     */
    public function contarFinalizadasPorResponsavel(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r.id AS pessoa_id, COUNT(p.id) AS total')
            ->from(Pasta::class, 'p')
            ->join('p.responsavel', 'r')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('r.id');

        // O critério é o mesmo do filtro `concluida` do Expediente (regra B12: o número = a lista).
        $desde = $filtros['contar_desde'] ?? null;
        PastaFinalizadaNoPeriodo::aplicar(
            $qb,
            'p',
            $this->lerData((string) ($filtros['data_de'] ?? ''), false),
            $this->lerData((string) ($filtros['data_ate'] ?? ''), true),
            $desde instanceof \DateTimeImmutable ? $desde : null,
        );
        if ($pastasOcultas !== []) {
            $qb->andWhere('p.id NOT IN (:pastasOcultas)')->setParameter('pastasOcultas', $pastasOcultas);
        }

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

    /**
     * "Relatório" (spec dashboard-parte-1, item 2): registros por AUTOR, criados no período, nas três
     * abas que o desenho soma: Dados da pasta (`PastaMensagem`, sem os cartões do sistema),
     * Financeiro e Detalhes. Resposta é um registro e conta; edição não conta de novo (a linha é a
     * mesma). Uma consulta agregada por tabela; pasta excluída (lápide) e pasta oculta pelo
     * cadeado para quem olha ficam fora.
     *
     * @param array<string, mixed> $filtros       data_de, data_ate (Y-m-d)
     * @param list<int>            $pastasOcultas
     *
     * @return array<int, int> userId => quantidade
     */
    public function contarRegistrosPorAutor(Tenant $tenant, array $filtros = [], array $pastasOcultas = []): array
    {
        $mapa = [];
        foreach (array_keys(RegistroDePastaNoPeriodo::ABAS) as $classe) {
            $qb = $this->em->createQueryBuilder()
                ->select('IDENTITY(r.autor) AS pessoa_id, COUNT(r.id) AS total')
                ->from($classe, 'r')
                ->join('r.pasta', 'p')
                ->groupBy('r.autor');
            $this->criterioDeRegistros($qb, $classe, $tenant, $filtros, $pastasOcultas);

            foreach ($this->mapaDeContagem($qb) as $id => $n) {
                $mapa[$id] = ($mapa[$id] ?? 0) + $n;
            }
        }

        return $mapa;
    }

    /**
     * A lista por trás do número da coluna Relatório (modal "Ver relatório"): os registros de UMA
     * pessoa, com o MESMO critério de contagem (RegistroDePastaNoPeriodo), dos mais novos aos mais
     * antigos. Cada aba traz no máximo `$limite` (as mais novas); `total` é a contagem exata, sem
     * limite, e é igual ao número da célula.
     *
     * @param array<string, mixed> $filtros       data_de, data_ate (Y-m-d) e contar_desde (corte do Zerar)
     * @param list<int>            $pastasOcultas
     *
     * @return array{total: int, registros: list<array{id: int, aba: string, pastaId: int, pastaNup: ?string, cliente: ?string, criadaEm: \DateTimeImmutable, conteudo: string}>}
     */
    public function listarRegistrosDoAutor(Tenant $tenant, int $autorId, array $filtros, array $pastasOcultas, int $limite): array
    {
        $total     = 0;
        $registros = [];
        foreach (RegistroDePastaNoPeriodo::ABAS as $classe => $aba) {
            $base = $this->em->createQueryBuilder()
                ->from($classe, 'r')
                ->join('r.pasta', 'p')
                ->andWhere('IDENTITY(r.autor) = :rpAutor')
                ->setParameter('rpAutor', $autorId);
            $this->criterioDeRegistros($base, $classe, $tenant, $filtros, $pastasOcultas);

            $total += (int) (clone $base)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

            $linhas = (clone $base)
                ->select('r.id AS id, r.criadaEm AS criadaEm, r.conteudo AS conteudo, p.id AS pastaId, p.nup AS pastaNup, p.nomeCliente AS cliente')
                ->orderBy('r.criadaEm', 'DESC')
                ->addOrderBy('r.id', 'DESC')
                ->setMaxResults($limite)
                ->getQuery()
                ->getArrayResult();
            foreach ($linhas as $l) {
                $registros[] = [
                    'id'       => (int) $l['id'],
                    'aba'      => $aba,
                    'pastaId'  => (int) $l['pastaId'],
                    'pastaNup' => $l['pastaNup'] !== null ? (string) $l['pastaNup'] : null,
                    'cliente'  => $l['cliente'] !== null ? (string) $l['cliente'] : null,
                    'criadaEm' => $l['criadaEm'],
                    'conteudo' => (string) $l['conteudo'],
                ];
            }
        }

        return ['total' => $total, 'registros' => $registros];
    }

    /**
     * O WHERE compartilhado pela contagem e pela lista: o critério do registro (RegistroDePastaNoPeriodo)
     * com o período do painel e o corte do Zerar lidos dos filtros.
     *
     * @param class-string         $classe
     * @param array<string, mixed> $filtros
     * @param list<int>            $pastasOcultas
     */
    private function criterioDeRegistros(QueryBuilder $qb, string $classe, Tenant $tenant, array $filtros, array $pastasOcultas): void
    {
        $desde = $filtros['contar_desde'] ?? null;
        RegistroDePastaNoPeriodo::aplicar(
            $qb,
            $classe,
            'r',
            'p',
            $tenant,
            $this->lerData((string) ($filtros['data_de'] ?? ''), false),
            $this->lerData((string) ($filtros['data_ate'] ?? ''), true),
            $desde instanceof \DateTimeImmutable ? $desde : null,
            $pastasOcultas,
        );
    }

    /**
     * Cadeado (spec pasta-cadeado D1 e D3): tira as metas das pastas ocultas para quem olha, como o
     * TarefaRepository faz nas colunas de metas. A raiz é `t`.
     *
     * @param list<int> $pastasOcultas
     */
    private function semPastasOcultas(QueryBuilder $qb, array $pastasOcultas): void
    {
        if ($pastasOcultas !== []) {
            $qb->andWhere('IDENTITY(t.pasta) NOT IN (:pastasOcultas)')
               ->setParameter('pastasOcultas', $pastasOcultas);
        }
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

        // "Zerar" (DASH-014): só o que veio a partir do ponto de corte, no mesmo campo de data.
        $desde = $filtros['contar_desde'] ?? null;
        if ($desde instanceof \DateTimeImmutable) {
            $qb->andWhere($campo . ' >= :fExtraDesde')->setParameter('fExtraDesde', $desde);
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
