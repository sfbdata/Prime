<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Arquivar, desarquivar e excluir rascunho (núcleo do desenho: arquivar, desarquivar, excluirRascunho). */
#[CoversClass(Assembleia::class)]
final class CicloDaAssembleiaTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-11-16 19:00:00');
    }

    #[TestDox('Arquivar a publicada sem pauta aberta: guarda quando, motivo e quem; desarquivar limpa')]
    public function testArquivarEDesarquivar(): void
    {
        [$assembleia] = $this->publicada();

        $assembleia->arquivar('  Pauta retirada da ordem do dia ', 'Comissão', $this->agora);

        self::assertTrue($assembleia->arquivada());
        self::assertSame('Pauta retirada da ordem do dia', $assembleia->getArquivadaMotivo());
        self::assertSame('Comissão', $assembleia->getArquivadaPorNome());
        self::assertEquals($this->agora, $assembleia->getArquivadaEm());

        $assembleia->desarquivar('Comissão');

        self::assertFalse($assembleia->arquivada());
        self::assertNull($assembleia->getArquivadaEm());
        self::assertNull($assembleia->getArquivadaMotivo());
        self::assertNull($assembleia->getArquivadaPorNome());
    }

    #[TestDox('Arquivar com pauta aberta (ou suspensa): "Encerre a votação antes de arquivar."')]
    public function testArquivarComPautaAberta(): void
    {
        [$assembleia, $pauta] = $this->publicada();
        $pauta->abrir('Mesa', 1, null, $this->agora);

        $this->assertRecusa('Encerre a votação antes de arquivar.', fn () => $assembleia->arquivar('Motivo válido', 'Mesa', $this->agora));

        $pauta->suspender('Queda de energia', 'Mesa', $this->agora);
        $this->assertRecusa('Encerre a votação antes de arquivar.', fn () => $assembleia->arquivar('Motivo válido', 'Mesa', $this->agora));
        self::assertFalse($assembleia->arquivada());
    }

    #[TestDox('Arquivar com pauta encerrada (apurada) ou cancelada vale; o resultado e as cédulas ficam')]
    public function testArquivarComEncerradaECancelada(): void
    {
        [$assembleia, $pauta] = $this->publicada();
        $pauta->abrir('Mesa', 1, null, $this->agora);
        $pauta->encerrar('Mesa', '', $this->agora);
        $outra = new PautaDaAssembleia($assembleia, 2, 'Orçamento');
        $outra->cancelar('Convocação irregular', 'Mesa', $this->agora);

        $assembleia->arquivar('Assembleia concluída', 'Mesa', $this->agora);

        self::assertTrue($assembleia->arquivada());
        self::assertTrue($outra->retirada(), 'a pauta que nunca abriu e foi cancelada virou retirada');
    }

    #[TestDox('Rascunho não se arquiva: "Rascunho não se arquiva: pode ser excluído."')]
    public function testArquivarRascunho(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G');

        $this->assertRecusa('Rascunho não se arquiva: pode ser excluído.', fn () => $assembleia->arquivar('Motivo válido', 'Mesa', $this->agora));
    }

    #[TestDox('Motivo curto, arquivar duas vezes e desarquivar o que não está arquivado são recusados')]
    public function testRecusas(): void
    {
        [$assembleia] = $this->publicada();

        $this->assertRecusa('Informe o motivo do arquivamento.', fn () => $assembleia->arquivar('abcd', 'Mesa', $this->agora));
        $this->assertRecusa('Votação não está arquivada.', fn () => $assembleia->desarquivar('Mesa'));

        $assembleia->arquivar('Motivo válido', 'Mesa', $this->agora);
        $this->assertRecusa('Votação já arquivada.', fn () => $assembleia->arquivar('Motivo válido', 'Mesa', $this->agora));
    }

    #[TestDox('Arquivada não abre pauta: "Votação arquivada: desarquive antes de abrir."; desarquivada abre')]
    public function testArquivadaNaoAbre(): void
    {
        [$assembleia, $pauta] = $this->publicada();
        $assembleia->arquivar('Motivo válido', 'Mesa', $this->agora);

        $this->assertRecusa('Votação arquivada: desarquive antes de abrir.', fn () => $pauta->abrir('Mesa', 1, null, $this->agora));

        $assembleia->desarquivar('Mesa');
        $pauta->abrir('Mesa', 1, null, $this->agora);
        self::assertTrue($pauta->aceitaVotoEm($this->agora));
    }

    #[TestDox('Excluir: só rascunho nunca publicado; a publicada diz que pode ser arquivada')]
    public function testExclusao(): void
    {
        $rascunho = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G');
        $rascunho->exigirExclusaoPossivel();
        $this->addToAssertionCount(1);

        [$publicada] = $this->publicada();
        $this->assertRecusa(
            'Só rascunho nunca publicado pode ser excluído. Votação publicada pode ser arquivada.',
            fn () => $publicada->exigirExclusaoPossivel(),
        );
    }

    private function assertRecusa(string $mensagem, callable $acao): void
    {
        try {
            $acao();
            self::fail('Devia recusar: ' . $mensagem);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
        }
    }

    /** @return array{Assembleia, PautaDaAssembleia} */
    private function publicada(): array
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $assembleia->publicar($this->agora->modify('-1 hour'));

        return [$assembleia, $pauta];
    }
}
