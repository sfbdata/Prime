<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\DTO\DocumentoDaPastaRecebida;
use App\Pasta\DTO\PastaRecebida;
use App\Pasta\DTO\PastaRecebidaOutput;
use App\Pasta\DTO\ResumoDaPastaRecebidaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Lê a pasta recebida de outra conta para quem a recebeu (spec acesso-restrito-e-compartilhar §4.5.1). Recebe só o
 * que o `PastaRecebidaDoUsuario::localizar()` devolveu: quem chama já passou pela porta.
 *
 * O filtro do escritório (o da sessão, B) fica desligado durante a leitura E a montagem do DTO (as relações
 * preguiçosas de A morreriam com o filtro de B ligado) e volta ligado no fim, com o mesmo valor. Toda consulta filtra
 * pelo escritório da pasta (A) e pela pasta do convite: nenhum id solto, nada de outra pasta de A nem de B com o mesmo
 * id. Devolve DTO, nunca entidade.
 *
 * O que entra (e só isto): número, cliente, ação, fase atual, as anotações e os registros de andamento (os outros
 * cartões do sistema falam de responsável, cadeado, cobrança, carteira: ficam de fora), documentos fora da lixeira e
 * metas abertas. A aba oculta para quem recebeu não traz nada.
 *
 * Cobrança fica fora sempre: o contrato de honorários (o documento da aba Financeiro, categoria `CONTRATO`) nunca
 * aparece para quem recebeu, nem com a aba Financeiro visível.
 */
final class LeitorDaPastaRecebida
{
    use SemFiltroDeTenant;

    /** Os cartões do sistema que são andamento da pasta (o resto do Registro do sistema fica de fora). */
    private const SISTEMA_QUE_ENTRA = ['fase'];

    private const LIMITE_DE_ANDAMENTOS = 150;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AndamentoDaPasta $andamento,
        private readonly VisibilidadeDasAbasDaPasta $abas,
    ) {
    }

    public function ler(PastaRecebida $recebida): PastaRecebidaOutput
    {
        return $this->semFiltroDeTenant(function () use ($recebida): PastaRecebidaOutput {
            $c = $recebida->compartilhamento;
            $p = $c->permissoes();
            $dados = $recebida->abaVisivel(AbaDaPasta::Dados);
            $documentos = $recebida->abaVisivel(AbaDaPasta::Documentos);
            $metas = $recebida->abaVisivel(AbaDaPasta::Tarefas);
            $estado = $this->andamento->estado($recebida->pasta, $recebida->tenant);

            return new PastaRecebidaOutput(
                $recebida->compartilhamentoId(),
                (string) $recebida->pasta->getNup(),
                (string) $recebida->pasta->getNomeCliente(),
                (string) $recebida->pasta->getNomeAcao(),
                (string) ($estado['fases'][$estado['atual']][0] ?? ''),
                self::nome($recebida->origem),
                (string) $recebida->tenant->getName(),
                $p->movimentar,
                $p->enviarDocumentos,
                $p->baixarDocumentos,
                $recebida->soLeitura,
                $dados,
                $documentos,
                $metas,
                $dados ? $this->andamentos($recebida) : [],
                $documentos ? $this->documentos($recebida) : [],
                $metas ? $this->metasAbertas($recebida) : [],
                $p->movimentar && !$recebida->soLeitura ? self::fasesAoAlcance($estado) : [],
            );
        });
    }

    /** A janela L11: sem as listas, só a contagem das metas abertas (nula com a aba Metas oculta). */
    public function resumo(PastaRecebida $recebida): ResumoDaPastaRecebidaOutput
    {
        return $this->semFiltroDeTenant(function () use ($recebida): ResumoDaPastaRecebidaOutput {
            $p = $recebida->compartilhamento->permissoes();

            return new ResumoDaPastaRecebidaOutput(
                $recebida->compartilhamentoId(),
                (string) $recebida->pasta->getNup(),
                (string) $recebida->pasta->getNomeCliente(),
                (string) $recebida->pasta->getNomeAcao(),
                self::nome($recebida->origem),
                (string) $recebida->tenant->getName(),
                $p->movimentar,
                $p->enviarDocumentos,
                $p->baixarDocumentos,
                $recebida->abaVisivel(AbaDaPasta::Tarefas) ? (int) $this->metasAbertasQb($recebida)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult() : null,
                $recebida->soLeitura,
            );
        });
    }

    /**
     * Um documento desta pasta, fora da lixeira e visível para quem recebeu (aba Documentos; o contrato de honorários
     * nunca). De outra pasta, de outro escritório ou na lixeira: nulo. A chave do arquivo sai da entidade
     * (`ChavesDePasta::documento`), montada aqui dentro da janela sem filtro.
     */
    public function documento(PastaRecebida $recebida, int $documentoId): ?DocumentoDaPastaRecebida
    {
        if (!$recebida->abaVisivel(AbaDaPasta::Documentos)) {
            return null;
        }

        return $this->semFiltroDeTenant(function () use ($recebida, $documentoId): ?DocumentoDaPastaRecebida {
            /** @var PastaDocumento|null $doc */
            $doc = $this->documentosQb($recebida)->andWhere('d.id = :doc')->setParameter('doc', $documentoId)->getQuery()->getOneOrNullResult();
            if ($doc === null) {
                return null;
            }

            return new DocumentoDaPastaRecebida((int) $doc->getId(), ChavesDePasta::documento($doc), $doc->getNomeOriginal());
        });
    }

    /**
     * A coluna Metas das linhas da Lista (L12): metas abertas de cada pasta, no escritório dela, para os convites que
     * já passaram pelo `PastaRecebidaDoUsuario::quaisAbrem()`. Duas consultas no lote (regras da aba Metas e contagem);
     * aba Metas oculta para quem recebeu (a regra "para todos" ou a que alcança quem republicou): nulo, como na janela.
     *
     * @param list<PastaCompartilhamento> $convites
     *
     * @return array<int, ?int> id do convite → metas abertas (nulo com a aba Metas oculta)
     */
    public function metasAbertasDaLista(array $convites): array
    {
        if ($convites === []) {
            return [];
        }

        return $this->semFiltroDeTenant(function () use ($convites): array {
            $ids = array_map(static fn (PastaCompartilhamento $c): int => (int) $c->getPasta()->getId(), $convites);
            $contagem = $this->contagemDeMetasAbertas($ids);
            $regras = [];
            /** @var list<AbaOcultaDaPasta> $achadas */
            $achadas = $this->em->createQueryBuilder()
                ->select('r')->from(AbaOcultaDaPasta::class, 'r')->join('r.pasta', 'p')
                ->andWhere('p.id IN (:ids)')->andWhere('r.tenant = p.tenant')->andWhere('r.aba = :aba')
                ->setParameter('ids', $ids)->setParameter('aba', AbaDaPasta::Tarefas->value)
                ->getQuery()->getResult();
            foreach ($achadas as $r) {
                $regras[(int) $r->getPasta()->getId()] = $r;
            }

            $metas = [];
            foreach ($convites as $c) {
                $pastaId = (int) $c->getPasta()->getId();
                $metas[(int) $c->getId()] = $this->metasOcultas($regras[$pastaId] ?? null, $c) ? null : ($contagem[$pastaId] ?? 0);
            }

            return $metas;
        });
    }

    /**
     * @param list<int> $pastaIds
     *
     * @return array<int, int> id da pasta → metas abertas
     */
    private function contagemDeMetasAbertas(array $pastaIds): array
    {
        /** @var list<array{pasta: int|string, n: int|string}> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.pasta) AS pasta', 'COUNT(t.id) AS n')->from(Tarefa::class, 't')->join('t.pasta', 'p')
            ->andWhere('p.id IN (:ids)')->andWhere('t.tenant = p.tenant')->andWhere('t.status <> :concluida')
            ->setParameter('ids', $pastaIds)->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('t.pasta')
            ->getQuery()->getArrayResult();
        $contagem = [];
        foreach ($linhas as $l) {
            $contagem[(int) $l['pasta']] = (int) $l['n'];
        }

        return $contagem;
    }

    /** A regra de `PastaRecebidaDoUsuario::abasOcultasParaQuemRecebeu()` só para a aba Metas. */
    private function metasOcultas(?AbaOcultaDaPasta $regra, PastaCompartilhamento $c): bool
    {
        if ($regra === null) {
            return false;
        }

        return $regra->isParaTodos()
            || ($regra->alcanca((int) $c->getOrigem()->getId()) && !$this->abas->podeConfigurar($c->getOrigem(), $c->getTenant()));
    }

    /**
     * As fases da troca de fase da página (fatia 9b), com o nome: a regra é a do servidor.
     *
     * @param array{fases: list<array{0: string, 1: string}>, atual: int} $estado
     *
     * @return list<array{indice: int, nome: string}>
     */
    private static function fasesAoAlcance(array $estado): array
    {
        return array_map(static fn (int $k): array => ['indice' => $k, 'nome' => $estado['fases'][$k][0]], FasesDaPastaRecebida::aoAlcance($estado));
    }

    /** @return list<array{autor: string, em: \DateTimeImmutable, texto: string, sistema: bool, resposta: bool}> */
    private function andamentos(PastaRecebida $recebida): array
    {
        /** @var list<PastaMensagem> $mensagens */
        $mensagens = $this->em->createQueryBuilder()
            ->select('m', 'a')->from(PastaMensagem::class, 'm')->leftJoin('m.autor', 'a')
            ->andWhere('m.pasta = :pasta')->andWhere('m.tenant = :tenant')
            ->andWhere('(m.sistema IS NULL OR m.sistema IN (:sistema))')
            ->setParameter('pasta', $recebida->pasta)->setParameter('tenant', $recebida->tenant)
            ->setParameter('sistema', self::SISTEMA_QUE_ENTRA)
            ->orderBy('m.criadaEm', 'DESC')->addOrderBy('m.id', 'DESC')
            ->setMaxResults(self::LIMITE_DE_ANDAMENTOS)
            ->getQuery()->getResult();

        return array_map(static fn (PastaMensagem $m): array => [
            'autor'    => $m->getAutor() !== null ? self::nome($m->getAutor()) : '',
            'em'       => $m->getCriadaEm(),
            'texto'    => $m->getConteudo(),
            'sistema'  => $m->isDoSistema(),
            'resposta' => $m->isResposta(),
        ], $mensagens);
    }

    /** @return list<array{id: int, nome: string, em: \DateTimeImmutable, tamanho: int}> */
    private function documentos(PastaRecebida $recebida): array
    {
        /** @var list<PastaDocumento> $docs */
        $docs = $this->documentosQb($recebida)->orderBy('d.carregadoEm', 'DESC')->addOrderBy('d.id', 'DESC')->getQuery()->getResult();

        return array_map(static fn (PastaDocumento $d): array => [
            'id'      => (int) $d->getId(),
            'nome'    => $d->getTitulo() !== '' ? $d->getTitulo() : $d->getNomeOriginal(),
            'em'      => $d->getCarregadoEm(),
            'tamanho' => $d->getTamanhoBytes(),
        ], $docs);
    }

    /** @return list<array{titulo: string, prazo: ?\DateTimeImmutable, situacao: string}> */
    private function metasAbertas(PastaRecebida $recebida): array
    {
        /** @var list<Tarefa> $metas */
        $metas = $this->metasAbertasQb($recebida)->orderBy('t.prazo', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();

        return array_map(static fn (Tarefa $t): array => [
            'titulo'   => $t->getTitulo(),
            'prazo'    => $t->getPrazo(),
            'situacao' => Tarefa::STATUS_LABELS[$t->getStatus()] ?? $t->getStatus(),
        ], $metas);
    }

    /**
     * Documentos vivos da pasta do convite, no escritório dela; o da subpasta que foi para a lixeira também sai (com o
     * filtro da lixeira ligado, a subpasta na lixeira nem entra no LEFT JOIN; sem ele, o `s.excluidoEm` decide). O
     * contrato de honorários (aba Financeiro) nunca entra.
     */
    private function documentosQb(PastaRecebida $recebida): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('d')->from(PastaDocumento::class, 'd')->leftJoin('d.secao', 's')
            ->andWhere('d.pasta = :pasta')->andWhere('d.tenant = :tenant')->andWhere('d.excluidoEm IS NULL')
            ->andWhere('(IDENTITY(d.secao) IS NULL OR (s.id IS NOT NULL AND s.excluidoEm IS NULL AND s.pasta = :pasta))')
            ->andWhere('(d.categoria IS NULL OR d.categoria <> :contrato)')
            ->setParameter('pasta', $recebida->pasta)->setParameter('tenant', $recebida->tenant)
            ->setParameter('contrato', PastaDocumento::CATEGORIA_CONTRATO);
    }

    private function metasAbertasQb(PastaRecebida $recebida): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('t')->from(Tarefa::class, 't')
            ->andWhere('t.pasta = :pasta')->andWhere('t.tenant = :tenant')->andWhere('t.status <> :concluida')
            ->setParameter('pasta', $recebida->pasta)->setParameter('tenant', $recebida->tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);
    }

    private static function nome(User $u): string
    {
        return (string) ($u->getFullName() ?: $u->getEmail());
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
