<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArtigoDoBlog::class)]
final class ArtigoDoBlogTest extends TestCase
{
    private function rascunho(): ArtigoDoBlog
    {
        return new ArtigoDoBlog('meu-artigo', BlogsDoArtigo::Ambos, null, new \DateTimeImmutable('2026-11-01 10:00'));
    }

    #[TestDox('Nasce rascunho, sem datas de publicação e sem alterações pendentes')]
    public function testNasceRascunho(): void
    {
        $artigo = $this->rascunho();

        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
        self::assertNull($artigo->getPublicadoEm());
        self::assertFalse($artigo->temAlteracoesPendentes());
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00'), $artigo->getAlteradoEm());
    }

    #[TestDox('Slug fora do padrão do zod é recusado na criação e na troca')]
    public function testSlugInvalido(): void
    {
        $this->expectException(\DomainException::class);
        $this->rascunho()->trocarSlug('Com Acento-é');
    }

    #[TestDox('Agendar exige data futura e publicar usa a data marcada quando ela já passou')]
    public function testAgendarEPublicarNaHora(): void
    {
        $artigo = $this->rascunho();
        $artigo->agendar(new \DateTimeImmutable('2026-11-05 09:00'), new \DateTimeImmutable('2026-11-03 10:00'));
        self::assertSame(SituacaoDoArtigo::Agendado, $artigo->getSituacao());

        $artigo->publicar(new \DateTimeImmutable('2026-11-05 09:10'));

        self::assertSame(SituacaoDoArtigo::Publicado, $artigo->getSituacao());
        self::assertEquals(new \DateTimeImmutable('2026-11-05 09:00'), $artigo->getPublicadoEm());
        self::assertNull($artigo->getAtualizadoEm());
    }

    #[TestDox('Agendar no passado é recusado')]
    public function testAgendarNoPassado(): void
    {
        $this->expectExceptionMessage('A data do agendamento precisa estar no futuro.');
        $this->rascunho()->agendar(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-03'));
    }

    #[TestDox('Publicar agora um agendado para o futuro usa a data de agora')]
    public function testPublicarAntesDoAgendado(): void
    {
        $artigo = $this->rascunho();
        $artigo->agendar(new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-03'));
        $artigo->publicar(new \DateTimeImmutable('2026-11-04 15:00'));

        self::assertEquals(new \DateTimeImmutable('2026-11-04 15:00'), $artigo->getPublicadoEm());
    }

    #[TestDox('Cancelar o agendamento volta para rascunho')]
    public function testCancelarAgendamento(): void
    {
        $artigo = $this->rascunho();
        $artigo->agendar(new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-03'));
        $artigo->cancelarAgendamento();

        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
    }

    #[TestDox('Editar um publicado guarda o texto e marca alterações não publicadas; publicar de novo muda atualizadoEm (D7)')]
    public function testEditarPublicadoFicaPendente(): void
    {
        $artigo = $this->rascunho();
        $artigo->publicar(new \DateTimeImmutable('2026-11-03 10:00'));

        $artigo->registrarAlteracao(null, new \DateTimeImmutable('2026-11-04 10:00'));
        self::assertSame(SituacaoDoArtigo::Publicado, $artigo->getSituacao());
        self::assertTrue($artigo->temAlteracoesPendentes());

        $artigo->publicar(new \DateTimeImmutable('2026-11-04 11:00'));
        self::assertFalse($artigo->temAlteracoesPendentes());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 10:00'), $artigo->getPublicadoEm());
        self::assertEquals(new \DateTimeImmutable('2026-11-04 11:00'), $artigo->getAtualizadoEm());
    }

    #[TestDox('Publicar de novo sem alteração nenhuma é recusado')]
    public function testRepublicarSemAlteracao(): void
    {
        $artigo = $this->rascunho();
        $artigo->publicar(new \DateTimeImmutable('2026-11-03 10:00'));
        $artigo->registrarVersaoPublicada(['blog' => '---'], []);

        $this->expectException(\DomainException::class);
        $artigo->publicar(new \DateTimeImmutable('2026-11-03 11:00'));
    }

    #[TestDox('Despublicar e publicar de novo sem texto novo mantém as datas')]
    public function testDespublicarEVoltar(): void
    {
        $artigo = $this->rascunho();
        $artigo->publicar(new \DateTimeImmutable('2026-11-03 10:00'));
        $artigo->despublicar();
        self::assertSame(SituacaoDoArtigo::Despublicado, $artigo->getSituacao());

        $artigo->publicar(new \DateTimeImmutable('2026-11-04 10:00'));
        self::assertSame(SituacaoDoArtigo::Publicado, $artigo->getSituacao());
        self::assertNull($artigo->getAtualizadoEm());
    }

    #[TestDox('Só o publicado despublica, agendado não reagenda depois de publicado, e publicado não se exclui')]
    public function testTransicoesProibidas(): void
    {
        $artigo = $this->rascunho();
        $artigo->exigirExcluivel();
        try {
            $artigo->despublicar();
            self::fail('rascunho não despublica');
        } catch (\DomainException) {
        }

        $artigo->publicar(new \DateTimeImmutable('2026-11-03 10:00'));
        try {
            $artigo->agendar(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-11-03'));
            self::fail('publicado não agenda');
        } catch (\DomainException) {
        }

        $this->expectExceptionMessage('Despublique o artigo antes de excluir.');
        $artigo->exigirExcluivel();
    }

    #[TestDox('Campos opcionais vazios viram nulo e relacionados repetidos caem')]
    public function testOpcionaisVazios(): void
    {
        $artigo = $this->rascunho();
        $artigo->classificar('automacao', 'equipe-editorial-bluejus', '  ', '', '3 min', false);
        $artigo->definirImagem('', ' ');
        $artigo->definirRelacionados(['a', 'b', 'a']);

        self::assertNull($artigo->getRevisorNome());
        self::assertNull($artigo->getRevisorOab());
        self::assertNull($artigo->getImagemChave());
        self::assertSame(['a', 'b'], $artigo->getRelacionados());
    }
}
