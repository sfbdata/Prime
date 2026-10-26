<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use App\Master\Service\AvisoDeConvenioDaUf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AvisoDeConvenioDaUf::class)]
final class AvisoDeConvenioDaUfTest extends TestCase
{
    /** @var list<MensagemDeEmail> */
    private array $enviadas = [];

    /** @var list<int> */
    private array $escritoriosConsultados = [];

    /** @param list<array{email: string, nome: string}> $primarios */
    private function servico(array $primarios): AvisoDeConvenioDaUf
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
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id, array $p = []): string => $id . ($p === [] ? '' : '|' . json_encode($p, \JSON_UNESCAPED_UNICODE)));

        return new AvisoDeConvenioDaUf($painel, $repo, $carteiro, $tradutor);
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

    #[TestDox('sem teto de destinatários: enfileira todos, por maior que seja a UF')]
    public function testSemTeto(): void
    {
        $muitos = array_map(static fn (int $i): array => ['email' => "p$i@x.com", 'nome' => "P$i"], range(1, 1500));
        $s = $this->servico($muitos);

        self::assertCount(1500, $s->destinatarios('SP'));
        self::assertSame(1500, $s->avisar('SP', null, $muitos));
    }

    #[TestDox('falha de um envio propaga, para o UseCase desfazer a gravação')]
    public function testFalhaPropaga(): void
    {
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willThrowException(new \RuntimeException('falha'));
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $s = new AvisoDeConvenioDaUf($this->createStub(PainelMasterRepository::class), $this->createStub(PrimariosDosEscritoriosRepository::class), $carteiro, $tradutor);

        $this->expectException(\RuntimeException::class);
        $s->avisar('SP', null, [['email' => 'a@x.com', 'nome' => 'A']]);
    }
}
