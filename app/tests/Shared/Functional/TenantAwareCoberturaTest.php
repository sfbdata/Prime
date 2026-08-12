<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Entity\RedefinicaoSenha;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Entity\ServiceDesk\ChamadoInteracao;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Entity\KanbanMarcador;
use App\Pasta\Entity\PastaProcesso;
use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Profile\Entity\UserProfile;
use App\Shared\Contract\TenantAware;
use App\Termo\Entity\AceiteTermo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rede de cobertura do isolamento multi-tenant (docs/specs/isolamento-multitenant-cobertura.md).
 *
 * O TenantFilter só age em entidades que implementam TenantAware; quem fica de fora não é
 * filtrado e NÃO DÁ ERRO — a consulta devolve dado de todos os escritórios com cara de sucesso.
 * O Kanban inteiro ficou assim por meses porque nada cobrava a cobertura: os testes de
 * isolamento existentes provam que a ferramenta testada isola, nunca que o perímetro fecha.
 *
 * Este teste cobra. Toda entidade ORM precisa estar num de três estados, e o terceiro é o
 * ponto: uma entidade nova que ninguém classificou quebra a suíte na hora.
 */
#[CoversClass(TenantAware::class)]
final class TenantAwareCoberturaTest extends KernelTestCase
{
    /**
     * Entidades que NÃO devem ser filtradas por tenant — decisão permanente, com o motivo ao lado.
     * Entrar aqui exige justificar; não é lugar de estacionar dívida (para isso existe PENDENTE).
     */
    private const FORA_DO_ESCOPO = [
        // O próprio eixo do isolamento.
        Tenant::class,

        // Identidade: existe antes e acima do vínculo com escritório.
        User::class,

        // O vínculo user↔escritório. Filtrar por tenant aqui seria circular e QUEBRARIA a troca
        // de escritório: TenantContext::setCurrentTenant precisa enxergar o vínculo do tenant
        // para o qual a pessoa está indo, que por definição não é o tenant ativo.
        UserTenant::class,

        // Pré-conta: existe antes de haver User e Tenant (nascem juntos na confirmação).
        // Chave externa é o token, nunca o id.
        CadastroPendente::class,

        // Pré-autenticação e efêmero (1h). Sem tenant por natureza.
        RedefinicaoSenha::class,

        // Catálogo global de permissões. O que é por escritório é TenantRolePermission.
        Permission::class,

        // Tabela global de referência (índices oficiais do BCB). Sem dado de escritório.
        IndiceMonetario::class,

        // Medido em 12/08/2026: 13 linhas para 13 usuários distintos — o perfil é do USUÁRIO,
        // não do escritório. Quem atua em dois escritórios tem um perfil só.
        UserProfile::class,
    ];

    /**
     * Furos conhecidos, cada um endereçado a uma fatia da spec. Esta lista SÓ ENCOLHE.
     *
     * Ela existe para a suíte permanecer verde enquanto a correção anda domínio por domínio —
     * uma suíte vermelha por semanas é ignorada, e aí a rede não protege nada. O que importa
     * está preservado: entidade nova que não esteja em NENHUMA das duas listas quebra o teste.
     */
    private const PENDENTE_DE_CORRECAO = [
        // Fatia 2 — Kanban. `kanban_board` já tem tenant_id NOT NULL (só falta a etiqueta);
        // as 7 filhas precisam da coluna, com backfill derivado do board.
        KanbanBoard::class,
        KanbanColuna::class,
        KanbanCard::class,
        KanbanComentario::class,
        KanbanChecklist::class,
        KanbanChecklistItem::class,
        KanbanAnexo::class,
        KanbanMarcador::class,

        // Fatia 3 — ServiceDesk. Sem coluna; backfill pelo chamado dono.
        ChamadoInteracao::class,
        ChamadoAnexo::class,

        // Fatia 4 — Tenant. Já têm tenant_id NOT NULL; ligar o filtro muda toda query
        // existente, inclusive as telas de administração (ver §5.2 da spec).
        Sede::class,
        Cargo::class,
        Lotacao::class,

        // Fatia 5 — Permission (risco MÉDIO).
        TenantRole::class,
        TenantRolePermission::class,

        // Fatia 6 — Ponto eletrônico (risco ALTO, re-revisão obrigatória).
        BlocoJornada::class,
        JornadaColaborador::class,
        BlocoJornadaColaborador::class,

        // Fatia 7 — depende de decisão do dono sobre os nulos (§5.1 da spec):
        // audit_log tem 19.390 de 29.791 linhas com tenant_id nulo, aceite_termo 7 de 10.
        // Pôr a etiqueta sem resolver isso sumiria com 2/3 da trilha de auditoria.
        AuditLog::class,
        AceiteTermo::class,

        // Fatia 8 — Pasta e Auth.
        // PastaProcesso: associação Pasta↔Processo, hoje isolada só pela Pasta dona.
        // Invitation: tem tenant_id nullable; convite vazado expõe e-mail de outro escritório.
        PastaProcesso::class,
        Invitation::class,
    ];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Toda entidade ORM implementa TenantAware ou está classificada explicitamente')]
    public function testTodaEntidadeEstaClassificada(): void
    {
        $naoClassificadas = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $class = $meta->getName();

            if (is_a($class, TenantAware::class, true)) {
                continue;
            }

            if (in_array($class, self::FORA_DO_ESCOPO, true)
                || in_array($class, self::PENDENTE_DE_CORRECAO, true)) {
                continue;
            }

            $naoClassificadas[] = $class;
        }

        self::assertSame([], $naoClassificadas, sprintf(
            "Entidade sem isolamento de tenant e sem classificação: %s.\n"
            . 'Ou implemente TenantAware (com ManyToOne(Tenant) JoinColumn nullable: false), '
            . 'ou declare em FORA_DO_ESCOPO com o motivo, ou em PENDENTE_DE_CORRECAO apontando a fatia.',
            implode(', ', $naoClassificadas)
        ));
    }

    #[TestDox('A lista de pendências só encolhe: entidade já corrigida sai dela')]
    public function testPendenciaCorrigidaSaiDaLista(): void
    {
        $jaCorrigidas = array_values(array_filter(
            self::PENDENTE_DE_CORRECAO,
            static fn (string $class): bool => is_a($class, TenantAware::class, true)
        ));

        self::assertSame([], $jaCorrigidas, sprintf(
            'Estas já implementam TenantAware e devem sair de PENDENTE_DE_CORRECAO: %s.',
            implode(', ', $jaCorrigidas)
        ));
    }

    #[TestDox('FORA_DO_ESCOPO não acumula entrada morta')]
    public function testForaDoEscopoNaoTemEntradaMorta(): void
    {
        $mapeadas = array_map(
            static fn ($meta): string => $meta->getName(),
            $this->em->getMetadataFactory()->getAllMetadata()
        );

        $mortas = array_values(array_filter(
            [...self::FORA_DO_ESCOPO, ...self::PENDENTE_DE_CORRECAO],
            static fn (string $class): bool => !in_array($class, $mapeadas, true)
                || is_a($class, TenantAware::class, true)
        ));

        self::assertSame([], $mortas, sprintf(
            'Entradas obsoletas nas listas (entidade removida do mapeamento ou já corrigida): %s.',
            implode(', ', $mortas)
        ));
    }

    #[TestDox('Uma entidade não pode estar nas duas listas ao mesmo tempo')]
    public function testListasNaoSeSobrepoem(): void
    {
        $intersecao = array_values(array_intersect(self::FORA_DO_ESCOPO, self::PENDENTE_DE_CORRECAO));

        self::assertSame([], $intersecao, sprintf(
            'Classificação ambígua — está em FORA_DO_ESCOPO e em PENDENTE_DE_CORRECAO: %s.',
            implode(', ', $intersecao)
        ));
    }
}
