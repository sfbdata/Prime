<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Indicacao\Entity\IndicacaoConfig;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Repository\IndicacaoPausaRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\PortaoDaIndicacao;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Cadastro completo e travas (spec indicar-bluejus.md, comportamentos 2 e 3), com os textos do desenho. */
#[CoversClass(PortaoDaIndicacao::class)]
final class PortaoDaIndicacaoTest extends TestCase
{
    private const AGORA = '2026-10-19 15:00:00';

    private IndicacaoConfig $config;
    private bool $pausado = false;
    private int $em24h = 0;
    private int $em30d = 0;
    /** @var list<string> */
    private array $janelas = [];

    protected function setUp(): void
    {
        $this->config = new IndicacaoConfig();
    }

    private function portao(): PortaoDaIndicacao
    {
        $configs = $this->createStub(IndicacaoConfigRepository::class);
        $configs->method('obter')->willReturn($this->config);
        $pausas = $this->createStub(IndicacaoPausaRepository::class);
        $pausas->method('estaPausado')->willReturnCallback(fn (): bool => $this->pausado);
        $indicacoes = $this->createStub(IndicacaoRepository::class);
        $agora = new \DateTimeImmutable(self::AGORA);
        $indicacoes->method('contarDoUsuarioDesde')->willReturnCallback(function (int $id, \DateTimeImmutable $desde) use ($agora): int {
            $this->janelas[] = $desde->format('Y-m-d H:i:s');

            return $desde->getTimestamp() === $agora->modify('-24 hours')->getTimestamp() ? $this->em24h : $this->em30d;
        });

        return new PortaoDaIndicacao($configs, $pausas, $indicacoes, new MockClock($agora));
    }

    /** @return iterable<string, array{string, ?string, list<string>}> */
    public static function cadastros(): iterable
    {
        yield 'completo' => ['Ana Souza', '123.456.789-09', []];
        yield 'nome de uma palavra' => ['Ana', '123.456.789-09', ['nome completo']];
        yield 'nome com espaços sobrando' => ['  Ana   ', '123', ['nome completo']];
        yield 'sem perfil' => ['Ana Souza', null, ['CPF']];
        yield 'CPF em branco' => ['Ana Souza', '  ', ['CPF']];
        yield 'falta tudo' => ['Ana', null, ['nome completo', 'CPF']];
    }

    /** @param list<string> $esperado */
    #[DataProvider('cadastros')]
    #[TestDox('pendências do cadastro: $_dataName')]
    public function testPendenciasDoCadastro(string $nome, ?string $cpf, array $esperado): void
    {
        self::assertSame($esperado, $this->portao()->pendenciasDoCadastro(Pessoas::usuario(1, $nome, $cpf)));
    }

    #[TestDox('com o perfil sem telefone, o telefone entra nas pendências (campo do Meu Perfil, desenho 07)')]
    public function testTelefoneObrigatorio(): void
    {
        $user = Pessoas::usuario(1);
        $user->getProfile()?->setTelefone(null);

        self::assertSame([PortaoDaIndicacao::TELEFONE], $this->portao()->pendenciasDoCadastro($user));
    }

    #[TestDox('o texto das pendências junta a lista com vírgula, como no desenho')]
    public function testTextoDasPendencias(): void
    {
        self::assertNull(PortaoDaIndicacao::textoDasPendencias([]));
        self::assertSame(
            'Por segurança, o convite sempre leva o seu nome, então precisamos do seu nome completo, CPF.',
            PortaoDaIndicacao::textoDasPendencias(['nome completo', 'CPF']),
        );
    }

    #[TestDox('sem pausa e abaixo dos limites, não há trava')]
    public function testSemTrava(): void
    {
        $this->em24h = 19;
        $this->em30d = 199;

        self::assertNull($this->portao()->motivoDaTrava(Pessoas::usuario(1)));
        self::assertSame(['2026-10-18 15:00:00', '2026-09-19 15:00:00'], $this->janelas, 'janelas móveis de 24h e de 30 dias');
    }

    #[TestDox('ordem das travas: a pausa global vence a pausa da pessoa e os limites')]
    public function testPausaGlobalPrimeiro(): void
    {
        $this->config->pausarParaTodos(true, Pessoas::master(), new \DateTimeImmutable());
        $this->pausado = true;
        $this->em24h = 99;

        self::assertSame('Os convites estão pausados pela administração do BlueJus no momento.', $this->portao()->motivoDaTrava(Pessoas::usuario(1)));
    }

    #[TestDox('ordem das travas: a pausa da pessoa vem antes dos limites')]
    public function testPausaDaPessoaDepois(): void
    {
        $this->pausado = true;
        $this->em24h = 99;

        self::assertSame('Seus envios de convite foram pausados pela administração do BlueJus.', $this->portao()->motivoDaTrava(Pessoas::usuario(1)));
    }

    #[TestDox('limite de 24h: chega no número configurado e trava com o texto do dia')]
    public function testLimiteDoDia(): void
    {
        $this->em24h = 20;
        $this->em30d = 500;

        self::assertSame('Você chegou ao limite de 20 convites por dia. Tente novamente amanhã.', $this->portao()->motivoDaTrava(Pessoas::usuario(1)));
    }

    #[TestDox('limite de 30 dias: trava com o texto do mês e o número configurado')]
    public function testLimiteDoMes(): void
    {
        $this->config->alterarLimites(20, 150, 30, Pessoas::master(), new \DateTimeImmutable());
        $this->em24h = 3;
        $this->em30d = 150;

        self::assertSame('Você chegou ao limite de 150 convites por mês.', $this->portao()->motivoDaTrava(Pessoas::usuario(1)));
    }
}
