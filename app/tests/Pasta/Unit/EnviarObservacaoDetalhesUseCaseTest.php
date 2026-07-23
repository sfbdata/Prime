<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Entity\Tenant\Tenant;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EnviarObservacaoDetalhesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnviarObservacaoDetalhesUseCase::class)]
final class EnviarObservacaoDetalhesUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private EntityManagerInterface&MockObject $em;
    private EnviarObservacaoDetalhesUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EnviarObservacaoDetalhesUseCase($this->em, $this->criarSanitizadorTextoRico());

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testEnviarObservacaoCriaEntidade(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaObservacaoDetalhes::class));
        $this->em->expects($this->once())->method('flush');

        $obs = $this->useCase->executar($this->pasta, $this->autor, 'Observação de detalhes', $this->tenant);

        self::assertSame('Observação de detalhes', $obs->getConteudo());
        self::assertSame($this->pasta, $obs->getPasta());
        self::assertSame($this->autor, $obs->getAutor());
        self::assertSame($this->tenant, $obs->getTenant());
    }

    public function testConteudoVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    public function testConteudoAcimaDe5000CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 5001), $this->tenant);
    }

    // ── Editor rico ──────────────────────────────────────────────────────────────────────────

    #[TestDox('o banco nunca recebe marcação perigosa — a limpeza é na ENTRADA')]
    public function testConteudoMaliciosoEGravadoLimpo(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $obs = $this->useCase->executar(
            $this->pasta,
            $this->autor,
            '<p>Combinado com o cliente</p><script>alert(document.cookie)</script>',
            $this->tenant,
        );

        self::assertStringNotContainsStringIgnoringCase('<script', $obs->getConteudo());
        self::assertStringContainsString('Combinado com o cliente', $obs->getConteudo());
    }

    #[TestDox('a formatação legítima do editor é preservada ao gravar')]
    public function testFormatacaoLegitimaEPreservada(): void
    {
        $obs = $this->useCase->executar(
            $this->pasta,
            $this->autor,
            '<p><strong>Urgente:</strong> ligar hoje</p><ul><li>conferir prazo</li></ul>',
            $this->tenant,
        );

        self::assertStringContainsString('<strong>Urgente:</strong>', $obs->getConteudo());
        self::assertStringContainsString('<li>conferir prazo</li>', $obs->getConteudo());
    }

    #[TestDox('o parágrafo vazio do editor é recusado como conteúdo vazio')]
    public function testParagrafoVazioDoEditorERecusado(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        // Não é string vazia — é o que o editor entrega quando ninguém digitou nada.
        $this->useCase->executar($this->pasta, $this->autor, '<p><br></p>', $this->tenant);
    }

    #[TestDox('formatar não reduz quanto texto cabe — o limite conta o visível')]
    public function testLimiteContaTextoVisivelENaoMarcacao(): void
    {
        $this->em->expects($this->once())->method('persist');

        // 4.900 caracteres de texto: passaria do limite se a marcação fosse contada junto.
        $texto = str_repeat('a', 4900);
        $obs = $this->useCase->executar(
            $this->pasta,
            $this->autor,
            '<p><strong>' . $texto . '</strong></p>',
            $this->tenant,
        );

        self::assertStringContainsString($texto, $obs->getConteudo());
    }
}
