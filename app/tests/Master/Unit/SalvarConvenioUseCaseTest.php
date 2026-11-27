<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\DTO\DadosDoConvenio;
use App\Master\DTO\SalvarConvenioInput;
use App\Master\Entity\Convenio;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\CodigoDeConvenio;
use App\Master\Service\SorteioDeCodigoDeConvite;
use App\Master\UseCase\SalvarConvenioUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Código de convite escolhido pelo Master (spec docs/specs/convenios.md, §5.3 e §7.3). */
#[CoversClass(SalvarConvenioUseCase::class)]
#[CoversClass(CodigoDeConvenio::class)]
#[CoversClass(SalvarConvenioInput::class)]
final class SalvarConvenioUseCaseTest extends TestCase
{
    private \DateTimeImmutable $hoje;

    /** @var list<string> */
    private array $codigosEmUso = [];

    private ?Convenio $existente = null;

    protected function setUp(): void
    {
        $this->hoje = new \DateTimeImmutable('2026-11-27');
    }

    #[TestDox('código informado vale como está, no formato da conta (OABDF2026)')]
    public function testCodigoInformado(): void
    {
        $r = $this->executar($this->input(codigo: 'OABDF2026'));

        self::assertTrue($r['criado']);
        self::assertSame('OABDF2026', $r['convenio']->getCodigoConvite());
    }

    #[TestDox('normaliza como a conta: minúsculas, espaços e hífen')]
    public function testNormaliza(): void
    {
        self::assertSame('OABDF2026', $this->executar($this->input(codigo: '  oabdf-2026 '))['convenio']->getCodigoConvite());
        self::assertSame('OABDF2026', $this->executar($this->input(codigo: 'oab df 2026'))['convenio']->getCodigoConvite());
    }

    /** @return iterable<string, array{string}> */
    public static function formatosInvalidos(): iterable
    {
        yield 'curto demais' => ['AB1'];
        yield 'longo demais' => ['ABCDEFGHIJKLMNOPQ'];
        yield 'símbolo' => ['OAB@2026'];
        yield 'acento' => ['OABÇ2026'];
    }

    #[TestDox('formato inválido é recusado')]
    #[\PHPUnit\Framework\Attributes\DataProvider('formatosInvalidos')]
    public function testFormatoInvalido(string $codigo): void
    {
        $this->expectException(ConvenioRecusado::class);
        $this->expectExceptionMessage('de 4 a 16 letras ou números');

        $this->executar($this->input(codigo: $codigo));
    }

    #[TestDox('código de outro convênio é recusado, mesmo com outra caixa')]
    public function testDuplicado(): void
    {
        $this->codigosEmUso = ['OABDF2026'];

        $this->expectException(ConvenioRecusado::class);
        $this->expectExceptionMessage('já é de outro convênio');

        $this->executar($this->input(codigo: 'oabdf2026'));
    }

    #[TestDox('vazio ou só espaços sorteia: 8 caracteres do alfabeto do sorteio')]
    public function testVazioSorteia(): void
    {
        foreach ([null, '', '   '] as $vazio) {
            $codigo = $this->executar($this->input(codigo: $vazio))['convenio']->getCodigoConvite();
            self::assertMatchesRegularExpression('/^[' . SorteioDeCodigoDeConvite::ALFABETO . ']{8}$/', (string) $codigo);
        }
    }

    #[TestDox('na edição, digitar outro código troca; o código antigo some')]
    public function testTrocaNaEdicao(): void
    {
        $this->existente = $this->convenioExistente('ANTIGO22');

        $r = $this->executar($this->input(id: 7, codigo: 'novo2027'));

        self::assertFalse($r['criado']);
        self::assertSame('NOVO2027', $r['convenio']->getCodigoConvite());
    }

    #[TestDox('na edição, repetir o código que o próprio convênio já tem não é duplicado')]
    public function testMesmoCodigoNaEdicao(): void
    {
        $this->existente = $this->convenioExistente('ANTIGO22');
        $this->codigosEmUso = ['ANTIGO22'];

        $r = $this->executar($this->input(id: 7, codigo: 'antigo22'));

        self::assertSame('ANTIGO22', $r['convenio']->getCodigoConvite());
    }

    #[TestDox('na edição, sem código mantém; com "gerar outro" sorteia; código informado ganha de "gerar outro"')]
    public function testMantemSorteiaOuEscolhe(): void
    {
        $this->existente = $this->convenioExistente('ANTIGO22');
        self::assertSame('ANTIGO22', $this->executar($this->input(id: 7))['convenio']->getCodigoConvite());

        $sorteado = $this->executar($this->input(id: 7, gerarOutro: true))['convenio']->getCodigoConvite();
        self::assertNotSame('ANTIGO22', $sorteado);
        self::assertMatchesRegularExpression('/^[' . SorteioDeCodigoDeConvite::ALFABETO . ']{8}$/', (string) $sorteado);

        self::assertSame('ESCOLHI99', $this->executar($this->input(id: 7, gerarOutro: true, codigo: 'escolhi99'))['convenio']->getCodigoConvite());
    }

    #[TestDox('o corpo JSON leva o código ao pedido; vazio vira nulo')]
    public function testDoJson(): void
    {
        self::assertSame('abc', SalvarConvenioInput::doJson(null, ['codigo' => ' abc '])->codigo);
        self::assertNull(SalvarConvenioInput::doJson(null, ['codigo' => '  '])->codigo);
        self::assertNull(SalvarConvenioInput::doJson(null, [])->codigo);
    }

    /** @return array{convenio: Convenio, criado: bool} */
    private function executar(SalvarConvenioInput $input): array
    {
        $convenios = $this->createStub(ConvenioRepository::class);
        $convenios->method('existeChave')->willReturn(false);
        $convenios->method('existeCodigo')->willReturnCallback(fn (string $c): bool => \in_array($c, $this->codigosEmUso, true));
        $convenios->method('find')->willReturnCallback(fn (): ?Convenio => $this->existente);
        $vinculos = $this->createStub(ConvenioVinculoRepository::class);
        $vinculos->method('abertosDosConvenios')->willReturn([]);

        $useCase = new SalvarConvenioUseCase(
            $convenios,
            $vinculos,
            new SorteioDeCodigoDeConvite(),
            new AsciiSlugger(),
            new EscopoDeAuditoriaDePlataforma(),
            new MockClock($this->hoje),
        );

        return $useCase->executar($input);
    }

    private function input(?int $id = null, ?string $codigo = null, bool $gerarOutro = false): SalvarConvenioInput
    {
        return new SalvarConvenioInput(null, $id, 'OAB DF', 'OAB do Distrito Federal', 'nacional', null, 'gratuidade', null, 'CONVÊNIO OAB DF', '2026-11-27', null, null, $gerarOutro, $codigo);
    }

    private function convenioExistente(string $codigo): Convenio
    {
        $dados = new DadosDoConvenio('OAB DF', 'OAB do Distrito Federal', 'oab-do-distrito-federal', AbrangenciaDoConvenio::Nacional, null, BeneficioDoConvenio::Gratuidade, 0, 'CONVÊNIO OAB DF', $this->hoje, null, null);

        return Convenio::novoPorCodigo('oab-df', $dados, $codigo, null, $this->hoje);
    }
}
