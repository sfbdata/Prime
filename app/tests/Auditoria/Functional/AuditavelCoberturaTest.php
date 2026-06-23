<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
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
        KanbanBoard::class,
        KanbanColuna::class,
        KanbanCard::class,
        KanbanComentario::class,
        KanbanChecklist::class,
        KanbanChecklistItem::class,
        KanbanAnexo::class,
        KanbanMarcador::class,
        Marcador::class,
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
