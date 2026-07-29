<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\Auth\Entity\CadastroPendente;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\EventoHistorico;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Audit\AuditLog;
use App\Expediente\Entity\Marcador;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Entity\KanbanMarcador;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\AssuntoProcesso;
use App\Processo\Entity\DocumentoProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Profile\Entity\UserProfile;
use App\Shared\Contract\Auditavel;
use App\Termo\Entity\AceiteTermo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(Auditavel::class)]
final class AuditavelCoberturaTest extends KernelTestCase
{
    // Entidades deliberadamente fora do escopo de Auditavel.
    // Toda entidade ausente desta lista E que não implemente Auditavel quebra o teste.
    private const NAO_AUDITAVEIS = [
        // Excluída por design: seria recursão infinita auditar o próprio log
        AuditLog::class,

        // Registro imutável append-only de consentimento — ele próprio é a prova/auditoria
        AceiteTermo::class,

        // Cadastro público efêmero (pré-conta, some em 24h na confirmação/expiração) — sem valor de auditoria
        CadastroPendente::class,

        // Domínios novos — auditoria não expandida nesta fatia (decisão de produto)
        UserProfile::class,
        Cliente::class,
        ClientePF::class,
        ClientePJ::class,
        ClienteDocumento::class,
        Processo::class,
        DocumentoProcesso::class,
        ParteProcesso::class,
        MovimentacaoProcesso::class,
        AssuntoProcesso::class,
        // Associação Pasta↔Processo: acompanha a fatia Processo (não auditada)
        PastaProcesso::class,
        KanbanBoard::class,
        KanbanColuna::class,
        KanbanCard::class,
        KanbanComentario::class,
        KanbanChecklist::class,
        KanbanChecklistItem::class,
        KanbanAnexo::class,
        KanbanMarcador::class,
        Marcador::class,

        // DJEN — domínio novo (mesma decisão de Processo/Cliente): auditoria não expandida.
        // PublicacaoDjen é captada em lote pelo sync (alto volume); OabMonitorada é config do escritório.
        OabMonitorada::class,
        PublicacaoDjen::class,

        // Cobranças — EventoHistorico é o LOG DE DOMÍNIO (linha do tempo operacional, SPEC §13):
        // histórico de negócio visível ao usuário, distinto da auditoria técnica (invariável 26).
        // Auditar o log seria log de log. CasoCobranca/Obrigacao (que tocam dinheiro) SÃO Auditavel.
        EventoHistorico::class,

        // Tabela GLOBAL de referência (spec §7.1 da calculadora de atualização monetária): índice
        // oficial do BCB, sem tenant_id e sem dado de escritório. Ninguém a edita pela aplicação —
        // a única escrita é o cron `app:importar-indices-monetarios`, que já registra na tela e no
        // log toda revisão de índice já gravado. Auditar por usuário não teria usuário nenhum.
        IndiceMonetario::class,
    ];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Toda entidade ORM fora de NAO_AUDITAVEIS implementa Auditavel')]
    public function testTodasEntidadesAuditaveisImplementamInterface(): void
    {
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $class = $meta->getName();

            if (in_array($class, self::NAO_AUDITAVEIS, true)) {
                continue;
            }

            self::assertTrue(
                is_a($class, Auditavel::class, true),
                "Entidade $class deve implementar Auditavel ou constar em NAO_AUDITAVEIS."
            );
        }
    }
}
