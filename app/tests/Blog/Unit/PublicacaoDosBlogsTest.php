<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDaPublicacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicacaoDosBlogs::class)]
final class PublicacaoDosBlogsTest extends TestCase
{
    private function nova(): PublicacaoDosBlogs
    {
        return new PublicacaoDosBlogs(new \DateTimeImmutable('2026-11-03 14:00'), null, MotivoDaPublicacao::Publicar);
    }

    #[TestDox('Na fila, gerando e no ar: guarda início, fim, commit e só as últimas 200 linhas do log')]
    public function testCiclo(): void
    {
        $p = $this->nova();
        self::assertSame(SituacaoDaPublicacao::NaFila, $p->getSituacao());
        self::assertFalse($p->montagemIniciada());
        $p->marcarEmMontagem();
        self::assertTrue($p->montagemIniciada(), 'em montagem: um Publicar novo não o reaproveita');
        self::assertFalse($p->pacoteMontado());
        self::assertSame([], $p->getArtigos());
        $p->registrarPacote(['blog' => ['a'], 'blog-gratis' => []]);
        self::assertTrue($p->pacoteMontado());

        $p->marcarGerando(new \DateTimeImmutable('2026-11-03 14:01'));
        $p->marcarGerando(new \DateTimeImmutable('2026-11-03 14:02'));
        self::assertEquals(new \DateTimeImmutable('2026-11-03 14:01'), $p->getIniciadaEm(), 'repetir não muda o início');

        $log = implode("\n", array_map(static fn (int $i): string => 'linha ' . $i, range(1, 250))) . "\n";
        $p->concluir(true, ' a1b2c3d ', $log, null, new \DateTimeImmutable('2026-11-03 14:03'));

        self::assertSame(SituacaoDaPublicacao::NoAr, $p->getSituacao());
        self::assertFalse($p->estaAberta());
        self::assertSame('a1b2c3d', $p->getCommitDosSites());
        $linhas = explode("\n", (string) $p->getLog());
        self::assertCount(200, $linhas);
        self::assertSame('linha 51', $linhas[0]);
        self::assertSame('linha 250', $linhas[199]);
    }

    #[TestDox('Pedido que terminou não muda mais (nem recebe pacote)')]
    public function testTerminadoNaoMuda(): void
    {
        $p = $this->nova();
        $p->falhar('O builder não respondeu em 30 minutos.', new \DateTimeImmutable('2026-11-03 14:31'));
        self::assertSame(SituacaoDaPublicacao::Falhou, $p->getSituacao());

        foreach ([
            fn () => $p->registrarPacote([]),
            fn () => $p->marcarEmMontagem(),
            fn () => $p->marcarGerando(new \DateTimeImmutable()),
            fn () => $p->concluir(true, null, '', null, new \DateTimeImmutable()),
            fn () => $p->falhar('de novo', new \DateTimeImmutable()),
        ] as $acao) {
            try {
                $acao();
                self::fail('Deveria recusar.');
            } catch (\DomainException) {
            }
        }
        self::assertSame('O builder não respondeu em 30 minutos.', $p->getLog());
    }
}
