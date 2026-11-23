<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\DadosDaPastaParaBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Pastas no backup: `pastas/<NUP ou nome>/dados.json` e `pastas/<NUP ou nome>/documentos/<árvore de seções>/<arquivo>`.
 *
 * Só roda para quem tem o módulo de pastas e, pasta a pasta, o `resources.pasta.view` (como a tela). Diretório de
 * seção só existe no ZIP se tiver arquivo (o `ZipArchive` não cria diretório vazio): seção com itens só ocultos não aparece. Pastas visíveis = as do escritório (`tenant_id` escrito na mão), fora a
 * lixeira (`excluida_em`), MENOS as ocultas pelo cadeado e pelo escopo ({@see AcessoAPasta::idsDasPastasOcultas()}) e,
 * no acesso restrito, só as do escopo ({@see EscopoDePastas::idsNoEscopo()}). Só as variantes com `User` e `Tenant`:
 * as que leem a sessão deixariam o perfil restrito sem filtro no worker.
 */
#[AsTaggedItem(priority: 20)]
final class ColetorDePastas implements ColetorDoBackup
{
    public const MODULO = 'pastas';

    public const PASTAS_POR_LOTE = 25;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly AcessoAPasta $acesso,
        private readonly EscopoDePastas $escopo,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly DadosDaPastaParaBackup $dados,
        private readonly EntityManagerInterface $em,
        private readonly int $pastasPorLote = self::PASTAS_POR_LOTE,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $usuarioId   = (int) $usuario->getId();
        $tenantId    = (int) $tenant->getId();
        $abasOcultas = $this->abas->abasOcultasPorPasta($usuario, $tenant);
        $raiz        = null;
        $lidas       = 0;
        foreach ($this->pastasVisiveis($usuario, $tenant) as $pasta) {
            if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
                continue;
            }
            $raiz ??= $zip->reservarDiretorio('', 'pastas');
            $this->coletarPasta($pasta, $tenant, $abasOcultas[(int) $pasta->getId()] ?? [], $zip, $raiz);
            $zip->contar('Pastas');

            if (++$lidas % $this->pastasPorLote === 0) {
                // Memória do worker: solta o que o lote carregou e recarrega só a pessoa e o escritório.
                $this->em->clear();
                $usuario = $this->em->find(User::class, $usuarioId) ?? throw new \LogicException('Usuário do backup sumiu.');
                $tenant  = $this->em->find(Tenant::class, $tenantId) ?? throw new \LogicException('Escritório do backup sumiu.');
            }
        }
    }

    /**
     * @param list<AbaDaPasta> $abasOcultas
     */
    private function coletarPasta(Pasta $pasta, Tenant $tenant, array $abasOcultas, ZipDoBackup $zip, string $raiz): void
    {
        $rotulo = trim((string) $pasta->getNup()) !== '' ? (string) $pasta->getNup() : ((string) $pasta->getNomeCliente() ?: 'pasta ' . $pasta->getId());
        $dir    = $zip->reservarDiretorio($raiz, $rotulo);

        $zip->adicionarJson($dir . '/dados.json', $this->dados->montar($pasta, $tenant, $abasOcultas));
        if (!in_array(AbaDaPasta::Documentos, $abasOcultas, true)) {
            $this->coletarDocumentos($pasta, $tenant, array_map(static fn (AbaDaPasta $a): string => $a->value, $abasOcultas), $zip, $dir);
        }
    }

    /** @return iterable<Pasta> */
    private function pastasVisiveis(User $usuario, Tenant $tenant): iterable
    {
        $ocultas = $this->acesso->idsDasPastasOcultas($usuario, $tenant);
        $escopo  = $this->escopo->idsNoEscopo($usuario, $tenant);
        if ($escopo === []) {
            return;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('p')->from(Pasta::class, 'p')
            ->andWhere('p.tenant = :tenant')->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)->orderBy('p.id');
        if ($ocultas !== []) {
            $qb->andWhere('p.id NOT IN (:ocultas)')->setParameter('ocultas', $ocultas);
        }
        if ($escopo !== null) {
            $qb->andWhere('p.id IN (:escopo)')->setParameter('escopo', $escopo);
        }

        yield from $qb->getQuery()->toIterable();
    }

    /** @param list<string> $abasOcultas */
    private function coletarDocumentos(Pasta $pasta, Tenant $tenant, array $abasOcultas, ZipDoBackup $zip, string $dirDaPasta): void
    {
        $filhasPor = [];
        $vivas     = [];
        foreach ($this->secoes($pasta, $tenant) as $secao) {
            $vivas[(int) $secao->getId()] = true;
            $filhasPor[$secao->getPai()?->getId() ?? 0][] = $secao;
        }
        $documentosPor = [];
        foreach (VisibilidadeDasAbasDaPasta::documentosVisiveis($this->documentos($pasta, $tenant), $abasOcultas) as $documento) {
            $documentosPor[$documento->getSecao()?->getId() ?? 0][] = $documento;
        }

        $dir = $zip->reservarDiretorio($dirDaPasta, 'documentos');
        $this->colocarDocumentos($documentosPor[0] ?? [], $dir, $zip);
        foreach ($this->raizesDaArvore($filhasPor, $vivas) as $secao) {
            $this->percorrer($secao, $dir, $filhasPor, $documentosPor, $zip, [(int) $secao->getId() => true]);
        }
    }

    /**
     * Seções sem pai ou cujo pai saiu da árvore viva (lixeira): nada fica inalcançável.
     *
     * @param array<int, list<PastaSecao>> $filhasPor
     * @param array<int, true>             $vivas
     *
     * @return list<PastaSecao>
     */
    private function raizesDaArvore(array $filhasPor, array $vivas): array
    {
        $raizes = $filhasPor[0] ?? [];
        foreach ($filhasPor as $paiId => $filhas) {
            if ($paiId !== 0 && !isset($vivas[$paiId])) {
                array_push($raizes, ...$filhas);
            }
        }

        return $raizes;
    }

    /**
     * @param array<int, list<PastaSecao>>     $filhasPor
     * @param array<int, list<PastaDocumento>> $documentosPor
     * @param array<int, true>                 $visitados
     */
    private function percorrer(PastaSecao $secao, string $dirPai, array $filhasPor, array $documentosPor, ZipDoBackup $zip, array $visitados): void
    {
        $dir = $zip->reservarDiretorio($dirPai, $secao->getNome());
        $this->colocarDocumentos($documentosPor[(int) $secao->getId()] ?? [], $dir, $zip);

        foreach ($filhasPor[(int) $secao->getId()] ?? [] as $filha) {
            $id = (int) $filha->getId();
            if (isset($visitados[$id]) || count($visitados) >= PastaSecao::LIMITE_SEGURANCA) {
                continue;
            }
            $this->percorrer($filha, $dir, $filhasPor, $documentosPor, $zip, $visitados + [$id => true]);
        }
    }

    /** @param list<PastaDocumento> $documentos */
    private function colocarDocumentos(array $documentos, string $dir, ZipDoBackup $zip): void
    {
        foreach ($documentos as $documento) {
            $colocado = $zip->adicionarArquivo(
                $dir,
                $documento->getNomeOriginal(),
                static fn () => ChavesDePasta::documento($documento),
                $documento->getTamanhoBytes(),
            );
            if ($colocado !== null) {
                $zip->contar('Documentos de pastas');
            }
        }
    }

    /** @return list<PastaSecao> */
    private function secoes(Pasta $pasta, Tenant $tenant): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('s')->from(PastaSecao::class, 's')
            ->andWhere('s.pasta = :pasta')->andWhere('s.tenant = :tenant')->andWhere('s.excluidoEm IS NULL')
            ->setParameter('pasta', $pasta->getId())->setParameter('tenant', $tenant)
            ->orderBy('s.ordem')->addOrderBy('s.id')->getQuery()->getResult());
    }

    /** @return list<PastaDocumento> */
    private function documentos(Pasta $pasta, Tenant $tenant): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('d')->from(PastaDocumento::class, 'd')
            ->andWhere('d.pasta = :pasta')->andWhere('d.tenant = :tenant')->andWhere('d.excluidoEm IS NULL')
            ->setParameter('pasta', $pasta->getId())->setParameter('tenant', $tenant)
            ->orderBy('d.ordem')->addOrderBy('d.id')->getQuery()->getResult());
    }
}
