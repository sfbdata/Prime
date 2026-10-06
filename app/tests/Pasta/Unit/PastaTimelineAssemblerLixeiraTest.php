<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Pasta\DTO\TimelineItemDTO;
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
 * O histórico da pasta (Registro) e a lixeira (D7): a ida e a volta de um documento ou de uma
 * subpasta são `update` de `excluidoEm` no audit_log, e aparecem com nome próprio — "movido(a)
 * para a lixeira" / "restaurado(a)" —, com o ator da linha, como a lápide da Pasta já aparece.
 * Sem o braço próprio, cairiam em "Documento atualizado" (ou em "Evento registrado").
 */
#[CoversClass(PastaTimelineAssembler::class)]
final class PastaTimelineAssemblerLixeiraTest extends TestCase
{
    private const DOCUMENTO = 'App\\Pasta\\Entity\\PastaDocumento';
    private const SECAO     = 'App\\Pasta\\Entity\\PastaSecao';

    /**
     * @param array<int, array<string, mixed>> $eventos linhas do audit_log
     *
     * @return TimelineItemDTO[]
     */
    private function montar(array $eventos): array
    {
        $mensagemRepo = $this->createStub(PastaMensagemRepository::class);
        $mensagemRepo->method('findByPasta')->willReturn([]);

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

    /** @param array<string, mixed> $diff o `changes` do diff (campo => {from, to}) */
    private function evento(string $classe, array $diff, string $quando = '2026-10-07 10:00:00'): array
    {
        return [
            'id'            => 1,
            'action'        => 'update',
            'entity_class'  => $classe,
            'entity_id'     => '42',
            'changes'       => json_encode(['diff' => ['changes' => $diff], 'context' => []], JSON_THROW_ON_ERROR),
            'actor_user_id' => null,
            'actor_email'   => 'ana@escritorio.com',
            'created_at'    => $quando,
        ];
    }

    #[TestDox('documento: excluidoEm null→valor é "movido para a lixeira" (danger, com ator); valor→null é "restaurado"')]
    public function testDocumentoIdaEVolta(): void
    {
        $ida   = $this->montar([$this->evento(self::DOCUMENTO, ['excluidoEm' => ['from' => null, 'to' => '2026-10-07T10:00:00+00:00'], 'excluidoPor' => ['from' => null, 'to' => ['class' => 'User', 'id' => 7]]])]);
        $volta = $this->montar([$this->evento(self::DOCUMENTO, ['excluidoEm' => ['from' => '2026-10-07T10:00:00+00:00', 'to' => null], 'excluidoPor' => ['from' => ['class' => 'User', 'id' => 7], 'to' => null]])]);

        self::assertCount(1, $ida);
        self::assertSame('Documento movido para a lixeira', $ida[0]->titulo);
        self::assertSame('text-bg-danger', $ida[0]->badgeCss);
        self::assertSame('bi-trash3', $ida[0]->icone);
        self::assertSame('ana@escritorio.com', $ida[0]->autorEmail, 'o ator vem da linha, como em todo evento');
        self::assertNotNull($ida[0]->detalhe);

        self::assertCount(1, $volta);
        self::assertSame('Documento restaurado', $volta[0]->titulo);
        self::assertSame('text-bg-success', $volta[0]->badgeCss);
        self::assertSame('ana@escritorio.com', $volta[0]->autorEmail);
    }

    #[TestDox('subpasta: a mesma ida e volta, com o nome da Pasta')]
    public function testSecaoIdaEVolta(): void
    {
        $ida   = $this->montar([$this->evento(self::SECAO, ['excluidoEm' => ['from' => null, 'to' => '2026-10-07T10:00:00+00:00']])]);
        $volta = $this->montar([$this->evento(self::SECAO, ['excluidoEm' => ['from' => '2026-10-07T10:00:00+00:00', 'to' => null]])]);

        self::assertSame('Pasta movida para a lixeira', $ida[0]->titulo);
        self::assertSame('text-bg-danger', $ida[0]->badgeCss);
        self::assertSame('Pasta restaurada', $volta[0]->titulo);
        self::assertSame('text-bg-success', $volta[0]->badgeCss);
    }

    #[TestDox('update de documento que NÃO toca excluidoEm continua "Documento atualizado"')]
    public function testOutraAtualizacaoNaoMuda(): void
    {
        $itens = $this->montar([$this->evento(self::DOCUMENTO, ['nomeOriginal' => ['from' => 'a.pdf', 'to' => 'b.pdf']])]);

        self::assertCount(1, $itens);
        self::assertSame('Documento atualizado', $itens[0]->titulo);
    }
}
