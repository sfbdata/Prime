<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\DTO\FuncaoDoPontoOutput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\UseCase\ObterConfiguracoesDoPontoUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * O conteúdo da seção das funções da aba Configurações (E7, P67): só para quem administra o ponto do escritório; o
 * Master ganha a chave Global; travada mostra a do escritório desligada sem perder o valor guardado.
 */
#[CoversClass(ObterConfiguracoesDoPontoUseCase::class)]
#[CoversClass(FuncaoDoPontoOutput::class)]
#[Group('ponto')]
final class ObterConfiguracoesDoPontoUseCaseTest extends TestCase
{
    #[TestDox('Sem a permissão de administrar o ponto: null (a seção não aparece)')]
    public function testSemPermissao(): void
    {
        self::assertNull($this->casoDeUso(false, null, true)->executar(new User(), new Tenant()));
    }

    #[TestDox('Sem linha e sem registro do Master: a geolocalização aparece ligada nas duas camadas')]
    public function testNasceLigada(): void
    {
        $saida = $this->casoDeUso(true, null, true)->executar($this->pessoa(['ROLE_USER']), new Tenant());

        self::assertNotNull($saida);
        self::assertFalse($saida->ehMaster);
        self::assertCount(1, $saida->funcoes);
        $geo = $saida->funcoes[0];
        self::assertSame(['ponto-geo', 'Geolocalização', 'bi-geo-alt', 'Exige que o registro seja feito dentro do raio de uma sede.'], [$geo->chave, $geo->titulo, $geo->icone, $geo->ajuda]);
        self::assertTrue($geo->global);
        self::assertTrue($geo->escritorio);
        self::assertFalse($geo->travada());
        self::assertTrue($geo->escritorioEfetivo());
    }

    #[TestDox('Global desligado: travada, a do escritório aparece desligada e o valor guardado continua')]
    public function testGlobalDesligadoTrava(): void
    {
        $tenant = new Tenant();
        $configuracao = new ConfiguracaoDoPonto($tenant);
        $configuracao->definir('ponto-geo', true, new User(), new \DateTimeImmutable());

        $geo = $this->casoDeUso(true, $configuracao, false)->executar($this->pessoa(['ROLE_SUPER_ADMIN']), $tenant)?->funcoes[0];

        self::assertNotNull($geo);
        self::assertFalse($geo->global);
        self::assertTrue($geo->escritorio, 'o valor guardado');
        self::assertTrue($geo->travada());
        self::assertFalse($geo->escritorioEfetivo());
    }

    #[TestDox('O Master é marcado para ver a chave Global')]
    public function testMaster(): void
    {
        self::assertTrue($this->casoDeUso(true, null, true)->executar($this->pessoa(['ROLE_SUPER_ADMIN']), new Tenant())?->ehMaster);
    }

    /** @param list<string> $papeis */
    private function pessoa(array $papeis): User
    {
        $user = new User();
        $user->setRoles($papeis);

        return $user;
    }

    private function casoDeUso(bool $podeConfigurar, ?ConfiguracaoDoPonto $configuracao, bool $globalLigado): ObterConfiguracoesDoPontoUseCase
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $gravados = [];
        if (!$globalLigado) {
            $gravados['ponto-geo'] = new RecursoEstado('ponto-geo');
            $gravados['ponto-geo']->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        }
        $estados->method('todosPorChave')->willReturn($gravados);
        $regra = new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class));
        $recursos = new RecursosDoSistema($estados, new MockClock('2026-11-09 10:00:00'), $this->createStub(Security::class), $regra, new RequestStack(), new NullLogger());
        $configuracoes = $this->createStub(ConfiguracaoDoPontoRepository::class);
        $configuracoes->method('doTenant')->willReturn($configuracao);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAdminister')->willReturn($podeConfigurar);

        return new ObterConfiguracoesDoPontoUseCase(new FuncoesDoPonto($recursos, $configuracoes, $permissoes), $configuracoes, $regra);
    }
}
