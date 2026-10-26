<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use App\Master\Service\AvisoDeConvenioDaUf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AvisoDeConvenioDaUf::class)]
final class AvisoDeConvenioDaUfTest extends TestCase
{
    /** @var list<MensagemDeEmail> */
    private array $enviadas = [];

    /** @var list<int> */
    private array $escritoriosConsultados = [];

    /** @param list<array{email: string, nome: string}> $primarios */
    private function servico(array $primarios, int $tetoTotal = 10, int $tetoCampanhas = 6): AvisoDeConvenioDaUf
    {
        $painel = $this->createStub(PainelMasterRepository::class);
        $painel->method('escritorios')->willReturn([
            ['id' => 1, 'estado' => 'sp', 'ativo' => true],
            ['id' => 2, 'estado' => 'SP', 'ativo' => false],
            ['id' => 3, 'estado' => 'RJ', 'ativo' => true],
            ['id' => 4, 'estado' => null, 'ativo' => true],
            ['id' => 5, 'estado' => ' SP ', 'ativo' => true],
        ]);
        $repo = $this->createStub(PrimariosDosEscritoriosRepository::class);
        $repo->method('dos')->willReturnCallback(function (array $ids) use ($primarios): array {
            $this->escritoriosConsultados = $ids;

            return $primarios;
        });
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willReturnCallback(function (MensagemDeEmail $m): ResultadoDoEnvio {
            $this->enviadas[] = $m;

            return new ResultadoDoEnvio(1, ResultadoDoEnvio::ENFILEIRADO);
        });
        $cota = new CotaDeEnvio($this->createStub(EnvioDeEmailRepository::class), $this->createStub(DestinoDaCampanhaRepository::class), new MockClock(), $tetoTotal, $tetoCampanhas, 10, 80);
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id, array $p = []): string => $id . ($p === [] ? '' : '|' . json_encode($p, \JSON_UNESCAPED_UNICODE)));

        return new AvisoDeConvenioDaUf($painel, $repo, $carteiro, $cota, $tradutor, new NullLogger());
    }

    private function convenio(string $modo, ?string $motivo = null): ConvenioDaUf
    {
        $em = new \DateTimeImmutable('2026-10-26');

        return new ConvenioDaUf('SP', $modo, 9.9, new \DateTimeImmutable('2026-11-01'), $motivo, null, $em);
    }

    #[TestDox('só os escritórios ativos da UF entram, pela mesma regra de UF das contas')]
    public function testSoEscritoriosAtivosDaUf(): void
    {
        $dest = $this->servico([['email' => 'a@x.com', 'nome' => 'Ana Silva']])->destinatarios('SP');

        self::assertSame([1, 5], $this->escritoriosConsultados);
        self::assertCount(1, $dest);
    }

    #[TestDox('modo dif: um e-mail por pessoa, transacional, origem convenio_oab, valor e data no assunto')]
    public function testDif(): void
    {
        $s = $this->servico([]);
        $n = $s->avisar('SP', $this->convenio('dif'), [['email' => 'A@x.com', 'nome' => 'Ana Silva'], ['email' => 'b@x.com', 'nome' => 'Beto']]);

        self::assertSame(2, $n);
        self::assertSame(['a@x.com', 'b@x.com'], array_map(static fn (MensagemDeEmail $m): string => $m->para, $this->enviadas));
        $m = $this->enviadas[0];
        self::assertSame(TipoDeEnvio::Transacional, $m->tipo);
        self::assertSame('convenio_oab', $m->origem);
        self::assertSame('master.convenio_email.assunto_dif|{"uf":"SP","valor":"9,90","data":"01\/11\/2026"}', $m->assunto);
        self::assertStringContainsString('&quot;nome&quot;:&quot;Ana&quot;', $m->corpoHtml);
        self::assertStringNotContainsString('motivo', $m->corpoHtml);
        self::assertStringNotContainsString('b@x.com', $m->corpoHtml);
        self::assertCount(0, $m->cc);
    }

    #[TestDox('modo sem: o motivo entra só quando existe')]
    public function testSemComESemMotivo(): void
    {
        $s = $this->servico([]);
        $s->avisar('SP', $this->convenio('sem', 'Não renovado'), [['email' => 'a@x.com', 'nome' => 'Ana']]);
        $s->avisar('SP', $this->convenio('sem'), [['email' => 'a@x.com', 'nome' => 'Ana']]);

        self::assertStringContainsString('assunto_sem', $this->enviadas[0]->assunto);
        self::assertStringContainsString('corpo_sem', $this->enviadas[0]->corpoHtml);
        self::assertStringContainsString('convenio_email.motivo|{&quot;motivo&quot;:&quot;Não renovado&quot;}', $this->enviadas[0]->corpoHtml);
        self::assertStringNotContainsString('convenio_email.motivo', $this->enviadas[1]->corpoHtml);
    }

    #[TestDox('modo livre: aviso de volta ao grátis, sem valor nem data')]
    public function testLivre(): void
    {
        $this->servico([])->avisar('SP', null, [['email' => 'a@x.com', 'nome' => 'Ana']]);

        self::assertSame('master.convenio_email.assunto_livre|{"uf":"SP","valor":"0,00","data":""}', $this->enviadas[0]->assunto);
    }

    #[TestDox('acima do teto (metade da reserva da cota) recusa com mensagem, sem enviar')]
    public function testTeto(): void
    {
        // total 10, campanhas 6: reserva 4, teto 2 destinatários.
        $s = $this->servico([['email' => 'a@x.com', 'nome' => 'A'], ['email' => 'b@x.com', 'nome' => 'B'], ['email' => 'c@x.com', 'nome' => 'C']]);

        self::assertSame(2, $s->tetoDeDestinatarios());
        try {
            $s->destinatarios('SP');
            self::fail('Devia recusar');
        } catch (ConvenioRecusado $e) {
            self::assertStringContainsString('recusa_teto', $e->getMessage());
        }
        self::assertSame([], $this->enviadas);
    }

    #[TestDox('falha de um envio não derruba os outros e não conta como enfileirado')]
    public function testFalhaIsolada(): void
    {
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willReturnCallback(static function (MensagemDeEmail $m): ResultadoDoEnvio {
            if ($m->para === 'a@x.com') {
                throw new \RuntimeException('falha');
            }

            return new ResultadoDoEnvio(1, ResultadoDoEnvio::ENFILEIRADO);
        });
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $s = new AvisoDeConvenioDaUf(
            $this->createStub(PainelMasterRepository::class),
            $this->createStub(PrimariosDosEscritoriosRepository::class),
            $carteiro,
            new CotaDeEnvio($this->createStub(EnvioDeEmailRepository::class), $this->createStub(DestinoDaCampanhaRepository::class), new MockClock(), 10, 6, 10, 80),
            $tradutor,
            new NullLogger(),
        );

        self::assertSame(1, $s->avisar('SP', null, [['email' => 'a@x.com', 'nome' => 'A'], ['email' => 'b@x.com', 'nome' => 'B']]));
    }
}
