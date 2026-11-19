<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\MensagemSuporte;
use App\Auth\Repository\MensagemSuporteRepository;
use App\Auth\UseCase\MarcarRespostaDoSuporteUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(MarcarRespostaDoSuporteUseCase::class)]
final class MarcarRespostaDoSuporteUseCaseTest extends TestCase
{
    #[TestDox('Marca, marcar de novo mantém a data da primeira resposta, e desfazer volta para pendente')]
    public function testMarcaMantemADataEDesfaz(): void
    {
        $mensagem = new MensagemSuporte('Cliente', 'a@adv.com', null, 'Mensagem longa o bastante.', '1.2.3.4');
        $repositorio = $this->createMock(MensagemSuporteRepository::class);
        $repositorio->method('find')->with(7)->willReturn($mensagem);
        $repositorio->expects($this->exactly(3))->method('salvar')->with($mensagem, true);
        $sut = new MarcarRespostaDoSuporteUseCase($repositorio);

        $sut->executar(7, true, new \DateTimeImmutable('2026-11-19 17:00'));
        $sut->executar(7, true, new \DateTimeImmutable('2026-11-19 18:00'));
        self::assertSame('2026-11-19 17:00', $mensagem->getRespondidaEm()?->format('Y-m-d H:i'));

        $sut->executar(7, false, new \DateTimeImmutable('2026-11-19 19:00'));
        self::assertNull($mensagem->getRespondidaEm());
    }

    #[TestDox('Mensagem que não existe: 404')]
    public function testMensagemInexistente(): void
    {
        $repositorio = $this->createMock(MensagemSuporteRepository::class);
        $repositorio->method('find')->willReturn(null);
        $repositorio->expects($this->never())->method('salvar');

        $this->expectException(NotFoundHttpException::class);

        (new MarcarRespostaDoSuporteUseCase($repositorio))->executar(99, true, new \DateTimeImmutable());
    }
}
