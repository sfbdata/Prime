<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Service\PastaTimelineAssembler;
use App\Pasta\UseCase\MontarZipDeDocumentosUseCase;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O download em .zip (D5) é registrado à mão no audit_log com a ação `zip` sobre a Pasta; a
 * timeline tem de dar nome a ele em vez do "Evento registrado" genérico.
 */
#[CoversClass(PastaTimelineAssembler::class)]
final class PastaTimelineAssemblerZipTest extends TestCase
{
    private const PASTA = 'App\\Pasta\\Entity\\Pasta';

    #[TestDox('a linha "zip" da Pasta vira "Documentos baixados em .zip" com a contagem — e os não encontrados, quando houve')]
    public function testZipComResumo(): void
    {
        $itens = $this->montar([$this->evento(['arquivos' => 3, 'nao_encontrados' => 1, 'bytes' => 10])]);

        self::assertCount(1, $itens);
        self::assertSame('Documentos baixados em .zip', $itens[0]->titulo);
        self::assertSame('3 arquivo(s) — 1 não encontrado(s) no armazenamento', $itens[0]->detalhe);
        self::assertSame('bi-file-earmark-zip', $itens[0]->icone);
        self::assertSame('text-bg-secondary', $itens[0]->badgeCss);
        self::assertSame('ana@escritorio.com', $itens[0]->autorEmail);
    }

    #[TestDox('sem não encontrados o detalhe é só a contagem; sem os campos, nada é inventado')]
    public function testDetalhe(): void
    {
        $soContagem = $this->montar([$this->evento(['arquivos' => 12, 'nao_encontrados' => 0])]);
        self::assertSame('12 arquivo(s)', $soContagem[0]->detalhe);

        $semCampos = $this->montar([$this->evento([])]);
        self::assertSame('Documentos baixados em .zip', $semCampos[0]->titulo);
        self::assertNull($semCampos[0]->detalhe);
    }

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

    /** @param array<string, mixed> $changes o `changes` PLANO que o UseCase grava */
    private function evento(array $changes): array
    {
        return [
            'id'            => 1,
            'action'        => MontarZipDeDocumentosUseCase::ACAO_AUDITORIA,
            'entity_class'  => self::PASTA,
            'entity_id'     => '42',
            'changes'       => json_encode($changes, JSON_THROW_ON_ERROR),
            'actor_user_id' => null,
            'actor_email'   => 'ana@escritorio.com',
            'created_at'    => '2026-10-07 10:00:00',
        ];
    }
}
