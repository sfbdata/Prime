<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Zeramento;

use App\Dashboard\DTO\PedirZeramentoInput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\UseCase\PedirZeramentoDoDashboardUseCase;
use App\Dashboard\Zeramento\ColaboradorDoEscritorio;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Profile\Repository\UserProfileRepository;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\Service\RegraDosPrimarios;
use App\Dashboard\Exception\ZeramentoNaoPermitidoException;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** "Pedir para zerar" (DASH-014): avisa os primários (o pedido) e a Controladoria (a ciência); nada muda nos números. */
#[CoversClass(PedirZeramentoDoDashboardUseCase::class)]
final class PedirZeramentoDoDashboardUseCaseTest extends TestCase
{
    private UserTenantRepository&MockObject $vinculos;
    private NotificacaoRepository&MockObject $notificacoes;
    private NotificacaoService&MockObject $servico;
    private Tenant $tenant;
    private PedirZeramentoDoDashboardUseCase $useCase;

    protected function setUp(): void
    {
        $this->vinculos     = $this->createMock(UserTenantRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoRepository::class);
        $this->servico      = $this->createMock(NotificacaoService::class);
        $this->tenant       = new Tenant();
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findColaboradoresAtivosPorTenant')->willReturn([PessoaDeTeste::criar(7, nome: 'Carla Souza')]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p): string => '/dashboard?' . http_build_query($p));

        $this->useCase = new PedirZeramentoDoDashboardUseCase(
            new ColaboradorDoEscritorio($usuarios),
            $this->vinculos,
            $this->notificacoes,
            $this->servico,
            $urls,
            self::tradutorDoCatalogo(),
            $this->createStub(UserProfileRepository::class),
            new RegraDosPrimarios($this->vinculos),
        );
    }

    private function pedido(?int $pessoa = 7): PedirZeramentoInput
    {
        $solicitante = PessoaDeTeste::criar(3, nome: 'Bruna Lima');

        return new PedirZeramentoInput($this->tenant, $solicitante, 'metas_vencidas', $pessoa, new \DateTimeImmutable('2026-10-23 15:40'));
    }

    #[TestDox('Avisa o primário com o pedido e a Controladoria com a ciência; quem é as duas coisas recebe só o pedido')]
    public function testAvisaPrimarioEControladoria(): void
    {
        $primario = PessoaDeTeste::criar(1, nome: 'Ana Prima');
        $controla = PessoaDeTeste::criar(2, nome: 'Davi Contas');
        $this->vinculos->method('findUsuariosAdminsAtivos')->willReturn([$primario]);
        $this->vinculos->method('findUsuariosDaControladoria')->willReturn([$controla, $primario]);
        $this->notificacoes->method('existeDoTipoComUrlNoEscritorioDesde')->willReturn(false);
        $avisos = [];
        $this->servico->expects($this->exactly(2))->method('criarNotificacao')->willReturnCallback(
            static function ($quem, $tenant, $tipo, $titulo, $url, $texto) use (&$avisos): Notificacao {
                $avisos[] = [$quem->getId(), $tipo, $titulo, $url, $texto];

                return new Notificacao();
            },
        );

        $saida = $this->useCase->executar($this->pedido());

        self::assertSame(['avisados' => 2, 'controladoria' => true, 'repetido' => false], $saida->paraArray());
        self::assertSame([1, 2], array_column($avisos, 0));
        self::assertSame('Pedido para zerar Metas vencidas', $avisos[0][2]);
        self::assertStringStartsWith('Ana, Bruna Lima pediu às 15:40 para zerar Metas vencidas de Carla Souza no Painel do Escritório.', $avisos[0][4]);
        self::assertSame('Davi, para sua análise: Bruna Lima pediu para zerar Metas vencidas de Carla Souza no Painel do Escritório. A decisão é do usuário primário; nada foi alterado.', $avisos[1][4]);
        self::assertSame('/dashboard?pedido_zerar=metas_vencidas&alvo=7&de=3', $avisos[0][3]);
        self::assertSame(PedirZeramentoDoDashboardUseCase::TIPO_NOTIFICACAO, $avisos[0][1]);
    }

    #[TestDox('Sem Controladoria no escritório, só o primário é avisado e a saída diz isso; quem pediu não avisa a si')]
    public function testSemControladoria(): void
    {
        $this->vinculos->method('findUsuariosAdminsAtivos')->willReturn([PessoaDeTeste::criar(1), PessoaDeTeste::criar(3)]);
        $this->vinculos->method('findUsuariosDaControladoria')->willReturn([]);
        $this->notificacoes->method('existeDoTipoComUrlNoEscritorioDesde')->willReturn(false);
        $this->servico->expects($this->once())->method('criarNotificacao')->willReturn(new Notificacao());

        $saida = $this->useCase->executar($this->pedido(null));

        self::assertSame(['avisados' => 1, 'controladoria' => false, 'repetido' => false], $saida->paraArray());
    }

    #[TestDox('Teto: o mesmo pedido de novo no dia não avisa e volta repetido')]
    public function testRepetidoNoDia(): void
    {
        $this->vinculos->method('findUsuariosAdminsAtivos')->willReturn([PessoaDeTeste::criar(1)]);
        $this->vinculos->method('findUsuariosDaControladoria')->willReturn([]);
        $this->notificacoes->expects($this->once())->method('existeDoTipoComUrlNoEscritorioDesde')->with(
            $this->tenant,
            PedirZeramentoDoDashboardUseCase::TIPO_NOTIFICACAO,
            '/dashboard?pedido_zerar=metas_vencidas&alvo=7&de=3',
            $this->callback(static fn (\DateTimeImmutable $d): bool => $d->format('Y-m-d H:i') === '2026-10-23 00:00'),
        )->willReturn(true);
        $this->servico->expects($this->never())->method('criarNotificacao');

        self::assertTrue($this->useCase->executar($this->pedido())->repetido);
    }

    #[TestDox('Pessoa fora do escritório da sessão é recusada antes de avisar alguém')]
    public function testPessoaDeOutroEscritorio(): void
    {
        $this->servico->expects($this->never())->method('criarNotificacao');

        $this->expectException(ColaboradorForaDoEscritorioException::class);
        $this->useCase->executar($this->pedido(99));
    }

    #[TestDox('Quem já pode zerar (primário ou Master) não pede: recusa, sem aviso')]
    public function testPrimarioNaoPede(): void
    {
        $papel = (new TenantRole())->setIsSystem(true);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            fn ($u, $t): UserTenant => (new UserTenant($u, $t))->setTenantRole($papel),
        );
        $this->servico->expects($this->never())->method('criarNotificacao');

        $this->expectException(ZeramentoNaoPermitidoException::class);
        $this->useCase->executar($this->pedido());
    }

    private static function tradutorDoCatalogo(): Translator
    {
        $tradutor = new Translator('pt_BR');
        $tradutor->addLoader('yaml', new YamlFileLoader());
        CatalogoPtBr::registrarEm($tradutor);

        return $tradutor;
    }
}
