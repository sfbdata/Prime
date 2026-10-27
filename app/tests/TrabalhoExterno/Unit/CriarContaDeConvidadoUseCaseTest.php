<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Service\PoliticaDeCadastro;
use App\Profile\Enum\Genero;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use App\Repository\UserRepository;
use App\Tenant\Service\SituacaoDaConta;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Service\ConviteDisponivel;
use App\TrabalhoExterno\UseCase\CriarContaDeConvidadoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** O gênero na conta criada pelo convite do Trabalho Externo (spec genero-no-cadastro, regra 1). */
#[CoversClass(CriarContaDeConvidadoUseCase::class)]
final class CriarContaDeConvidadoUseCaseTest extends TestCase
{
    private const TOKEN = 'token-do-convite';

    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $usuarios;

    private ConfiguracaoDaPlataformaFalsa $configuracao;

    protected function setUp(): void
    {
        $this->configuracao = new ConfiguracaoDaPlataformaFalsa(['cadastro' => 'on']);
        $this->em       = $this->createMock(EntityManagerInterface::class);
        $this->usuarios = $this->createMock(UserRepository::class);
        $this->usuarios->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
    }

    #[TestDox('Cria a conta com o perfil e o gênero escolhido, persistido em cascata com o User')]
    public function testGravaOGenero(): void
    {
        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(static function (object $o) use (&$persistidos): void {
            $persistidos[] = $o;
        });

        $usuario = $this->useCase()->executar(self::TOKEN, 'Nova Correspondente', 'senha-forte-1', true, '10.0.0.1', null, 'feminino');

        self::assertSame(Genero::Feminino, $usuario->getProfile()?->getGenero());
        self::assertSame($usuario, $usuario->getProfile()?->getUser());
        self::assertSame([$usuario], $persistidos, 'o perfil vai em cascata, sem persist próprio');
    }

    #[TestDox('Cadastro só por convite: o convite do Trabalho Externo ainda cria a conta')]
    public function testPoliticaConviteDeixaCriarConta(): void
    {
        $this->configuracao->definir('cadastro', 'convite');

        $usuario = $this->useCase()->executar(self::TOKEN, 'Nova Correspondente', 'senha-forte-1', true, '10.0.0.1', null, 'feminino');

        self::assertSame('nova@test.com', $usuario->getEmail());
    }

    #[TestDox('Cadastro fechado: o convite do Trabalho Externo não cria conta nova e nada é gravado')]
    public function testPoliticaOffBloqueia(): void
    {
        $this->configuracao->definir('cadastro', 'off');
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(CadastroNaoDisponivel::class);

        $this->useCase()->executar(self::TOKEN, 'Nova Correspondente', 'senha-forte-1', true, '10.0.0.1', null, 'feminino');
    }

    /** @return iterable<string, array{string}> */
    public static function generosRecusados(): iterable
    {
        yield 'vazio' => [''];
        yield 'fora das opções' => ['outro'];
        yield 'legado "prefiro não informar"' => ['nao_informado'];
    }

    #[DataProvider('generosRecusados')]
    #[TestDox('Sem gênero válido a conta não nasce: "Informe o gênero."')]
    public function testRecusaSemGenero(string $genero): void
    {
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(DadosInvalidos::class);
        $this->expectExceptionMessage('Informe o gênero.');

        $this->useCase()->executar(self::TOKEN, 'Nova Correspondente', 'senha-forte-1', true, null, null, $genero);
    }

    private function useCase(): CriarContaDeConvidadoUseCase
    {
        $convites = $this->createStub(ConviteDemandaRepository::class);
        $convites->method('peloToken')->willReturn($this->convite());
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hash');
        $aceites = $this->createStub(AceiteTermoRepository::class);
        $aceites->method('existeAceiteVigente')->willReturn(true);

        return new CriarContaDeConvidadoUseCase(
            $convites,
            $this->usuarios,
            $hasher,
            new RegistrarAceiteTermoUseCase($aceites, new TermoVigente()),
            $this->em,
            new ConviteDisponivel(new SituacaoDaConta()),
            new PoliticaDeCadastro($this->configuracao),
        );
    }

    private function convite(): ConviteDemanda
    {
        return new ConviteDemanda(
            Apoio::demanda(Situacao::Execucao),
            'Nova Correspondente',
            'nova@test.com',
            null,
            new \DateTimeImmutable('+7 days'),
            ConviteDemanda::hashDoToken(self::TOKEN),
            ['ver' => true],
            Apoio::autor(Perfil::Solicitante),
        );
    }
}
