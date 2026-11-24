<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Master\DTO\DadosDoConvenio;
use App\Master\Entity\Convenio;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use App\Master\Service\AvisoDeConvenio;
use App\Master\Service\SorteioDeCodigoDeConvite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O aviso do fim de um convênio (spec docs/specs/convenios.md, §6.2): um e-mail por primário, transacional, origem
 * `convenio`; o parágrafo do benefício só com benefício real; o "continua" sem data. E o código de convite (§5.3).
 */
#[CoversClass(AvisoDeConvenio::class)]
#[CoversClass(SorteioDeCodigoDeConvite::class)]
final class AvisoDeConvenioTest extends TestCase
{
    /** @var list<MensagemDeEmail> */
    private array $enviadas = [];

    #[TestDox('fim com benefício real: assunto com a data, parágrafo do benefício e o motivo; um e-mail por pessoa')]
    public function testFimComBeneficioReal(): void
    {
        $convenio = $this->convenio(BeneficioDoConvenio::DescontoFixo, 1250);
        $convenio->marcarFim(new \DateTimeImmutable('2026-12-24'), 'Fim da parceria', null, new \DateTimeImmutable('2026-11-24'));

        $n = $this->servico()->avisar($convenio, new \DateTimeImmutable('2026-12-24'), [['email' => 'a@x.com', 'nome' => 'Ana Silva'], ['email' => 'b@x.com', 'nome' => 'Bia']]);

        self::assertSame(2, $n);
        $m = $this->enviadas[0];
        self::assertSame(['a@x.com', TipoDeEnvio::Transacional, 'convenio'], [$m->para, $m->tipo, $m->origem]);
        self::assertSame('master.convenios_email.assunto_fim|{"nome":"CAASP","data":"24/12/2026"}', $m->assunto);
        self::assertStringContainsString('ola|{&quot;nome&quot;:&quot;Ana&quot;}', $m->corpoHtml);
        self::assertStringContainsString('beneficio.desconto_fixo|{\\&quot;valor\\&quot;:\\&quot;12,50', $m->corpoHtml);
        self::assertStringContainsString('Fim da parceria', $m->corpoHtml);
    }

    #[TestDox('gratuidade: sem o parágrafo do benefício; o fim cancelado vai sem data')]
    public function testGratuidadeEContinua(): void
    {
        $convenio = $this->convenio(BeneficioDoConvenio::Gratuidade, 0);

        $fim = $this->servico()->mensagem($convenio, new \DateTimeImmutable('2026-11-24'), 'a@x.com', 'Ana');
        $continua = $this->servico()->mensagem($convenio, null, 'a@x.com', 'Ana');

        self::assertStringNotContainsString('corpo_fim_beneficio', $fim->corpoHtml);
        self::assertStringStartsWith('master.convenios_email.assunto_continua', $continua->assunto);
        self::assertStringContainsString('corpo_continua', $continua->corpoHtml);
    }

    #[TestDox('código de convite: 8 letras e números, sem 0, O, 1 e I')]
    public function testCodigo(): void
    {
        $sorteio = new SorteioDeCodigoDeConvite();
        for ($i = 0; $i < 50; ++$i) {
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $sorteio->sortear());
        }
    }

    private function servico(): AvisoDeConvenio
    {
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willReturnCallback(function (MensagemDeEmail $m): ResultadoDoEnvio {
            $this->enviadas[] = $m;

            return new ResultadoDoEnvio(1, ResultadoDoEnvio::ENFILEIRADO);
        });
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id, array $p = []): string => $id . ($p === [] ? '' : '|' . json_encode($p, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));

        return new AvisoDeConvenio($this->createStub(PrimariosDosEscritoriosRepository::class), $carteiro, $tradutor);
    }

    private function convenio(BeneficioDoConvenio $beneficio, int $valor): Convenio
    {
        $dados = new DadosDoConvenio('CAASP', 'Caixa de SP', 'caixa-de-sp', AbrangenciaDoConvenio::Nacional, null, $beneficio, $valor, 'CONVÊNIO CAASP', new \DateTimeImmutable('2026-11-01'), null, null);

        return Convenio::novoPorCodigo('caasp', $dados, 'ABCD2345', null, new \DateTimeImmutable('2026-11-24'));
    }
}
