<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * P40 do desenho do Ponto: os nomes das situações fora da tela nova (ficha do gestor e sininho) saem de um ponto só,
 * pronto para voltar aos de antes com uma linha.
 */
#[CoversClass(NomesDasSituacoesDaJustificativa::class)]
final class NomesDasSituacoesDaJustificativaTest extends TestCase
{
    #[TestDox('Vigentes: os nomes do desenho ("Em análise", "Recusado", "recusada")')]
    public function testVigentesSaoOsDoDesenho(): void
    {
        self::assertSame('Em análise', NomesDasSituacoesDaJustificativa::texto('situacao.pendente'));
        self::assertSame('Recusado', NomesDasSituacoesDaJustificativa::texto('situacao.rejeitado'));
        self::assertSame('Sua justificativa de ponto foi recusada.', NomesDasSituacoesDaJustificativa::texto('aviso.recusada'));
        self::assertSame('Justificativa de 03/11/2026 recusada.', NomesDasSituacoesDaJustificativa::texto('analise.recusada', '03/11/2026'));
        self::assertSame('Informe o motivo da recusa.', NomesDasSituacoesDaJustificativa::texto('analise.sem_motivo'));
        self::assertSame('Voltar para análise', NomesDasSituacoesDaJustificativa::texto('reverter.botao'));
        self::assertSame('Recusar', NomesDasSituacoesDaJustificativa::texto('ficha.recusar'));
        self::assertSame('Motivo da recusa (obrigatório)', NomesDasSituacoesDaJustificativa::texto('ficha.motivo'));
        self::assertSame('Recusar justificativa de 03/11/2026?', NomesDasSituacoesDaJustificativa::texto('ficha.confirmar_recusa', '03/11/2026'));
        self::assertStringContainsString('abonado ou recusado', NomesDasSituacoesDaJustificativa::texto('anexo.travado'));
        self::assertStringContainsString('voltar para análise', NomesDasSituacoesDaJustificativa::texto('anexo.travado'));
        self::assertStringContainsString('não está mais em análise', NomesDasSituacoesDaJustificativa::texto('edicao.ja_analisada'));
        self::assertSame(NomesDasSituacoesDaJustificativa::texto('edicao.ja_analisada'), \App\Ponto\Exception\JustificativaJaAnalisadaException::MENSAGEM);
        self::assertSame('Nenhuma justificativa em análise encontrada neste lote.', NomesDasSituacoesDaJustificativa::texto('lote.sem_pendente'));
    }

    #[TestDox('De antes: a chave devolve os textos antigos da ficha ("Rejeitar", "Motivo da rejeição")')]
    public function testDeAntesDevolveOsTextosAntigosDaFicha(): void
    {
        self::assertSame('Rejeitar', NomesDasSituacoesDaJustificativa::DE_ANTES['ficha.recusar']);
        self::assertSame('Motivo da rejeição (obrigatório)', NomesDasSituacoesDaJustificativa::DE_ANTES['ficha.motivo']);
        self::assertSame('Rejeitar justificativa de %s?', NomesDasSituacoesDaJustificativa::DE_ANTES['ficha.confirmar_recusa']);
    }

    #[TestDox('Os dois conjuntos têm as mesmas chaves: a troca de uma linha não deixa texto faltando')]
    public function testConjuntosComAsMesmasChaves(): void
    {
        self::assertSame(array_keys(NomesDasSituacoesDaJustificativa::DO_DESENHO), array_keys(NomesDasSituacoesDaJustificativa::DE_ANTES));
    }

    #[TestDox('Sem travessão e sem "Pendente"/"Rejeitado" nos nomes do desenho')]
    public function testNomesDoDesenhoSemOsAntigos(): void
    {
        foreach (NomesDasSituacoesDaJustificativa::DO_DESENHO as $chave => $texto) {
            self::assertDoesNotMatchRegularExpression('/[—–]|\bPendente\b|\bRejeitad[oa]\b|\brejeitad[oa]\b|\bRejeitar\b|\bpendente\b|rejeição/u', $texto, $chave);
        }
    }

    #[TestDox('Chave sem texto é erro de programação, nunca uma tela com o nome vazio')]
    public function testChaveDesconhecida(): void
    {
        $this->expectException(\LogicException::class);

        NomesDasSituacoesDaJustificativa::texto('situacao.inexistente');
    }
}
