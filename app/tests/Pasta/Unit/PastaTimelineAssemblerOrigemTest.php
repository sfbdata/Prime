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

/**
 * L18: o assembler diz de que FAMÍLIA é cada evento do audit_log (`origem`), para a Timeline
 * inteligente agrupar sem ler o título; e carrega autores e metas de uma vez (sem N+1).
 */
#[CoversClass(PastaTimelineAssembler::class)]
final class PastaTimelineAssemblerOrigemTest extends TestCase
{
    /** @return array<string, mixed> */
    private function linha(string $classe, string $acao, string $entityId, array $changes, int $ator = 7): array
    {
        return [
            'id'            => random_int(1, 100000),
            'action'        => $acao,
            'entity_class'  => $classe,
            'entity_id'     => $entityId,
            'changes'       => json_encode($changes),
            'actor_user_id' => $ator,
            'actor_email'   => 'ana@escritorio.com',
            'created_at'    => '2026-10-01 10:00:00',
        ];
    }

    private function comId(object $entidade, int $id): object
    {
        $prop = new \ReflectionProperty($entidade, 'id');
        $prop->setValue($entidade, $id);

        return $entidade;
    }

    #[TestDox('Cada classe auditada vira a sua origem; mensagem não tem origem')]
    public function testOrigemPorClasse(): void
    {
        $mensagens = $this->createStub(PastaMensagemRepository::class);
        $mensagens->method('findByPasta')->willReturn([]);

        $audit = $this->createStub(AuditLogRepository::class);
        $audit->method('findForPastaTimeline')->willReturn([
            $this->linha('App\\Pasta\\Entity\\Pasta', 'create', '1', []),
            $this->linha('App\\Pasta\\Entity\\PastaDocumento', 'create', '2', ['diff' => ['after' => ['nomeOriginal' => 'a.pdf']]]),
            $this->linha('App\\Entity\\Tarefa\\Tarefa', 'create', '3', []),
            $this->linha('App\\Processo\\Entity\\Processo', 'create', '4', []),
        ]);

        $assembler = new PastaTimelineAssembler(
            $mensagens,
            $audit,
            $this->createStub(UserRepository::class),
            $this->createStub(MarcadorRepository::class),
            $this->createStub(TarefaRepository::class),
        );

        $itens = $assembler->montar(new Pasta(), new Tenant(), 1, null);

        $porTitulo = [];
        foreach ($itens as $item) {
            self::assertSame(TimelineItemType::EVENTO, $item->tipo);
            $porTitulo[$item->titulo] = $item->origem;
        }

        self::assertSame([
            'Pasta criada'       => 'pasta',
            'Documento enviado'  => 'documento',
            'Meta criada'        => 'meta',
            'Processo vinculado' => 'processo',
        ], $porTitulo);
    }

    #[TestDox('Autores e metas vêm numa consulta só (findBy); find() não é chamado para o que foi achado')]
    public function testPreCargaSemNMaisUm(): void
    {
        $mensagens = $this->createStub(PastaMensagemRepository::class);
        $mensagens->method('findByPasta')->willReturn([]);

        $audit = $this->createStub(AuditLogRepository::class);
        $audit->method('findForPastaTimeline')->willReturn([
            $this->linha('App\\Entity\\Tarefa\\Tarefa', 'create', '30', [], 7),
            $this->linha('App\\Entity\\Tarefa\\Tarefa', 'update', '31', ['diff' => ['changes' => ['status' => ['from' => 'pendente', 'to' => 'concluida']]]], 8),
            $this->linha('App\\Entity\\Tarefa\\Tarefa', 'update', '30', ['diff' => ['changes' => ['prazo' => ['from' => null, 'to' => '2026-10-10']]]], 7),
        ]);

        $ana = new User();
        $ana->setFullName('Ana Souza');
        $bruno = new User();
        $bruno->setFullName('Bruno Lima');

        $usuarios = $this->createMock(UserRepository::class);
        $usuarios->expects(self::once())->method('findBy')
            ->with(self::callback(static fn (array $c) => isset($c['id']) && array_values($c['id']) === [7, 8]))
            ->willReturn([$this->comId($ana, 7), $this->comId($bruno, 8)]);
        $usuarios->expects(self::never())->method('find');

        $contestacao = new Tarefa();
        $contestacao->setTitulo('Contestação');
        $recurso = new Tarefa();
        $recurso->setTitulo('Recurso');

        $tarefas = $this->createMock(TarefaRepository::class);
        $tarefas->expects(self::once())->method('findBy')
            ->willReturn([$this->comId($contestacao, 30), $this->comId($recurso, 31)]);
        $tarefas->expects(self::never())->method('find');

        $assembler = new PastaTimelineAssembler($mensagens, $audit, $usuarios, $this->createStub(MarcadorRepository::class), $tarefas);
        $itens     = $assembler->montar(new Pasta(), new Tenant(), 1, null);

        self::assertCount(3, $itens);
        $autores = array_map(static fn (TimelineItemDTO $i) => $i->autorNome, $itens);
        sort($autores);
        self::assertSame(['Ana Souza', 'Ana Souza', 'Bruno Lima'], $autores);
        $metas = array_map(static fn (TimelineItemDTO $i) => $i->metaTitulo, $itens);
        sort($metas);
        self::assertSame(['Contestação', 'Contestação', 'Recurso'], $metas);
    }
}
