<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Armazenamento\ChavesDeKanban;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Entity\KanbanMarcador;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Kanban (murais) no backup: `dados/kanban.json` (quadros, colunas, cartões, comentários, checklists e marcadores) e
 * `kanban/<quadro>/<cartão>/<arquivo>` (anexos dos cartões).
 *
 * Só roda para quem tem o módulo `kanban`. Quadro = do escritório (`tenant_id` escrito na mão) em que a pessoa é a
 * criadora ou participante: a regra da lista e da abertura do quadro (`KanbanBoardRepository::findAcessiveisPorUsuario`
 * e `KanbanBoard::temAcesso`, usadas por `KanbanBoardController::index` e `view`). Quem acessa o quadro vê tudo dentro
 * dele, como a tela. Só os cartões, comentários, checklists e anexos dos quadros que a pessoa acessa.
 */
#[AsTaggedItem(priority: 7)]
final class ColetorDeKanban implements ColetorDoBackup
{
    public const MODULO = 'kanban';

    public const QUADROS_POR_LOTE = 5;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly EntityManagerInterface $em,
        private readonly int $quadrosPorLote = self::QUADROS_POR_LOTE,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $usuarioId = (int) $usuario->getId();
        $tenantId  = (int) $tenant->getId();
        $dados     = [];
        $raiz      = null;
        $lidos     = 0;
        foreach ($this->quadrosDaPessoa($usuario, $tenant) as $quadro) {
            $quadroId = (int) $quadro->getId();
            $dir      = null;
            $cartoes  = [];
            foreach ($this->cartoes($quadroId, $tenant) as $cartao) {
                $cartoes[] = $this->dadosDoCartao($cartao, $tenant);
                $zip->contar('Cartões do Kanban');
                $dir ??= $zip->reservarDiretorio($raiz ??= $zip->reservarDiretorio('', 'kanban'), $quadro->getNome());
                $this->colocarAnexos($cartao, $tenant, $zip, $dir);
            }

            $dados[] = $this->dadosDoQuadro($quadro, $tenant, $cartoes);
            $zip->contar('Quadros do Kanban');

            if (++$lidos % $this->quadrosPorLote === 0) {
                // Memória do worker: solta o lote e recarrega só a pessoa e o escritório.
                $this->em->clear();
                $usuario = $this->em->find(User::class, $usuarioId) ?? throw new \LogicException('Usuário do backup sumiu.');
                $tenant  = $this->em->find(Tenant::class, $tenantId) ?? throw new \LogicException('Escritório do backup sumiu.');
            }
        }

        $dir = $zip->diretorioDeDados();
        $zip->adicionarJson($dir . '/kanban.json', $dados);
    }

    /** @return iterable<KanbanBoard> */
    private function quadrosDaPessoa(User $usuario, Tenant $tenant): iterable
    {
        yield from $this->em->createQueryBuilder()
            ->select('b')->from(KanbanBoard::class, 'b')
            ->andWhere('b.tenant = :tenant')
            ->andWhere('b.criadoPor = :usuario OR :usuario MEMBER OF b.participantes')
            ->setParameter('tenant', $tenant)->setParameter('usuario', $usuario)
            ->orderBy('b.id')->getQuery()->toIterable();
    }

    /** @return list<KanbanCard> */
    private function cartoes(int $quadroId, Tenant $tenant): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('c', 'col')->from(KanbanCard::class, 'c')
            ->leftJoin('c.coluna', 'col')
            ->andWhere('c.board = :quadro')->andWhere('c.tenant = :tenant')
            ->setParameter('quadro', $quadroId)->setParameter('tenant', $tenant)
            ->orderBy('col.posicao')->addOrderBy('c.posicao')->addOrderBy('c.id')->getQuery()->getResult());
    }

    /**
     * @param list<array<string, mixed>> $cartoes
     *
     * @return array<string, mixed>
     */
    private function dadosDoQuadro(KanbanBoard $quadro, Tenant $tenant, array $cartoes): array
    {
        /** @var list<KanbanMarcador> $marcadores */
        $marcadores = $this->em->createQueryBuilder()
            ->select('m')->from(KanbanMarcador::class, 'm')
            ->andWhere('m.board = :quadro')->andWhere('m.tenant = :tenant')
            ->setParameter('quadro', $quadro->getId())->setParameter('tenant', $tenant)
            ->orderBy('m.id')->getQuery()->getResult();

        /** @var list<KanbanColuna> $colunas */
        $colunas = $this->em->createQueryBuilder()
            ->select('c')->from(KanbanColuna::class, 'c')
            ->andWhere('c.board = :quadro')->andWhere('c.tenant = :tenant')
            ->setParameter('quadro', $quadro->getId())->setParameter('tenant', $tenant)
            ->orderBy('c.posicao')->addOrderBy('c.id')->getQuery()->getResult();

        return [
            'nome'          => $quadro->getNome(),
            'descricao'     => $quadro->getDescricao(),
            'cor'           => $quadro->getCor(),
            'criado_por'    => $quadro->getCriadoPor()?->getFullName(),
            'criado_em'     => ValoresDoBackup::data($quadro->getCriadoEm()),
            'participantes' => array_values(array_map(static fn (User $u): ?string => $u->getFullName(), $quadro->getParticipantes()->toArray())),
            'colunas'       => array_map(static fn (KanbanColuna $c): array => ['nome' => $c->getNome(), 'tipo' => $c->getTipo()], $colunas),
            'marcadores'    => array_map(static fn (KanbanMarcador $m): array => ['nome' => $m->getNome(), 'cor' => $m->getCor()], $marcadores),
            'cartoes'       => $cartoes,
        ];
    }

    /** @return array<string, mixed> */
    private function dadosDoCartao(KanbanCard $cartao, Tenant $tenant): array
    {
        return [
            'titulo'         => $cartao->getTitulo(),
            'descricao'      => $cartao->getDescricao(),
            'coluna'         => $cartao->getColuna()?->getNome(),
            'data_vencimento' => ValoresDoBackup::data($cartao->getDataVencimento()),
            'criado_por'     => $cartao->getCriadoPor()?->getFullName(),
            'criado_em'      => ValoresDoBackup::data($cartao->getCriadoEm()),
            'responsaveis'   => $this->responsaveis($cartao, $tenant),
            'marcadores'     => $this->marcadores($cartao, $tenant),
            'checklists'     => $this->checklists($cartao, $tenant),
            'comentarios'    => $this->comentarios($cartao, $tenant),
            'anexos'         => array_map(static fn (KanbanAnexo $a): string => $a->getNomeOriginal(), $this->anexos($cartao, $tenant)),
        ];
    }

    /** @return list<string|null> consulta com o escritório escrito (sem a coleção lazy do cartão) */
    private function responsaveis(KanbanCard $cartao, Tenant $tenant): array
    {
        /** @var list<string|null> $nomes */
        $nomes = $this->em->createQueryBuilder()
            ->select('u.fullName')->from(KanbanCard::class, 'c')->join('c.responsaveis', 'u')
            ->andWhere('c.id = :cartao')->andWhere('c.tenant = :tenant')
            ->setParameter('cartao', $cartao->getId())->setParameter('tenant', $tenant)
            ->orderBy('u.id')->getQuery()->getSingleColumnResult();

        return $nomes;
    }

    /** @return list<string> consulta com o escritório escrito (cartão e marcador), sem a coleção lazy */
    private function marcadores(KanbanCard $cartao, Tenant $tenant): array
    {
        /** @var list<string> $nomes */
        $nomes = $this->em->createQueryBuilder()
            ->select('m.nome')->from(KanbanCard::class, 'c')->join('c.marcadores', 'm')
            ->andWhere('c.id = :cartao')->andWhere('c.tenant = :tenant')->andWhere('m.tenant = :tenant')
            ->setParameter('cartao', $cartao->getId())->setParameter('tenant', $tenant)
            ->orderBy('m.id')->getQuery()->getSingleColumnResult();

        return $nomes;
    }

    /** @return list<array<string, mixed>> */
    private function checklists(KanbanCard $cartao, Tenant $tenant): array
    {
        /** @var list<KanbanChecklist> $listas */
        $listas = $this->em->createQueryBuilder()
            ->select('l')->from(KanbanChecklist::class, 'l')
            ->andWhere('l.card = :cartao')->andWhere('l.tenant = :tenant')
            ->setParameter('cartao', $cartao->getId())->setParameter('tenant', $tenant)
            ->orderBy('l.posicao')->addOrderBy('l.id')->getQuery()->getResult();

        return array_map(function (KanbanChecklist $l) use ($tenant): array {
            /** @var list<KanbanChecklistItem> $itens */
            $itens = $this->em->createQueryBuilder()
                ->select('i')->from(KanbanChecklistItem::class, 'i')
                ->andWhere('i.checklist = :lista')->andWhere('i.tenant = :tenant')
                ->setParameter('lista', $l->getId())->setParameter('tenant', $tenant)
                ->orderBy('i.posicao')->addOrderBy('i.id')->getQuery()->getResult();

            return [
                'titulo' => $l->getTitulo(),
                'itens'  => array_map(static fn (KanbanChecklistItem $i): array => ['texto' => $i->getTexto(), 'concluido' => $i->isConcluido()], $itens),
            ];
        }, $listas);
    }

    /** @return list<array<string, mixed>> */
    private function comentarios(KanbanCard $cartao, Tenant $tenant): array
    {
        /** @var list<KanbanComentario> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('c')->from(KanbanComentario::class, 'c')
            ->andWhere('c.card = :cartao')->andWhere('c.tenant = :tenant')
            ->setParameter('cartao', $cartao->getId())->setParameter('tenant', $tenant)
            ->orderBy('c.criadoEm')->addOrderBy('c.id')->getQuery()->getResult();

        return array_map(static fn (KanbanComentario $c): array => [
            'autor' => $c->getCriadoPor()?->getFullName(),
            'texto' => $c->getConteudo(),
            'em'    => ValoresDoBackup::data($c->getCriadoEm()),
        ], $linhas);
    }

    /** @return list<KanbanAnexo> */
    private function anexos(KanbanCard $cartao, Tenant $tenant): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('a')->from(KanbanAnexo::class, 'a')
            ->andWhere('a.card = :cartao')->andWhere('a.tenant = :tenant')
            ->setParameter('cartao', $cartao->getId())->setParameter('tenant', $tenant)
            ->orderBy('a.criadoEm')->addOrderBy('a.id')->getQuery()->getResult());
    }

    private function colocarAnexos(KanbanCard $cartao, Tenant $tenant, ZipDoBackup $zip, string $dirDoQuadro): void
    {
        $dirDoCartao = null;
        foreach ($this->anexos($cartao, $tenant) as $anexo) {
            $dirDoCartao ??= $zip->reservarDiretorio($dirDoQuadro, $cartao->getTitulo() !== '' ? $cartao->getTitulo() : 'cartão ' . $cartao->getId());
            $colocado = $zip->adicionarArquivo($dirDoCartao, $anexo->getNomeOriginal(), static fn () => ChavesDeKanban::anexo($anexo), $anexo->getTamanho());
            if ($colocado !== null) {
                $zip->contar('Anexos do Kanban');
            }
        }
    }
}
