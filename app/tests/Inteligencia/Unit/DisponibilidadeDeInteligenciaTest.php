<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A ordem dos motivos é o contrato: plataforma → escritório → permissão → cota. A UI mostra o
 * primeiro que falhar, então trocar a ordem muda o que o usuário lê.
 */
#[CoversClass(DisponibilidadeDeInteligencia::class)]
final class DisponibilidadeDeInteligenciaTest extends TestCase
{
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private PermissionChecker&MockObject $permissoes;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    private function sut(ProvedorDeLinguagem $provedor, bool $flag = true): DisponibilidadeDeInteligencia
    {
        return new DisponibilidadeDeInteligencia($provedor, $this->configuracoes, $this->analises, $this->permissoes, $flag);
    }

    private function configuracao(bool $habilitada, int $diario = 50, int $mensal = 500): ConfiguracaoDeInteligencia
    {
        $c = new ConfiguracaoDeInteligencia($this->tenant);
        $c->atualizar($habilitada, $diario, $mensal, true, $this->user);

        return $c;
    }

    #[TestDox('flag da plataforma desligada → não configurada na plataforma, mesmo com tudo o mais em ordem')]
    public function testFlagDesligada(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturn(0);

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorFalso(), flag: false)->para($this->user, $this->tenant));
    }

    #[TestDox('provedor não configurado → não configurada na plataforma (antes de olhar o escritório)')]
    public function testProvedorNaoConfigurado(): void
    {
        $this->configuracoes->expects($this->never())->method('findDoTenant');

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorNaoConfigurado())->para($this->user, $this->tenant));
    }

    #[TestDox('escritório sem configuração → desligada no escritório (antes de olhar a permissão)')]
    public function testSemConfiguracaoDoTenant(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn(null);
        $this->permissoes->expects($this->never())->method('canAccessModule');

        self::assertSame(Disponibilidade::DesligadaNoEscritorio, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('escritório com a IA desligada → desligada no escritório')]
    public function testConfiguracaoDesligada(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(false));

        self::assertSame(Disponibilidade::DesligadaNoEscritorio, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('usuário sem modules.inteligencia.view → sem permissão (antes de contar a cota)')]
    public function testSemPermissao(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true));
        $this->permissoes->method('canAccessModule')->with($this->user, $this->tenant, 'inteligencia')->willReturn(false);
        $this->analises->expects($this->never())->method('contarDesde');

        self::assertSame(Disponibilidade::SemPermissao, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('cota diária atingida → limite atingido')]
    public function testLimiteDiario(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true, diario: 3));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturn(3);

        self::assertSame(Disponibilidade::LimiteAtingido, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('cota mensal atingida → limite atingido (mesmo com folga no dia)')]
    public function testLimiteMensal(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true, diario: 50, mensal: 100));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        // 1ª chamada = hoje, 2ª = mês.
        $this->analises->method('contarDesde')->willReturnOnConsecutiveCalls(2, 100);

        self::assertSame(Disponibilidade::LimiteAtingido, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('tudo em ordem → disponível')]
    public function testDisponivel(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturn(1);

        $resultado = $this->sut(new ProvedorFalso())->para($this->user, $this->tenant);

        self::assertSame(Disponibilidade::Disponivel, $resultado);
        self::assertTrue($resultado->estaDisponivel());
    }

    #[TestDox('cada motivo tem a mensagem da spec para a tela')]
    public function testMensagens(): void
    {
        self::assertSame('IA não configurada nesta instalação', Disponibilidade::NaoConfiguradaNaPlataforma->mensagem());
        self::assertSame('BlueJus IA desligada neste escritório — peça ao administrador', Disponibilidade::DesligadaNoEscritorio->mensagem());
        self::assertSame('Sem permissão para usar a BlueJus IA', Disponibilidade::SemPermissao->mensagem());
        self::assertSame('Limite diário de análises atingido', Disponibilidade::LimiteAtingido->mensagem());
    }
}
