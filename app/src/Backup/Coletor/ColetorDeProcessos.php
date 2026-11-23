<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Processo\Entity\AssuntoProcesso;
use App\Processo\Entity\DocumentoProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Processos no backup: `dados/processos.json` (cadastro, partes, movimentações, assuntos, notas técnicas e a lista dos
 * documentos do processo).
 *
 * Só roda para quem tem o módulo de processos. Os processos são os do escritório (`tenant_id` escrito na mão), MENOS os
 * que a tela de processos esconde: {@see AcessoAPasta::idsDosProcessosOcultos()} (todas as pastas ocultas pelo cadeado
 * e, no acesso restrito, o processo sem pasta sob a responsabilidade da pessoa), a mesma conferência do `ProcessoController`
 * (`index` e `show`), e depois `resources.processo.view` por processo, como a tela.
 *
 * Os documentos do processo (`DocumentoProcesso`) não têm arquivo no armazenamento: a coluna só aponta um caminho de
 * origem e nada em `src/` grava nela (ver `ExcluirProcessoUseCase`). Por isso entram só como metadados no JSON, sem
 * arquivo no ZIP. Sem a variante de `criterioDeVisivel` e afins: elas leem a sessão.
 */
#[AsTaggedItem(priority: 9)]
final class ColetorDeProcessos implements ColetorDoBackup
{
    public const MODULO = 'processos';

    public const PROCESSOS_POR_LOTE = 25;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly AcessoAPasta $acesso,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly EntityManagerInterface $em,
        private readonly int $processosPorLote = self::PROCESSOS_POR_LOTE,
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
        $lidos     = 0;
        $ocultos   = $this->acesso->idsDosProcessosOcultos($usuario, $tenant);
        $pastasOcultas = $this->acesso->idsDasPastasOcultas($usuario, $tenant);
        $abasOcultas   = $this->abas->abasOcultasPorPasta($usuario, $tenant);
        $veemPasta     = [];
        foreach ($this->processosVisiveis($tenant, $ocultos) as $processo) {
            if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PROCESSO, (int) $processo->getId(), AccessRequest::ACTION_VIEW)) {
                continue;
            }
            $notas = $this->vePush($processo, $usuario, $tenant, $pastasOcultas, $abasOcultas, $veemPasta);
            $dados[] = $this->dadosDoProcesso($processo, $tenant, $ocultos, $notas);
            $zip->contar('Processos');

            if (++$lidos % $this->processosPorLote === 0) {
                // Memória do worker: solta o lote e recarrega só a pessoa e o escritório.
                $this->em->clear();
                $usuario = $this->em->find(User::class, $usuarioId) ?? throw new \LogicException('Usuário do backup sumiu.');
                $tenant  = $this->em->find(Tenant::class, $tenantId) ?? throw new \LogicException('Escritório do backup sumiu.');
            }
        }

        $dir = $zip->diretorioDeDados();
        $zip->adicionarJson($dir . '/processos.json', $dados);
    }

    /**
     * @param list<int> $ocultos
     *
     * @return iterable<Processo>
     */
    private function processosVisiveis(Tenant $tenant, array $ocultos): iterable
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')->from(Processo::class, 'p')
            ->andWhere('p.tenant = :tenant')->setParameter('tenant', $tenant)
            ->orderBy('p.id');
        if ($ocultos !== []) {
            $qb->andWhere('p.id NOT IN (:ocultos)')->setParameter('ocultos', $ocultos);
        }

        yield from $qb->getQuery()->toIterable();
    }

    /**
     * @param list<int> $ocultos
     *
     * @return array<string, mixed>
     */
    private function dadosDoProcesso(Processo $processo, Tenant $tenant, array $ocultos, bool $comNotas): array
    {
        return [
            'numero'          => $processo->getNumeroProcesso(),
            'tribunal'        => trim($processo->getSiglaTribunal()),
            'classe'          => trim($processo->getClasseProcessual()),
            'assunto'         => trim($processo->getAssuntoProcessual()),
            'orgao_julgador'  => trim($processo->getOrgaoJulgador()),
            'instancia'       => $processo->getInstanciaRotulo(),
            'situacao'        => trim($processo->getSituacaoProcesso()),
            'distribuido_em'  => ValoresDoBackup::dia($processo->getDataDistribuicao()),
            'baixado_em'      => ValoresDoBackup::dia($processo->getDataBaixa()),
            'segredo_de_justica' => $processo->emSegredoDeJustica(),
            'processo_pai'    => $this->processoPai($processo, $tenant, $ocultos),
            'criado_em'       => ValoresDoBackup::data($processo->getCriadoAt()),
            'partes'          => $this->partes($processo, $tenant),
            'assuntos'        => $this->assuntos($processo, $tenant),
            'movimentacoes'   => $this->movimentacoes($processo, $tenant),
            'notas_tecnicas'  => $comNotas ? $this->notas($processo, $tenant) : [],
            'documentos'      => $this->documentos($processo, $tenant),
        ];
    }

    /**
     * O número do processo pai só sai se o pai não estiver entre os processos que a pessoa não pode ver (a tela
     * não mostra o vínculo com um processo que o cadeado esconde).
     *
     * @param list<int> $ocultos
     */
    private function processoPai(Processo $processo, Tenant $tenant, array $ocultos): ?string
    {
        $pai = $processo->getProcessoPai();
        if ($pai === null || $pai === '' || $ocultos === []) {
            return $pai;
        }
        $escondido = $this->em->createQueryBuilder()
            ->select('COUNT(x.id)')->from(Processo::class, 'x')
            ->andWhere('x.tenant = :tenant')->andWhere('x.numeroProcesso = :numero')->andWhere('x.id IN (:ocultos)')
            ->setParameter('tenant', $tenant)->setParameter('numero', $pai)->setParameter('ocultos', $ocultos)
            ->getQuery()->getSingleScalarResult();

        return (int) $escondido > 0 ? null : $pai;
    }

    /**
     * A nota técnica é LIDA nos cartões da aba Push da pasta (`PastaController.php:473-486`, a rota de nota só
     * escreve): entra quando alguma pasta do escritório que contém o processo é visível à pessoa (sem cadeado
     * oculto, `canAccessResource` de ver) e não esconde dela a aba Push. As rotas de nota (`NotaTecnicaController`)
     * exigem `edit` só para criar, editar e excluir.
     *
     * @param list<int>                  $pastasOcultas
     * @param array<int, list<AbaDaPasta>> $abasOcultas
     * @param array<int, bool>           $veemPasta cache por pasta
     */
    private function vePush(Processo $processo, User $usuario, Tenant $tenant, array $pastasOcultas, array $abasOcultas, array &$veemPasta): bool
    {
        /** @var list<PastaProcesso> $vinculos */
        $vinculos = $this->em->createQueryBuilder()
            ->select('v', 'p')->from(PastaProcesso::class, 'v')->join('v.pasta', 'p')
            ->andWhere('v.processo = :processo')->andWhere('p.tenant = :tenant')->andWhere('p.excluidaEm IS NULL')
            ->setParameter('processo', $processo->getId())->setParameter('tenant', $tenant)
            ->getQuery()->getResult();
        foreach ($vinculos as $v) {
            $pastaId = (int) $v->getPasta()->getId();
            if (in_array($pastaId, $pastasOcultas, true) || in_array(AbaDaPasta::Push, $abasOcultas[$pastaId] ?? [], true)) {
                continue;
            }
            $veemPasta[$pastaId] ??= $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW);
            if ($veemPasta[$pastaId]) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function partes(Processo $processo, Tenant $tenant): array
    {
        /** @var list<ParteProcesso> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('x')->from(ParteProcesso::class, 'x')
            ->andWhere('x.processo = :processo')->andWhere('x.tenant = :tenant')
            ->setParameter('processo', $processo->getId())->setParameter('tenant', $tenant)
            ->orderBy('x.id')->getQuery()->getResult();

        return array_map(static fn (ParteProcesso $x): array => [
            'tipo'      => $x->getTipo(),
            'papel'     => $x->getPapel(),
            'nome'      => $x->getNome(),
            'documento' => $x->getDocumento(),
        ], $linhas);
    }

    /** @return list<array<string, mixed>> */
    private function assuntos(Processo $processo, Tenant $tenant): array
    {
        /** @var list<AssuntoProcesso> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('x')->from(AssuntoProcesso::class, 'x')
            ->andWhere('x.processo = :processo')->andWhere('x.tenant = :tenant')
            ->setParameter('processo', $processo->getId())->setParameter('tenant', $tenant)
            ->orderBy('x.id')->getQuery()->getResult();

        return array_map(static fn (AssuntoProcesso $x): array => ['codigo' => $x->getCodigo(), 'nome' => $x->getNome()], $linhas);
    }

    /** @return list<array<string, mixed>> */
    private function movimentacoes(Processo $processo, Tenant $tenant): array
    {
        /** @var list<MovimentacaoProcesso> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('x')->from(MovimentacaoProcesso::class, 'x')
            ->andWhere('x.processo = :processo')->andWhere('x.tenant = :tenant')
            ->setParameter('processo', $processo->getId())->setParameter('tenant', $tenant)
            ->orderBy('x.dataMovimentacao', 'DESC')->addOrderBy('x.id', 'DESC')->getQuery()->getResult();

        return array_map(static fn (MovimentacaoProcesso $x): array => [
            'data'         => ValoresDoBackup::data($x->getDataMovimentacao()),
            'descricao'    => $x->getDescricao(),
            'tipo'         => $x->getTipo(),
            'orgao'        => $x->getOrgao(),
            'complementos' => $x->getComplementosResumo(),
        ], $linhas);
    }

    /** @return list<array<string, mixed>> */
    private function notas(Processo $processo, Tenant $tenant): array
    {
        /** @var list<NotaTecnica> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('x')->from(NotaTecnica::class, 'x')
            ->andWhere('x.processo = :processo')->andWhere('x.tenant = :tenant')
            ->setParameter('processo', $processo->getId())->setParameter('tenant', $tenant)
            ->orderBy('x.criadaEm')->addOrderBy('x.id')->getQuery()->getResult();

        return array_map(static fn (NotaTecnica $x): array => [
            'autor'     => $x->getAutor()?->getFullName(),
            'conteudo'  => $x->getConteudo(),
            'criada_em' => ValoresDoBackup::data($x->getCriadaEm()),
            'editada_em' => ValoresDoBackup::data($x->getEditadaEm()),
        ], $linhas);
    }

    /** @return list<array<string, mixed>> só os metadados: o documento do processo não tem arquivo guardado */
    private function documentos(Processo $processo, Tenant $tenant): array
    {
        /** @var list<DocumentoProcesso> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('x')->from(DocumentoProcesso::class, 'x')
            ->andWhere('x.processo = :processo')->andWhere('x.tenant = :tenant')
            ->setParameter('processo', $processo->getId())->setParameter('tenant', $tenant)
            ->orderBy('x.id')->getQuery()->getResult();

        return array_map(static fn (DocumentoProcesso $x): array => [
            'tipo'          => $x->getTipo(),
            'nome_original' => $x->getNomeOriginal(),
            'tipo_de_arquivo' => $x->getMimeType(),
            'tamanho_bytes' => $x->getTamanho(),
            'criado_em'     => ValoresDoBackup::data($x->getCriadoEm()),
        ], $linhas);
    }
}
