<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Assinatura eletrônica no backup: `dados/assinaturas.json` (documento, signatários e trilha de eventos) e
 * `assinaturas/<documento>/` com o PDF enviado, as versões e o PDF final assinado.
 *
 * Só roda para quem tem o módulo `assinatura_eletronica`. A lista da tela mostra TODOS os documentos do escritório a
 * quem tem o módulo (`ListarDocumentosUseCase.php:21-35`, `AcessoAAssinatura::quemPode` em `AcessoAAssinatura.php:30`),
 * então entram todos os do escritório (`tenant_id` escrito na mão), menos os da Lixeira (`excluido_em`). O rótulo da pasta
 * vai como texto, como na lista; o vínculo vivo com a pasta não.
 *
 * Fora do ZIP, por serem dado pessoal de terceiros ou segredo: selfie e rubrica dos signatários, IP, navegador,
 * latitude, longitude e precisão (da linha do signatário e de dentro dos eventos), hash de link e de código de
 * assinatura, tentativas e bloqueios, e os convites com link.
 */
#[AsTaggedItem(priority: 4)]
final class ColetorDaAssinatura implements ColetorDoBackup
{
    use PercorreEmPaginasTrait;

    public const DOCUMENTOS_POR_PAGINA = 20;

    /** Chaves de `dados` do evento que nunca saem (evidência de terceiro ou segredo). */
    private const DADOS_PROIBIDOS = '/latitude|longitude|precisao|selfie|rubrica|^ip$|token|codigo|link|user_agent|navegador/i';

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly EntityManagerInterface $em,
        private readonly int $porPagina = self::DOCUMENTOS_POR_PAGINA,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, AcessoAAssinatura::MODULO)) {
            return;
        }

        $tenantId = (int) $tenant->getId();
        $dados    = [];
        $raiz     = null;
        $ajuste   = static fn (QueryBuilder $qb) => $qb->andWhere('e.excluidoEm IS NULL');
        foreach ($this->paginas(DocumentoParaAssinar::class, $tenantId, $this->porPagina, $ajuste) as $pagina) {
            foreach ($pagina as $documento) {
                \assert($documento instanceof DocumentoParaAssinar);
                $versoes  = $this->versoes($documento, $tenantId);
                $dados[]  = $this->dadosDoDocumento($documento, $tenantId, $versoes);
                $raiz   ??= $zip->reservarDiretorio('', 'assinaturas');
                $this->colocarArquivos($documento, $versoes, $zip, $zip->reservarDiretorio($raiz, $documento->getNome()));
                $zip->contar('Documentos de assinatura');
            }
        }

        $dir = $zip->reservarDiretorio('', 'dados');
        $zip->adicionarJson($dir . '/assinaturas.json', $dados);
    }

    /** @param list<VersaoDoPdf> $versoes */
    private function colocarArquivos(DocumentoParaAssinar $documento, array $versoes, ZipDoBackup $zip, string $dir): void
    {
        if (ChavesDeAssinaturaEletronica::arquivo($documento) !== null) {
            $this->colocar($zip, $dir, 'original-' . ($documento->getArquivoEnviadoNome() ?? 'documento.pdf'), static fn () => ChavesDeAssinaturaEletronica::arquivo($documento) ?? throw new \LogicException('sem chave'));
        }
        if (ChavesDeAssinaturaEletronica::pdfFinal($documento) !== null) {
            $this->colocar($zip, $dir, 'final-assinado.pdf', static fn () => ChavesDeAssinaturaEletronica::pdfFinal($documento) ?? throw new \LogicException('sem chave'));
        }
        foreach ($versoes as $versao) {
            $this->colocar($zip, $dir, sprintf('versao-%02d-%s-%s', $versao->getOrdem(), $versao->getOrigem()->value, $versao->getNomeOriginal()), static fn () => ChavesDeAssinaturaEletronica::versao($versao), $versao->getTamanho());
        }
    }

    private function colocar(ZipDoBackup $zip, string $dir, string $nome, \Closure $chave, ?int $tamanho = null): void
    {
        if ($zip->adicionarArquivo($dir, $nome, $chave, $tamanho) !== null) {
            $zip->contar('Arquivos de assinatura');
        }
    }

    /** @return list<VersaoDoPdf> */
    private function versoes(DocumentoParaAssinar $documento, int $tenantId): array
    {
        return $this->filhos(VersaoDoPdf::class, $documento, $tenantId, 'x.ordem');
    }

    /**
     * @param class-string $classe
     *
     * @return list<object>
     */
    private function filhos(string $classe, DocumentoParaAssinar $documento, int $tenantId, string $ordem): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('x')->from($classe, 'x')->join('x.documento', 'd')
            ->andWhere('x.documento = :documento')->andWhere('IDENTITY(d.tenant) = :tenantId')
            ->setParameter('documento', $documento->getId())->setParameter('tenantId', $tenantId)
            ->orderBy($ordem)->addOrderBy('x.id')->getQuery()->getResult());
    }

    /**
     * @param list<VersaoDoPdf> $versoes
     *
     * @return array<string, mixed>
     */
    private function dadosDoDocumento(DocumentoParaAssinar $d, int $tenantId, array $versoes): array
    {
        /** @var list<Signatario> $signatarios */
        $signatarios = $this->filhos(Signatario::class, $d, $tenantId, 'x.ordem');
        /** @var list<EventoDaTrilha> $eventos */
        $eventos = $this->filhos(EventoDaTrilha::class, $d, $tenantId, 'x.criadoEm');

        return [
            'nome'         => $d->getNome(),
            'categoria'    => $d->getCategoria(),
            'situacao'     => $d->getSituacao()->value,
            'pasta'        => $d->getPastaRotulo(),
            'codigo'       => $d->getCodigo(),
            'mensagem'     => $d->getMensagem(),
            'prazo_dias'   => $d->getPrazoDias(),
            'criado_por'   => $d->getCriadoPor()?->getFullName(),
            'criado_em'    => ValoresDoBackup::data($d->getCriadoEm()),
            'enviado_em'   => ValoresDoBackup::data($d->getEnviadoEm()),
            'vence_em'     => ValoresDoBackup::data($d->getVenceEm()),
            'concluido_em' => ValoresDoBackup::data($d->getConcluidoEm()),
            'hash_enviado' => $d->getHashEnviado(),
            'hash_final'   => $d->getHashFinal(),
            'blocos'       => $d->getBlocos(),
            'signatarios'  => $this->signatariosDe($signatarios),
            'versoes'      => $this->versoesDe($versoes),
            'trilha'       => $this->trilhaDe($eventos),
        ];
    }

    /**
     * @param list<Signatario> $signatarios
     *
     * @return list<array<string, mixed>>
     */
    private function signatariosDe(array $signatarios): array
    {
        return array_map(static fn (Signatario $s): array => [
            'ordem'          => $s->getOrdem(),
            'nome'           => $s->getNome(),
            'email'          => $s->getEmail(),
            'cpf'            => $s->getCpf(),
            'papel'          => $s->getPapel(),
            'nivel'          => $s->getNivel()->value,
            'situacao'       => $s->getSituacao()->value,
            'assinado_em'    => ValoresDoBackup::data($s->getAssinadoEm()),
            'nome_digitado'  => $s->getNomeDigitado(),
            'metodo'         => $s->getMetodo(),
            'motivo_recusa'  => $s->getMotivoRecusa(),
        ], $signatarios);
    }

    /**
     * @param list<VersaoDoPdf> $versoes
     *
     * @return list<array<string, mixed>>
     */
    private function versoesDe(array $versoes): array
    {
        return array_map(static fn (VersaoDoPdf $v): array => [
            'ordem' => $v->getOrdem(), 'origem' => $v->getOrigem()->value, 'arquivo' => $v->getNomeOriginal(), 'sha256' => $v->getSha256(),
            'tamanho_bytes' => $v->getTamanho(), 'criado_em' => ValoresDoBackup::data($v->getCriadoEm()),
        ], $versoes);
    }

    /**
     * @param list<EventoDaTrilha> $eventos
     *
     * @return list<array<string, mixed>>
     */
    private function trilhaDe(array $eventos): array
    {
        return array_map(fn (EventoDaTrilha $e): array => [
            'tipo'        => $e->getTipo()->value,
            'em'          => ValoresDoBackup::data($e->getCriadoEm()),
            'signatario'  => $e->getSignatario()?->getNome(),
            'usuario'     => $e->getUsuario()?->getFullName(),
            'dados'       => $this->semEvidencia($e->getDados()),
            'hash'        => $e->getHash(),
            'hash_anterior' => $e->getHashAnterior(),
        ], $eventos);
    }

    /**
     * @param array<mixed> $dados
     *
     * @return array<mixed>
     */
    private function semEvidencia(array $dados): array
    {
        $limpo = [];
        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && preg_match(self::DADOS_PROIBIDOS, $chave) === 1) {
                continue;
            }
            $limpo[$chave] = is_array($valor) ? $this->semEvidencia($valor) : $valor;
        }

        return $limpo;
    }
}
