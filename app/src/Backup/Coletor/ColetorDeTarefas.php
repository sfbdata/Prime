<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;
use App\Tarefa\Armazenamento\ChavesDeTarefa;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tarefa\Service\QuemCuidaDaMeta;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Tarefas (metas) no backup: `dados/tarefas.json` (a meta, as mensagens e os despachos) e
 * `tarefas/<título>/anexos/<arquivo>` (anexo das mensagens).
 *
 * Só roda para quem tem o módulo de tarefas. A meta entra pela mesma regra de abrir a meta por id
 * ({@see \App\Tarefa\Service\AcessoAMeta::exigir()}), que é também o universo das listas das Minhas Metas
 * (`TarefaRepository::findParaMinhasMetas`, com `semPastasOcultas` e `PastasSoAcompanhadasSemVer`): do escritório
 * (`tenant_id` escrito na mão), de pasta fora da lixeira e NÃO oculta para a pessoa ({@see AcessoAPasta::idsDasPastasOcultas()},
 * cadeado e escopo do acesso restrito), e só se ela cuida da meta (responsável ou criador, {@see QuemCuidaDaMeta}) OU
 * pode ver a pasta (`resources.pasta.view`). Quem só acompanha a meta, sem ver a pasta, fica de fora. A meta concluída
 * entra sem o corte dos 30 dias da tela: backup leva o histórico. Sem a lista de acompanhantes (marcação pessoal de cada um).
 *
 * O anexo que é documento da pasta (`pasta_documento:<id>`) já vai com a pasta; aqui entra só o anexo antigo da mensagem.
 */
#[AsTaggedItem(priority: 8)]
final class ColetorDeTarefas implements ColetorDoBackup
{
    public const METAS_POR_LOTE = 50;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly AcessoAPasta $acesso,
        private readonly QuemCuidaDaMeta $quemCuida,
        private readonly EntityManagerInterface $em,
        private readonly int $metasPorLote = self::METAS_POR_LOTE,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, AcessoAoModuloTarefas::MODULO)) {
            return;
        }

        $usuarioId = (int) $usuario->getId();
        $tenantId  = (int) $tenant->getId();
        $ocultas   = $this->acesso->idsDasPastasOcultas($usuario, $tenant);
        $veemPasta = [];
        $dados     = [];
        $raiz      = null;
        $lidas     = 0;
        foreach ($this->metas($tenant, $ocultas) as $meta) {
            $pastaId = (int) $meta->getPasta()->getId();
            $veemPasta[$pastaId] ??= $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW);
            if (!$veemPasta[$pastaId] && !$this->quemCuida->ehResponsavelOuCriador($meta, $usuario)) {
                continue;
            }

            $mensagens = $this->mensagens($meta, $tenant);
            $dados[]   = $this->dadosDaMeta($meta, $mensagens);
            $this->colocarAnexos($meta, $mensagens, $zip, $raiz);
            $zip->contar('Tarefas');

            if (++$lidas % $this->metasPorLote === 0) {
                // Memória do worker: solta o lote e recarrega só a pessoa e o escritório.
                $this->em->clear();
                $usuario = $this->em->find(User::class, $usuarioId) ?? throw new \LogicException('Usuário do backup sumiu.');
                $tenant  = $this->em->find(Tenant::class, $tenantId) ?? throw new \LogicException('Escritório do backup sumiu.');
            }
        }

        $dir = $zip->reservarDiretorio('', 'dados');
        $zip->adicionarJson($dir . '/tarefas.json', $dados);
    }

    /**
     * @param list<int> $pastasOcultas
     *
     * @return iterable<Tarefa>
     */
    private function metas(Tenant $tenant, array $pastasOcultas): iterable
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t', 'p')->from(Tarefa::class, 't')
            ->join('t.pasta', 'p')
            ->andWhere('t.tenant = :tenant')->andWhere('p.tenant = :tenant')->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)->orderBy('t.id');
        if ($pastasOcultas !== []) {
            $qb->andWhere('p.id NOT IN (:ocultas)')->setParameter('ocultas', $pastasOcultas);
        }

        yield from $qb->getQuery()->toIterable();
    }

    /** @return list<TarefaMensagem> */
    private function mensagens(Tarefa $meta, Tenant $tenant): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('m')->from(TarefaMensagem::class, 'm')
            ->andWhere('m.tarefa = :meta')->andWhere('m.tenant = :tenant')
            ->setParameter('meta', $meta->getId())->setParameter('tenant', $tenant)
            ->orderBy('m.criadoEm')->addOrderBy('m.id')->getQuery()->getResult());
    }

    /**
     * @param list<TarefaMensagem> $mensagens
     *
     * @return array<string, mixed>
     */
    private function dadosDaMeta(Tarefa $meta, array $mensagens): array
    {
        $pasta = $meta->getPasta();

        return [
            'numero'            => $meta->getNumero(),
            'titulo'            => $meta->getTitulo(),
            'descricao'         => $meta->getDescricao(),
            'status'            => $meta->getStatus(),
            'prioridade'        => $meta->getPrioridade()->value,
            'etapa'             => $meta->getEtapa()->value,
            'tipo_de_demanda'   => $meta->getTipoDeDemanda()->value,
            'prazo'             => ValoresDoBackup::data($meta->getPrazo()),
            'prazo_juridico'    => ValoresDoBackup::data($meta->getPrazoJuridico()),
            'data_da_publicacao' => ValoresDoBackup::data($meta->getDataDaPublicacao()),
            'data_da_ciencia'   => ValoresDoBackup::data($meta->getDataDaCiencia()),
            'criada_em'         => ValoresDoBackup::data($meta->getDataCriacao()),
            'alterada_em'       => ValoresDoBackup::data($meta->getDataAlteracao()),
            'concluida_em'      => ValoresDoBackup::data($meta->getDataConclusao()),
            'criada_por'        => $meta->getCriadoPor()?->getFullName(),
            'responsaveis'      => array_values(array_map(static fn (User $u): ?string => $u->getFullName(), $meta->getResponsaveis()->toArray())),
            'pasta'             => ['nup' => $pasta->getNup(), 'cliente' => $pasta->getNomeCliente()],
            'mensagens'         => array_map(static fn (TarefaMensagem $m): array => [
                'autor'              => $m->getUsuario()?->getFullName(),
                'texto'              => $m->getMensagem(),
                'em'                 => ValoresDoBackup::data($m->getCriadoEm()),
                'editada_em'         => ValoresDoBackup::data($m->getEditadoEm()),
                'natureza'           => $m->getNatureza()?->value,
                'acao'               => $m->getAcao()?->value,
                'codigo'             => $m->getCodigo(),
                'prazo_cumprimento'  => ValoresDoBackup::data($m->getPrazoCumprimento()),
                'destinatario'       => $m->getDestinatario()?->getFullName(),
                'ciente_em'          => ValoresDoBackup::data($m->getCienteEm()),
                'certidao'           => $m->getCertidao(),
                'certificada_em'     => ValoresDoBackup::data($m->getCertificadoEm()),
                'anexo'              => ChavesDeTarefa::nomeDoValorDaColuna($m->getArquivoAnexo()),
            ], $mensagens),
        ];
    }

    /** @param list<TarefaMensagem> $mensagens */
    private function colocarAnexos(Tarefa $meta, array $mensagens, ZipDoBackup $zip, ?string &$raiz): void
    {
        $dirDosAnexos = null;
        foreach ($mensagens as $mensagem) {
            if ($mensagem->getArquivoAnexo() === null || $mensagem->getDocumentoJuntadoId() !== null) {
                continue;
            }
            if ($dirDosAnexos === null) {
                $raiz ??= $zip->reservarDiretorio('', 'tarefas');
                $titulo       = trim($meta->getTitulo()) !== '' ? $meta->getTitulo() : 'meta ' . $meta->getId();
                $dirDosAnexos = $zip->reservarDiretorio($zip->reservarDiretorio($raiz, $titulo), 'anexos');
            }
            $nome = ChavesDeTarefa::nomeDoValorDaColuna($mensagem->getArquivoAnexo()) ?? 'anexo-' . $mensagem->getId();
            $colocado = $zip->adicionarArquivo($dirDosAnexos, $nome, static fn () => ChavesDeTarefa::anexoDeMensagem($mensagem));
            if ($colocado !== null) {
                $zip->contar('Anexos de tarefas');
            }
        }
    }
}
