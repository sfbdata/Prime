<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Entity\ImagemDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ImagemDaCampanhaRepository;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A imagem da mensagem do modo simples (spec comercial-e-emails.md §12, F3i): a mensagem só aceita o token (nunca URL
 * nem `data:`), o token desconhecido ou vencido é recusado com "A imagem expirou; envie de novo.", e a validade é
 * renovada pelo envio (1 ano + o uso) e pelo modelo salvo (1 ano).
 */
#[CoversClass(ImagensDaCampanha::class)]
#[CoversClass(MensagemDaCampanha::class)]
#[CoversClass(ImagemDaCampanha::class)]
final class ImagensDaCampanhaTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    #[TestDox('A mensagem do simples guarda o token; ida e volta pelo conteúdo gravado (o JSON sem imagem continua igual)')]
    public function testTokenNaMensagem(): void
    {
        $m = MensagemDaCampanha::deArray(['modo' => 'simples', 'chave' => 'convite', 'assunto' => 'A', 'titulo' => 'T', 'corpo' => 'C', 'imagem' => self::TOKEN]);
        self::assertSame(self::TOKEN, $m->imagem);
        $json = ModeloDaGrade::conteudoParaGravar($m);
        self::assertSame(['corpo' => 'C', 'itens' => [], 'cta' => '', 'link' => '', 'imagem' => self::TOKEN], json_decode($json, true));

        $volta = MensagemDaCampanha::deArray(['modo' => 'simples', 'chave' => 'convite'] + (array) json_decode($json, true));
        self::assertSame(self::TOKEN, $volta->imagem);

        $sem = MensagemDaCampanha::deArray(['modo' => 'simples', 'chave' => 'convite', 'corpo' => 'C', 'imagem' => '']);
        self::assertSame('{"corpo":"C","itens":[],"cta":"","link":""}', ModeloDaGrade::conteudoParaGravar($sem));
    }

    #[TestDox('Fora do simples o campo é ignorado (o bloco "Imagem" dos blocos continua com https://)')]
    public function testSoNoSimples(): void
    {
        $m = MensagemDaCampanha::deArray(['modo' => 'blocos', 'chave' => 'news', 'blocos' => [], 'imagem' => 'https://evil.test/x.png']);

        self::assertSame('', $m->imagem);
    }

    /** @return iterable<string, array{mixed}> */
    public static function naoSaoToken(): iterable
    {
        yield 'URL https' => ['https://exemplo.com/foto.jpg'];
        yield 'data:' => ['data:image/jpeg;base64,/9j/4AAQSkZJRgABAQ'];
        yield 'caminho' => ['/e/i/' . self::TOKEN . '.jpg'];
        yield 'maiúsculas' => [strtoupper(self::TOKEN)];
        yield 'curto' => ['abc123'];
        yield 'não é texto' => [['token' => self::TOKEN]];
    }

    #[DataProvider('naoSaoToken')]
    #[TestDox('Imagem que não é token é recusada: $_dataName')]
    public function testSoOFormatoDoToken(mixed $valor): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage(MensagemDaCampanha::IMAGEM_INVALIDA);

        MensagemDaCampanha::deArray(['modo' => 'simples', 'chave' => 'convite', 'imagem' => $valor]);
    }

    #[TestDox('Sem imagem nada é consultado; token desconhecido ou vencido: "A imagem expirou; envie de novo."')]
    public function testExigirVigente(): void
    {
        $repositorio = $this->createMock(ImagemDaCampanhaRepository::class);
        $repositorio->expects(self::once())->method('vigente')->with(self::TOKEN)->willReturn(null);
        $imagens = new ImagensDaCampanha($repositorio, $this->createStub(UrlGeneratorInterface::class), new MockClock());

        $imagens->exigirVigente(self::mensagem(''));
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage('A imagem expirou; envie de novo.');
        $imagens->exigirVigente(self::mensagem(self::TOKEN));
    }

    #[TestDox('O envio leva a validade a 1 ano e marca o uso; o modelo salvo, só a validade; sem imagem, nada')]
    public function testRenovacoes(): void
    {
        $agora = new \DateTimeImmutable('2030-03-10 12:00:00');
        $chamadas = [];
        $repositorio = $this->createMock(ImagemDaCampanhaRepository::class);
        $repositorio->expects(self::exactly(2))->method('prolongar')->willReturnCallback(static function (string $token, \DateTimeImmutable $ate, ?\DateTimeImmutable $uso = null) use (&$chamadas): void {
            $chamadas[] = [$token, $ate->format('Y-m-d H:i:s'), $uso?->format('Y-m-d H:i:s')];
        });
        $imagens = new ImagensDaCampanha($repositorio, $this->createStub(UrlGeneratorInterface::class), new MockClock($agora));

        $imagens->renovarPeloEnvio(self::mensagem(self::TOKEN), $agora);
        $imagens->renovarPeloModelo(self::mensagem(self::TOKEN), $agora);
        $imagens->renovarPeloEnvio(self::mensagem(''), $agora);
        $imagens->renovarPeloModelo(self::mensagem(''), $agora);

        self::assertSame([
            [self::TOKEN, '2031-03-10 12:00:00', '2030-03-10 12:00:00'],
            [self::TOKEN, '2031-03-10 12:00:00', null],
        ], $chamadas);
    }

    #[TestDox('O endereço público é absoluto, com a extensão do tipo gravado; vencida ou sem imagem, null')]
    public function testUrl(): void
    {
        $agora = new \DateTimeImmutable('2030-03-10 12:00:00');
        $imagem = new ImagemDaCampanha('a1b2.png', 'image/png', 10, str_repeat('0', 64), 1, 1, new User(), $agora, $agora->modify('+30 days'));
        $repositorio = $this->createStub(ImagemDaCampanhaRepository::class);
        $repositorio->method('vigente')->willReturnCallback(static fn (string $t): ?ImagemDaCampanha => $t === $imagem->getToken() ? $imagem : null);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())->method('generate')
            ->with(ImagensDaCampanha::ROTA, ['token' => $imagem->getToken(), 'ext' => 'png'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://app.bluejus.test/e/i/' . $imagem->getToken() . '.png');
        $imagens = new ImagensDaCampanha($repositorio, $urls, new MockClock($agora));

        self::assertSame('https://app.bluejus.test/e/i/' . $imagem->getToken() . '.png', $imagens->url($imagem->getToken()));
        self::assertNull($imagens->url(self::TOKEN));
        self::assertNull($imagens->url(''));
        self::assertMatchesRegularExpression(ImagemDaCampanha::PADRAO_DO_TOKEN, $imagem->getToken());
        self::assertTrue($imagem->vigente($agora->modify('+30 days')));
        self::assertFalse($imagem->vigente($agora->modify('+30 days +1 second')));
    }

    private static function mensagem(string $imagem): MensagemDaCampanha
    {
        return new MensagemDaCampanha('convite', 'simples', 'Assunto', 'SELO', 'Título', 'Corpo', imagem: $imagem);
    }
}
