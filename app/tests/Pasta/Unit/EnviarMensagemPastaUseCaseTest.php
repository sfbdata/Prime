<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnviarMensagemPastaUseCase::class)]
final class EnviarMensagemPastaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private EntityManagerInterface&MockObject $em;
    private EnviarMensagemPastaUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EnviarMensagemPastaUseCase($this->em, $this->criarSanitizadorTextoRico());

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testEnviarMensagemCriaEntidade(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaMensagem::class));
        $this->em->expects($this->once())->method('flush');

        $mensagem = $this->useCase->executar($this->pasta, $this->autor, 'Mensagem de teste', $this->tenant);

        self::assertSame('Mensagem de teste', $mensagem->getConteudo());
        self::assertSame($this->pasta, $mensagem->getPasta());
        self::assertSame($this->autor, $mensagem->getAutor());
        self::assertSame($this->tenant, $mensagem->getTenant());
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

    // ── Responder ────────────────────────────────────────────────────────────

    private function mensagemDe(Pasta $pasta, Tenant $tenant, ?PastaMensagem $respostaA = null): PastaMensagem
    {
        return (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($this->autor)
            ->setTenant($tenant)
            ->setConteudo('Original')
            ->setRespostaA($respostaA);
    }

    #[TestDox('Sem resposta_a a mensagem nasce como registro comum (não é resposta)')]
    public function testSemRespostaNasceRegistroComum(): void
    {
        $mensagem = $this->useCase->executar($this->pasta, $this->autor, 'Comum', $this->tenant);

        self::assertNull($mensagem->getRespostaA());
        self::assertFalse($mensagem->isResposta());
    }

    #[TestDox('Responder a um registro da MESMA pasta e do MESMO escritório liga a resposta a ele')]
    public function testRespostaValidaLigaARaiz(): void
    {
        $original = $this->mensagemDe($this->pasta, $this->tenant);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resposta = $this->useCase->executar($this->pasta, $this->autor, 'Respondendo', $this->tenant, $original);

        self::assertSame($original, $resposta->getRespostaA());
        self::assertTrue($resposta->isResposta());
        self::assertFalse($resposta->isRespostaOrfa());
    }

    #[TestDox('Responder a um registro de OUTRA pasta (mesmo escritório) é recusado e nada é gravado')]
    public function testRespostaAMensagemDeOutraPastaRecusa(): void
    {
        $deOutraPasta = $this->mensagemDe(new Pasta(), $this->tenant);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Invasão', $this->tenant, $deOutraPasta);
    }

    #[TestDox('Responder a um registro de OUTRO escritório é recusado e nada é gravado (IDOR)')]
    public function testRespostaAMensagemDeOutroTenantRecusa(): void
    {
        $deOutroTenant = $this->mensagemDe($this->pasta, new Tenant());

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Invasão', $this->tenant, $deOutroTenant);
    }

    #[TestDox('Uma mensagem sem pasta ou sem tenant não prova a posse: a resposta é recusada')]
    public function testRespostaAMensagemSemPastaOuTenantRecusa(): void
    {
        $semPasta = (new PastaMensagem())->setTenant($this->tenant)->setConteudo('x');

        $this->em->expects($this->never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $semPasta);
    }

    #[TestDox('Responder a uma RESPOSTA liga à raiz dela: a conversa tem um nível só')]
    public function testRespostaDeRespostaApontaParaARaiz(): void
    {
        $raiz     = $this->mensagemDe($this->pasta, $this->tenant);
        $resposta = $this->mensagemDe($this->pasta, $this->tenant, $raiz);

        $nova = $this->useCase->executar($this->pasta, $this->autor, 'Tréplica', $this->tenant, $resposta);

        self::assertSame($raiz, $nova->getRespostaA());
        self::assertNull($raiz->getRespostaA(), 'a raiz continua raiz');
    }

    #[TestDox('Proxy do ORM (outra instância, mesmo id) conta como a mesma pasta e o mesmo escritório')]
    public function testMesmaPastaPorIdAceita(): void
    {
        $this->definirId($this->pasta, 41);
        $this->definirId($this->tenant, 7);
        $pastaProxy  = $this->definirId(new Pasta(), 41);
        $tenantProxy = $this->definirId(new Tenant(), 7);
        $original    = $this->mensagemDe($pastaProxy, $tenantProxy);

        $nova = $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $original);

        self::assertSame($original, $nova->getRespostaA());
    }

    #[TestDox('Ids diferentes recusam mesmo com tudo o mais igual')]
    public function testOutraPastaPorIdRecusa(): void
    {
        $this->definirId($this->pasta, 41);
        $this->definirId($this->tenant, 7);
        $original = $this->mensagemDe($this->definirId(new Pasta(), 42), $this->definirId(new Tenant(), 7));

        $this->em->expects($this->never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $original);
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private function definirId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
