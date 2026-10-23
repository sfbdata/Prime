<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\RegistroDeEnviosInterface;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\RegistroDeEnvios;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** As consultas de `comercial_envio` contra o Postgres: token único, situações, recuperação da varredura. */
#[CoversClass(EnvioDeEmailRepository::class)]
#[CoversClass(RegistroDeEnvios::class)]
final class EnvioDeEmailRepositoryTest extends KernelTestCase
{
    private Connection $conexao;
    private EnvioDeEmailRepository $envios;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->envios = static::getContainer()->get(EnvioDeEmailRepository::class);
    }

    private static function mensagem(): MensagemDeEmail
    {
        return new MensagemDeEmail('ana@exemplo.com', 'Assunto', '<p>Corpo</p>', TipoDeEnvio::Transacional, 'teste', cc: ['b@exemplo.com'], rastrear: true);
    }

    private function inserir(string $situacao, ?string $proxima, ?string $token = null): int
    {
        $agora = new \DateTimeImmutable('2030-01-15 10:00:00');

        return $this->envios->inserir(self::mensagem(), $token ?? bin2hex(random_bytes(16)), $situacao, $agora, $proxima === null ? null : new \DateTimeImmutable($proxima));
    }

    #[TestDox('Token é único')]
    public function testTokenUnico(): void
    {
        $this->inserir('enfileirado', null, str_repeat('a', 32));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->envios->emTransacao(fn (): int => $this->inserir('enfileirado', null, str_repeat('a', 32)));
    }

    #[TestDox('lerParaEnvio junta o conteúdo; depois de apagado, tem_conteudo é falso')]
    public function testLerEApagarConteudo(): void
    {
        $id = $this->inserir('enfileirado', '2030-01-15 10:00:00');
        $this->envios->inserirConteudo($id, self::mensagem());

        $lido = $this->envios->lerParaEnvio($id);
        self::assertNotNull($lido);
        self::assertTrue($lido['tem_conteudo']);
        self::assertSame('<p>Corpo</p>', $lido['corpo_html']);
        self::assertSame(['b@exemplo.com'], $lido['cc']);
        self::assertTrue($lido['rastrear']);

        $this->envios->apagarConteudo($id);
        self::assertFalse($this->envios->lerParaEnvio($id)['tem_conteudo'] ?? true);
        self::assertNull($this->envios->lerParaEnvio(999_999_999));
    }

    #[TestDox('RegistroDeEnvios: situação, enviado_em, abriu e clicou por id; ids desconhecidos ficam de fora')]
    public function testSituacoes(): void
    {
        $token = bin2hex(random_bytes(16));
        $enviado = $this->inserir('enviando', null, $token);
        $this->envios->marcarEnviado($enviado, new \DateTimeImmutable('2030-01-15 10:01:00'));
        $this->envios->registrarAbertura($token, new \DateTimeImmutable('2030-01-15 10:05:00'));
        $fila = $this->inserir('enfileirado', '2030-01-15 10:00:00');

        $situacoes = static::getContainer()->get(RegistroDeEnviosInterface::class)->situacoes([$enviado, $fila, 999_999_999]);

        self::assertSame([$enviado, $fila], array_keys($situacoes));
        self::assertSame('enviado', $situacoes[$enviado]->situacao);
        self::assertSame('2030-01-15 10:01:00', $situacoes[$enviado]->enviadoEm?->format('Y-m-d H:i:s'));
        self::assertTrue($situacoes[$enviado]->abriu);
        self::assertFalse($situacoes[$enviado]->clicou);
        self::assertSame('enfileirado', $situacoes[$fila]->situacao);
        self::assertNull($situacoes[$fila]->enviadoEm);
    }

    #[TestDox('enfileiradosPerdidos: só o enfileirado com a próxima tentativa vencida; o adiado pela vazão fica fora')]
    public function testEnfileiradosPerdidos(): void
    {
        $perdido = $this->inserir('enfileirado', '2030-01-15 09:00:00');
        $adiado = $this->inserir('enfileirado', '2030-01-16 00:05:00');
        $enviando = $this->inserir('enviando', '2030-01-15 09:00:00');

        $ids = $this->envios->enfileiradosPerdidos(new \DateTimeImmutable('2030-01-15 09:30:00'), 100);

        self::assertContains($perdido, $ids);
        self::assertNotContains($adiado, $ids);
        self::assertNotContains($enviando, $ids);
    }

    #[TestDox('devolverEnviandoParados: enviando antigo volta a enfileirado, disponível agora; o recente fica')]
    public function testDevolverEnviandoParados(): void
    {
        $velho = $this->inserir('enfileirado', '2030-01-15 09:00:00');
        $this->envios->reivindicar($velho, new \DateTimeImmutable('2030-01-15 09:00:00'));
        $recente = $this->inserir('enfileirado', '2030-01-15 09:00:00');
        $this->envios->reivindicar($recente, new \DateTimeImmutable('2030-01-15 09:58:00'));

        $devolvidos = $this->envios->devolverEnviandoParados(new \DateTimeImmutable('2030-01-15 09:45:00'), new \DateTimeImmutable('2030-01-15 10:00:00'));

        self::assertGreaterThanOrEqual(1, $devolvidos);
        $situacao = fn (int $id): array|false => $this->conexao->fetchAssociative('SELECT situacao, proxima_tentativa_em FROM comercial_envio WHERE id = ?', [$id]);
        self::assertSame('enfileirado', $situacao($velho)['situacao'] ?? null);
        self::assertStringStartsWith('2030-01-15 10:00:00', (string) ($situacao($velho)['proxima_tentativa_em'] ?? ''));
        self::assertSame('enviando', $situacao($recente)['situacao'] ?? null);
    }

    #[TestDox('Estados finais não voltam: falhou e bloqueado só a partir de enfileirado ou enviando; erro cortado em 500')]
    public function testEstadosFinais(): void
    {
        $id = $this->inserir('enviando', null);
        $this->envios->marcarEnviado($id, new \DateTimeImmutable('2030-01-15 10:00:00'));
        $this->envios->marcarFalhou($id, 'x');
        $this->envios->marcarBloqueado($id);
        self::assertSame('enviado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$id]));

        $outro = $this->inserir('enfileirado', null);
        $this->envios->marcarFalhou($outro, str_repeat('e', 900));
        self::assertSame(500, mb_strlen((string) $this->conexao->fetchOne('SELECT erro FROM comercial_envio WHERE id = ?', [$outro])));
    }
}
