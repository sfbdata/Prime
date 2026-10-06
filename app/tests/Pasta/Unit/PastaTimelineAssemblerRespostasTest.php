<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Service\PastaTimelineAssembler;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Responder" no Registro (desenho 1.2.3): a resposta vai para debaixo da raiz, em
 * ordem de criação, e a ordem das RAÍZES continua a de antes (mais recente primeiro).
 */
#[CoversClass(PastaTimelineAssembler::class)]
final class PastaTimelineAssemblerRespostasTest extends TestCase
{
    private User $ana;
    private User $bruno;

    protected function setUp(): void
    {
        $this->ana   = (new User())->setEmail('ana@escritorio.com')->setFullName('Ana Souza');
        $this->bruno = (new User())->setEmail('bruno@escritorio.com')->setFullName('Bruno Lima');
    }

    /**
     * @param PastaMensagem[] $mensagens na ordem do repositório (mais recente primeiro)
     * @param array<int, array<string, mixed>> $eventos linhas do audit_log
     * @return TimelineItemDTO[]
     */
    private function montar(array $mensagens, array $eventos = []): array
    {
        $mensagemRepo = $this->createStub(PastaMensagemRepository::class);
        $mensagemRepo->method('findByPasta')->willReturn($mensagens);

        $auditRepo = $this->createStub(AuditLogRepository::class);
        $auditRepo->method('findForPastaTimeline')->willReturn($eventos);

        $assembler = new PastaTimelineAssembler(
            $mensagemRepo,
            $auditRepo,
            $this->createStub(UserRepository::class),
            $this->createStub(MarcadorRepository::class),
            $this->createStub(TarefaRepository::class),
        );

        return $assembler->montar(new Pasta(), new Tenant(), 1, null);
    }

    private function msg(int $id, User $autor, string $quando, ?PastaMensagem $respostaA = null): PastaMensagem
    {
        $m = (new PastaMensagem())
            ->setAutor($autor)
            ->setConteudo('Mensagem ' . $id)
            ->setRespostaA($respostaA);
        (new \ReflectionProperty(PastaMensagem::class, 'id'))->setValue($m, $id);
        (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($m, new \DateTimeImmutable($quando));

        return $m;
    }

    /** @param TimelineItemDTO[] $itens @return array<int|null> */
    private function ids(array $itens): array
    {
        return array_map(static fn (TimelineItemDTO $i) => $i->mensagemId, $itens);
    }

    #[TestDox('As respostas saem do primeiro nível e vão para debaixo da raiz, da mais antiga à mais nova')]
    public function testAgrupaRespostasSobARaiz(): void
    {
        $a  = $this->msg(1, $this->ana, '2026-10-05 10:00');
        $b  = $this->msg(2, $this->bruno, '2026-10-05 10:20');
        $r1 = $this->msg(3, $this->bruno, '2026-10-05 10:10', $a);
        $r2 = $this->msg(4, $this->ana, '2026-10-05 10:30', $a);

        // O repositório entrega da mais recente para a mais antiga.
        $itens = $this->montar([$r2, $b, $r1, $a]);

        self::assertSame([2, 1], $this->ids($itens), 'só as raízes no primeiro nível, na ordem de antes');
        self::assertSame([], $itens[0]->respostas);
        self::assertSame([3, 4], $this->ids($itens[1]->respostas), 'respostas em ordem de criação');

        $resposta = $itens[1]->respostas[0];
        self::assertSame(TimelineItemType::MENSAGEM, $resposta->tipo);
        self::assertTrue($resposta->ehResposta);
        self::assertSame(1, $resposta->respostaAId);
        self::assertSame($this->ana->getFullName(), $resposta->respostaANome);
        self::assertFalse($itens[1]->ehResposta, 'a raiz não é resposta');
    }

    #[TestDox('Uma resposta nova não move a raiz: a ordem das raízes é a data DELAS')]
    public function testRespostaNaoReordenaAsRaizes(): void
    {
        $antiga = $this->msg(1, $this->ana, '2026-10-01 09:00');
        $nova   = $this->msg(2, $this->bruno, '2026-10-04 09:00');
        $tardia = $this->msg(3, $this->bruno, '2026-10-05 18:00', $antiga);

        $itens = $this->montar([$tardia, $nova, $antiga]);

        self::assertSame([2, 1], $this->ids($itens));
        self::assertSame([3], $this->ids($itens[1]->respostas));
    }

    #[TestDox('Raízes e eventos do histórico seguem intercalados pela data, como antes')]
    public function testOrdemComEventosPreservada(): void
    {
        $a = $this->msg(1, $this->ana, '2026-10-05 10:00');
        $b = $this->msg(2, $this->ana, '2026-10-05 12:00');
        $r = $this->msg(3, $this->bruno, '2026-10-05 13:00', $a);

        $itens = $this->montar([$r, $b, $a], [[
            'id'            => 10,
            'action'        => 'create',
            'entity_class'  => 'App\\Pasta\\Entity\\Pasta',
            'entity_id'     => '1',
            'changes'       => null,
            'actor_user_id' => null,
            'actor_email'   => null,
            'created_at'    => '2026-10-05 11:00:00',
        ]]);

        self::assertSame(
            [TimelineItemType::MENSAGEM, TimelineItemType::EVENTO, TimelineItemType::MENSAGEM],
            array_map(static fn (TimelineItemDTO $i) => $i->tipo, $itens),
        );
        self::assertSame([2, null, 1], $this->ids($itens));
        self::assertSame([3], $this->ids($itens[2]->respostas));
    }

    #[TestDox('Resposta órfã (a original foi excluída) fica no primeiro nível, marcada e sem nome')]
    public function testRespostaOrfaFicaNoPrimeiroNivel(): void
    {
        $orfa = $this->msg(5, $this->bruno, '2026-10-05 10:00');
        // Nasceu resposta; o SET NULL do banco soltou o vínculo quando a original foi excluída.
        $orfa->setRespostaA($this->msg(99, $this->ana, '2026-10-05 09:00'));
        (new \ReflectionProperty(PastaMensagem::class, 'respostaA'))->setValue($orfa, null);

        $itens = $this->montar([$orfa]);

        self::assertSame([5], $this->ids($itens));
        self::assertTrue($itens[0]->ehResposta);
        self::assertNull($itens[0]->respostaAId);
        self::assertNull($itens[0]->respostaANome, 'sem nome a tela diz "Resposta a uma mensagem excluída"');
    }

    #[TestDox('Resposta cuja raiz ficou fora do limite da consulta não some: fica no primeiro nível com o nome da raiz')]
    public function testRespostaComRaizForaDoLimite(): void
    {
        $raizFora = $this->msg(1, $this->ana, '2026-01-01 09:00');
        $resposta = $this->msg(2, $this->bruno, '2026-10-05 10:00', $raizFora);

        $itens = $this->montar([$resposta]);

        self::assertSame([2], $this->ids($itens));
        self::assertTrue($itens[0]->ehResposta);
        self::assertSame(1, $itens[0]->respostaAId);
        self::assertSame($this->ana->getFullName(), $itens[0]->respostaANome);
    }
}
