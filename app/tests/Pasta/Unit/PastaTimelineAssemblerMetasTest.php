<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Service\PastaTimelineAssembler;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PastaTimelineAssembler::class)]
final class PastaTimelineAssemblerMetasTest extends TestCase
{
    private const ENTITY_TAREFA   = 'App\\Entity\\Tarefa\\Tarefa';
    private const ENTITY_MENSAGEM = 'App\\Entity\\Tarefa\\TarefaMensagem';

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return TimelineItemDTO[]
     */
    private function montarComLinhas(array $rows): array
    {
        $mensagemRepo = $this->createMock(PastaMensagemRepository::class);
        $mensagemRepo->method('findByPasta')->willReturn([]);

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('findForPastaTimeline')->willReturn($rows);

        $userRepo = $this->createMock(UserRepository::class);
        $ator = new User();
        $ator->setFullName('Dra. Ana');
        $ator->setEmail('ana@escritorio.com');
        $userRepo->method('find')->willReturn($ator);

        $marcadorRepo = $this->createMock(MarcadorRepository::class);

        $tarefaRepo = $this->createMock(TarefaRepository::class);
        $tarefaRepo->method('find')->willReturnCallback(static function ($id): ?Tarefa {
            if ((int) $id === 99) {
                return null; // meta inexistente: força fallback no JSON
            }
            $tarefa = new Tarefa();
            $tarefa->setTitulo('Contestação');

            return $tarefa;
        });

        $assembler = new PastaTimelineAssembler($mensagemRepo, $auditRepo, $userRepo, $marcadorRepo, $tarefaRepo);

        return $assembler->montar(new Pasta(), new Tenant(), 1, null);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function linha(string $entityClass, string $action, string $entityId, array $changes, string $createdAt): array
    {
        return [
            'id'            => random_int(1, 100000),
            'action'        => $action,
            'entity_class'  => $entityClass,
            'entity_id'     => $entityId,
            'changes'       => json_encode($changes),
            'actor_user_id' => 7,
            'actor_email'   => 'ana@escritorio.com',
            'created_at'    => $createdAt,
        ];
    }

    private function porTitulo(array $itens, string $titulo): ?TimelineItemDTO
    {
        foreach ($itens as $item) {
            if ($item->titulo === $titulo) {
                return $item;
            }
        }

        return null;
    }

    #[TestDox('Criação de meta vira evento "Meta criada" com o título entre aspas')]
    public function testMetaCriada(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_TAREFA, 'create', '5', [
                'diff' => ['after' => ['titulo' => 'Contestação']],
            ], '2026-06-17 10:00:00'),
        ]);

        $item = $this->porTitulo($itens, 'Meta criada');
        self::assertNotNull($item);
        self::assertSame(TimelineItemType::EVENTO, $item->tipo);
        self::assertNull($item->detalhe);
        self::assertSame(5, $item->metaId);
        self::assertSame('Contestação', $item->metaTitulo);
        self::assertSame('Dra. Ana', $item->autorNome);
    }

    #[TestDox('Status → concluida vira "Meta concluída" com link para a meta')]
    public function testMetaConcluida(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_TAREFA, 'update', '5', [
                'diff' => ['changes' => ['status' => ['from' => 'pendente', 'to' => 'concluida']]],
            ], '2026-06-17 11:00:00'),
        ]);

        $item = $this->porTitulo($itens, 'Meta concluída');
        self::assertNotNull($item);
        self::assertNull($item->detalhe);
        self::assertSame(5, $item->metaId);
        self::assertSame('Contestação', $item->metaTitulo);
    }

    #[TestDox('Mudança de prazo: detalhe só com o sufixo "de X para Y"')]
    public function testPrazoAlterado(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_TAREFA, 'update', '5', [
                'diff' => ['changes' => ['prazo' => ['from' => '2026-06-01T00:00:00+00:00', 'to' => '2026-07-10T00:00:00+00:00']]],
            ], '2026-06-17 13:00:00'),
        ]);

        $item = $this->porTitulo($itens, 'Prazo da meta alterado');
        self::assertNotNull($item);
        self::assertSame('de 01/06/2026 para 10/07/2026', $item->detalhe);
        self::assertSame(5, $item->metaId);
        self::assertSame('Contestação', $item->metaTitulo);
    }

    #[TestDox('Troca de responsáveis (M2M): detalhe só com os nomes')]
    public function testResponsavelAlterado(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_TAREFA, 'update', '5', [
                'diff' => ['changes' => [
                    'responsaveis[+:7]' => ['from' => null, 'to' => ['id' => '7', 'label' => 'Maria']],
                    'responsaveis[-:3]' => ['from' => ['id' => '3', 'label' => 'João'], 'to' => null],
                ]],
            ], '2026-06-17 14:00:00'),
        ]);

        $item = $this->porTitulo($itens, 'Responsável da meta alterado');
        self::assertNotNull($item);
        self::assertSame('incluído Maria · removido João', $item->detalhe);
        self::assertSame(5, $item->metaId);
        self::assertSame('Contestação', $item->metaTitulo);
    }

    #[TestDox('Observação na meta: link para a meta (de after.tarefa), sem texto da mensagem')]
    public function testObservacaoNaMeta(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_MENSAGEM, 'create', '42', [
                'diff' => ['after' => [
                    'mensagem' => 'Revisar a petição inicial',
                    'tarefa'   => ['id' => '5', 'label' => 'Contestação'],
                ]],
            ], '2026-06-17 15:00:00'),
        ]);

        $item = $this->porTitulo($itens, 'Observação na meta');
        self::assertNotNull($item);
        self::assertNull($item->detalhe);
        self::assertSame(5, $item->metaId);
        self::assertSame('Contestação', $item->metaTitulo);
    }

    #[TestDox('Edição de observação de meta é descartada da timeline da pasta')]
    public function testEdicaoObservacaoDescartada(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_MENSAGEM, 'update', '42', [
                'diff' => ['changes' => ['mensagem' => ['from' => 'a', 'to' => 'b']]],
            ], '2026-06-17 16:00:00'),
        ]);

        self::assertCount(0, $itens);
    }

    #[TestDox('Update de meta sem informação útil é descartado')]
    public function testUpdateGenericoSemInfoDescartado(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_TAREFA, 'update', '5', [
                'diff' => ['changes' => ['dataAlteracao' => ['from' => '2026-06-01T00:00:00+00:00', 'to' => '2026-06-17T00:00:00+00:00']]],
            ], '2026-06-17 17:00:00'),
        ]);

        self::assertCount(0, $itens);
    }

    #[TestDox('Meta removida resolve o título pelo JSON quando a tarefa já não existe')]
    public function testMetaRemovidaUsaFallbackJson(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha(self::ENTITY_TAREFA, 'delete', '99', [
                'diff' => ['before' => ['titulo' => 'Embargos']],
            ], '2026-06-17 18:00:00'),
        ]);

        $item = $this->porTitulo($itens, 'Meta removida');
        self::assertNotNull($item);
        self::assertNull($item->detalhe);
        self::assertNull($item->metaId, 'meta excluída não deve ter link');
        self::assertSame('Embargos', $item->metaTitulo);
    }

    #[TestDox('Evento de Pasta (não-meta) não recebe metaId/metaTitulo')]
    public function testEventoNaoMetaSemReferenciaDeMeta(): void
    {
        $itens = $this->montarComLinhas([
            $this->linha('App\\Pasta\\Entity\\Pasta', 'create', '10', [
                'diff' => ['after' => ['nup' => 'X-1']],
            ], '2026-06-17 19:00:00'),
        ]);

        $item = $this->porTitulo($itens, 'Pasta criada');
        self::assertNotNull($item);
        self::assertNull($item->metaId);
        self::assertNull($item->metaTitulo);
    }
}
