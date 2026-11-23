<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\TipoMensagem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Trabalho Externo no backup: `dados/trabalho_externo.json` e `trabalho_externo/<demanda>/` com os documentos, SÓ das
 * demandas que a PRÓPRIA pessoa criou (`criado_por`), no escritório dela (`tenant_id`), escritos na mão.
 *
 * Só roda para quem tem o módulo `trabalho_externo` (`ResolvedorDePerfil::MODULO`). A tela do solicitante mostra as
 * demandas do escritório (`PoliticaDaDemanda.php:66`), mas o backup é mais estrito de propósito: só o que a pessoa abriu.
 * Entram os campos da demanda, as propostas, as mensagens do chat que não são nota interna da equipe nem foram apagadas,
 * e os documentos anexados. Nunca entram notas internas, convites, contatos de WhatsApp nem demanda de colega.
 */
#[AsTaggedItem(priority: 1)]
final class ColetorDoTrabalhoExterno implements ColetorDoBackup
{
    use PercorreEmPaginasTrait;

    public const MODULO = 'trabalho_externo';

    public const DEMANDAS_POR_PAGINA = 25;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly EntityManagerInterface $em,
        private readonly int $porPagina = self::DEMANDAS_POR_PAGINA,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $usuarioId = (int) $usuario->getId();
        $ajuste    = static fn ($qb) => $qb->andWhere('IDENTITY(e.criadoPor) = :pessoa')->setParameter('pessoa', $usuarioId);
        $linhas    = [];
        $raiz      = null;
        foreach ($this->paginas(DemandaExterna::class, (int) $tenant->getId(), $this->porPagina, $ajuste) as $pagina) {
            foreach ($pagina as $d) {
                \assert($d instanceof DemandaExterna);
                $dir      = null;
                $arquivos = [];
                foreach ($d->getDocumentos() as $doc) {
                    $raiz ??= $zip->reservarDiretorio('', 'trabalho_externo');
                    $dir  ??= $zip->reservarDiretorio($raiz, $d->getCodigo());
                    $zip->adicionarArquivo($dir, $doc->getNomeOriginal(), static fn () => ChavesDeTrabalhoExterno::documento($doc), $doc->getTamanho());
                    $arquivos[] = $doc->getNomeOriginal();
                    $zip->contar('Documentos de trabalho externo');
                }
                $linhas[] = $this->linhaDaDemanda($d, $arquivos);
                $zip->contar('Demandas de trabalho externo');
            }
        }

        $dir = $zip->diretorioDeDados();
        $zip->adicionarJson($dir . '/trabalho_externo.json', $linhas);
    }

    /**
     * @param list<string> $arquivos
     *
     * @return array<string, mixed>
     */
    private function linhaDaDemanda(DemandaExterna $d, array $arquivos): array
    {
        return [
            'codigo'       => $d->getCodigo(),
            'situacao'     => $d->getSituacao()->name,
            'escritorio'   => $d->getEscritorio(),
            'advogado'     => $d->getAdvogado(),
            'oab'          => $d->getOab() . '/' . $d->getOabUf(),
            'uf_execucao'  => $d->getUfExecucao(),
            'cidade_execucao' => $d->getCidadeExecucao(),
            'orgao'        => $d->getOrgao(),
            'processo'     => $d->getProcesso(),
            'partes'       => $d->getPartes(),
            'tipo_servico' => $d->getTipoServico(),
            'prioridade'   => $d->getPrioridade(),
            'prazo_final'  => ValoresDoBackup::dia($d->getPrazoFinal()),
            'descricao'    => $d->getDescricao(),
            'objetivo'     => $d->getObjetivo(),
            'instrucoes'   => $d->getInstrucoes(),
            'criada_em'    => ValoresDoBackup::data($d->getCriadoEm()),
            'propostas'    => $this->propostasDe($d),
            'mensagens'    => $this->mensagensDe($d),
            'documentos'   => $arquivos,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function mensagensDe(DemandaExterna $d): array
    {
        $mensagens = [];
        foreach ($d->getMensagens() as $m) {
            if ($m->getTipo() === TipoMensagem::Interna || $m->getApagadaEm() !== null) {
                continue;
            }
            $mensagens[] = ['em' => ValoresDoBackup::data($m->getEm()), 'autor' => $m->getNomeAutor(), 'texto' => $m->getTexto()];
        }

        return $mensagens;
    }

    /** @return list<array<string, mixed>> */
    private function propostasDe(DemandaExterna $d): array
    {
        $propostas = [];
        foreach ($d->getPropostas() as $p) {
            $propostas[] = [
                'versao'    => $p->getVersao(),
                'valor'     => $p->getValor(),
                'despesas'  => $p->getDespesas(),
                'prazo'     => $p->getPrazoEstimado(),
                'validade'  => ValoresDoBackup::dia($p->getValidade()),
                'pagamento' => $p->getPagamento(),
                'escopo'    => $p->getEscopo(),
                'nao_inclui' => $p->getNaoInclui(),
                'situacao'  => $p->getSituacao()->name,
                'enviada_em' => ValoresDoBackup::data($p->getEnviadaEm()),
            ];
        }


        return $propostas;
    }
}
