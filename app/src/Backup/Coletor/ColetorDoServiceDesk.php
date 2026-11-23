<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\ServiceDesk\Armazenamento\ChavesDeServiceDesk;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Entity\HistoricoDoChamado;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Service Desk no backup: `dados/service_desk.json` e `service_desk/<chamado>/` com os anexos.
 *
 * Só roda para quem tem o módulo `servicedesk` (`ServiceDeskController.php:181`). Duas fontes, cada uma com a regra da tela:
 * - Chamados do escritório ("Meus chamados", `ChamadoDeSuporteRepository::listarDoEscritorio`): a tela lista TODOS os
 *   do escritório a quem tem o módulo, com o histórico. Entram todos do escritório (`tenant_id` na mão).
 * - Chamados antigos (`Chamado`): só os que a PRÓPRIA pessoa abriu (a tela de quem não é admin só mostra o solicitante),
 *   com as respostas que não são notas internas da equipe, e os anexos.
 * Nunca entram notas internas, nem chamado de outro escritório.
 */
#[AsTaggedItem(priority: 1)]
final class ColetorDoServiceDesk implements ColetorDoBackup
{
    use PercorreEmPaginasTrait;

    public const MODULO = 'servicedesk';

    public const CHAMADOS_POR_PAGINA = 50;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly EntityManagerInterface $em,
        private readonly int $porPagina = self::CHAMADOS_POR_PAGINA,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $tenantId  = (int) $tenant->getId();
        $usuarioId = (int) $usuario->getId();
        $dados     = [
            'chamados'        => $this->chamadosDoEscritorio($tenantId, $zip),
            'chamados_antigos' => $this->chamadosAntigos($tenantId, $usuarioId, $zip),
        ];

        $dir = $zip->reservarDiretorio('', 'dados');
        $zip->adicionarJson($dir . '/service_desk.json', $dados);
    }

    /** @return list<array<string, mixed>> */
    private function chamadosDoEscritorio(int $tenantId, ZipDoBackup $zip): array
    {
        $linhas = [];
        foreach ($this->paginas(ChamadoDeSuporte::class, $tenantId, $this->porPagina) as $pagina) {
            foreach ($pagina as $c) {
                \assert($c instanceof ChamadoDeSuporte);
                /** @var list<HistoricoDoChamado> $historico */
                $historico = $this->em->createQueryBuilder()
                    ->select('h')->from(HistoricoDoChamado::class, 'h')
                    ->andWhere('h.chamado = :chamado')->andWhere('IDENTITY(h.tenant) = :tenant')
                    ->setParameter('chamado', $c)->setParameter('tenant', $tenantId)
                    ->orderBy('h.id')->getQuery()->getResult();
                $linhas[] = [
                    'numero'         => $c->getNumero(),
                    'assunto'        => $c->getAssunto(),
                    'descricao'      => $c->getDescricao(),
                    'tipo'           => $c->getTipo()->name,
                    'prioridade'     => $c->getPrioridade()->name,
                    'situacao'       => $c->getStatus()->rotulo(),
                    'data_agendada'  => ValoresDoBackup::dia($c->getDataAgendada()),
                    'hora_agendada'  => $c->getHoraAgendada(),
                    'valor_estimado_centavos' => $c->getValorEstimadoCentavos(),
                    'valor_debitado_centavos' => $c->getValorDebitadoCentavos(),
                    'aberto_em'      => ValoresDoBackup::data($c->getCriadoEm()),
                    'solicitante'    => $c->getSolicitante()?->getFullName(),
                    'historico'      => array_map(static fn (HistoricoDoChamado $h): array => [
                        'em'    => ValoresDoBackup::data($h->getCriadoEm()),
                        'texto' => $h->getTexto(),
                    ], $historico),
                ];
                $zip->contar('Chamados do Service Desk');
            }
        }

        return $linhas;
    }

    /** @return list<array<string, mixed>> */
    private function chamadosAntigos(int $tenantId, int $usuarioId, ZipDoBackup $zip): array
    {
        $ajuste = static fn ($qb) => $qb->andWhere('IDENTITY(e.solicitante) = :solicitante')->setParameter('solicitante', $usuarioId);
        $linhas = [];
        $raiz   = null;
        foreach ($this->paginas(Chamado::class, $tenantId, $this->porPagina, $ajuste) as $pagina) {
            foreach ($pagina as $c) {
                \assert($c instanceof Chamado);
                $respostas = $this->respostasDe($c);
                $anexos = [];
                $dir    = null;
                foreach ($c->getAnexos() as $a) {
                    $raiz ??= $zip->reservarDiretorio('', 'service_desk');
                    $dir  ??= $zip->reservarDiretorio($raiz, 'chamado ' . $c->getId());
                    $zip->adicionarArquivo($dir, $a->getNomeOriginal(), static fn () => ChavesDeServiceDesk::anexoDeChamado($a), $a->getTamanho());
                    $anexos[] = $a->getNomeOriginal();
                    $zip->contar('Anexos de chamado');
                }
                $linhas[] = [
                    'id'         => $c->getId(),
                    'titulo'     => $c->getTitulo(),
                    'descricao'  => $c->getDescricao(),
                    'categoria'  => $c->getCategoria(),
                    'prioridade' => $c->getPrioridade(),
                    'situacao'   => $c->getStatus(),
                    'aberto_em'  => ValoresDoBackup::data($c->getCriadoEm()),
                    'respostas'  => $respostas,
                    'anexos'     => $anexos,
                ];
                $zip->contar('Chamados antigos do Service Desk');
            }
        }

        return $linhas;
    }

    /** @return list<array<string, mixed>> as respostas que não são nota interna da equipe */
    private function respostasDe(Chamado $c): array
    {
        $respostas = [];
        foreach ($c->getInteracoes() as $i) {
            if ($i->isInterno()) {
                continue;
            }
            $respostas[] = [
                'em'       => ValoresDoBackup::data($i->getCriadoEm()),
                'autor'    => $i->getAutor()?->getFullName(),
                'mensagem' => $i->getMensagem(),
            ];
        }

        return $respostas;
    }
}
