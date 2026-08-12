<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Entity\KanbanMarcador;
use App\Shared\Contract\TenantAware;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fatia 2 de docs/specs/isolamento-multitenant-cobertura.md — Kanban.
 *
 * Antes desta fatia o dominio inteiro estava fora do TenantFilter: `KanbanBoard` tinha coluna
 * `tenant_id` mas nao a etiqueta `TenantAware`, e as 7 filhas nao tinham nem coluna. Consulta de
 * Kanban devolvia dado de todos os escritorios sem erro nenhum. Testes de Kanban existiam e
 * passavam — nenhum olhava para o perimetro.
 *
 * Aqui se prova o que a spec (§7) cobra: negativa cross-tenant por entidade, e a invariante
 * pai↔filho que a denormalizacao criou.
 */
final class KanbanIsolamentoTenantTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return iterable<string, array{class-string}> */
    public static function entidadesDoKanban(): iterable
    {
        yield 'board'          => [KanbanBoard::class];
        yield 'coluna'         => [KanbanColuna::class];
        yield 'card'           => [KanbanCard::class];
        yield 'marcador'       => [KanbanMarcador::class];
        yield 'checklist'      => [KanbanChecklist::class];
        yield 'item'           => [KanbanChecklistItem::class];
        yield 'comentario'     => [KanbanComentario::class];
        yield 'anexo'          => [KanbanAnexo::class];
    }

    #[DataProvider('entidadesDoKanban')]
    #[TestDox('O escritorio A nao enxerga o Kanban do escritorio B')]
    public function testCadaEntidadeIsolaEntreEscritorios(string $classe): void
    {
        $muralA = $this->criarMuralCompleto();
        $muralB = $this->criarMuralCompleto();

        $repo = $this->em->getRepository($classe);

        // Sem filtro (contexto CLI/import): enxerga os dois — comportamento intencional.
        self::assertCount(2, $repo->findBy([]), 'Cenario mal montado: era para haver 1 de cada.');

        $this->ligarFiltro((int) $muralA['tenant']->getId());
        $doA = $repo->findBy([]);

        self::assertCount(1, $doA, sprintf('%s vazou entre escritorios.', $classe));
        self::assertSame(
            $muralA['tenant']->getId(),
            $doA[0]->getTenant()?->getId(),
            sprintf('%s devolveu registro do escritorio errado.', $classe)
        );

        // E a negativa e negativa mesmo: buscar o id do vizinho por PK nao devolve nada.
        $idDoVizinho = (int) $muralB[$classe]->getId();
        $this->em->clear();
        self::assertNull(
            $this->em->find($classe, $idDoVizinho),
            sprintf('%s do escritorio B foi alcancada por id a partir do escritorio A.', $classe)
        );
    }

    #[DataProvider('entidadesDoKanban')]
    #[TestDox('Toda entidade do Kanban declara isolamento de escritorio')]
    public function testTodaEntidadeEhTenantAware(string $classe): void
    {
        self::assertTrue(
            is_a($classe, TenantAware::class, true),
            sprintf('%s ficaria fora do TenantFilter — e sem erro nenhum.', $classe)
        );
    }

    #[TestDox('O escritorio da filha e derivado do pai, nunca informado por fora')]
    public function testFilhaHerdaEscritorioDoPai(): void
    {
        $mural = $this->criarMuralCompleto();
        $tenantId = $mural['tenant']->getId();

        foreach (self::entidadesDoKanban() as [$classe]) {
            self::assertSame(
                $tenantId,
                $mural[$classe]->getTenant()?->getId(),
                sprintf('%s nasceu com escritorio diferente do pai.', $classe)
            );
        }
    }

    #[TestDox('Card com coluna de outro mural e recusado na construcao')]
    public function testCardRecusaColunaDeOutroMural(): void
    {
        $muralA = $this->criarMuralCompleto();
        $muralB = $this->criarMuralCompleto();

        // O unico filho com dois pais e, por isso, o unico que pode receber pais discordantes.
        // Sem a guarda, o card herdaria o tenant do board de B carregando uma coluna de A:
        // invisivel para o dono da coluna, visivel para o vizinho.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pertence a outro mural');

        new KanbanCard(
            'Card intruso',
            $muralA[KanbanColuna::class],
            $muralB[KanbanBoard::class],
            $muralB['user']
        );
    }

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    /**
     * Um mural completo — board mais as 7 filhas — num escritorio novo.
     *
     * @return array<string, mixed> indexado pelo FQCN de cada entidade, mais 'tenant' e 'user'
     */
    private function criarMuralCompleto(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Kanban ' . uniqid());
        $this->em->persist($tenant);

        $user = new User();
        $user->setEmail('kanban_' . uniqid() . '@test.com');
        $user->setFullName('Usuario ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        $board = new KanbanBoard('Mural ' . uniqid(), $tenant, $user);
        $this->em->persist($board);

        $coluna = new KanbanColuna('A Fazer', KanbanColuna::TIPO_A_FAZER, 0, $board);
        $this->em->persist($coluna);

        $card = new KanbanCard('Card ' . uniqid(), $coluna, $board, $user);
        $this->em->persist($card);

        $marcador = new KanbanMarcador('Marcador', '#3b82f6', $board);
        $this->em->persist($marcador);

        $checklist = new KanbanChecklist('Checklist', $card);
        $this->em->persist($checklist);

        $item = new KanbanChecklistItem('Item', $checklist);
        $this->em->persist($item);

        $comentario = new KanbanComentario('Comentario', $card, $user);
        $this->em->persist($comentario);

        $anexo = new KanbanAnexo('anexo.txt', '/tmp/nao-lido-neste-teste', 26, 'text/plain', $card, $user);
        $this->em->persist($anexo);

        $this->em->flush();

        return [
            'tenant'                    => $tenant,
            'user'                      => $user,
            KanbanBoard::class          => $board,
            KanbanColuna::class         => $coluna,
            KanbanCard::class           => $card,
            KanbanMarcador::class       => $marcador,
            KanbanChecklist::class      => $checklist,
            KanbanChecklistItem::class  => $item,
            KanbanComentario::class     => $comentario,
            KanbanAnexo::class          => $anexo,
        ];
    }
}
