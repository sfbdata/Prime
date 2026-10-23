<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Service\AvisoDeDisparoAlto;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O aviso do sino cai numa CONTA DO MASTER (criador Master, ou sem criador registrado), no vínculo mais antigo, e
 * nunca no escritório de um cliente (a Notificacao é auditada e o cliente lê o audit_log do escritório dele).
 */
#[CoversClass(AvisoDeDisparoAlto::class)]
final class AvisoDeDisparoAltoTest extends TestCase
{
    /** @var list<array{User, Tenant, ?string}> */
    private array $criadas = [];

    /** @var list<Notificacao> */
    private array $notificacoes = [];

    /** @param list<UserTenant> $vinculos */
    private function aviso(User $master, array $vinculos): AvisoDeDisparoAlto
    {
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findComPapelGlobal')->willReturn([$master]);
        $repoVinculos = $this->createStub(UserTenantRepository::class);
        $repoVinculos->method('findActiveByUser')->willReturn($vinculos);
        $notificacoes = $this->createStub(NotificacaoService::class);
        $notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t): Notificacao {
            $n = new Notificacao();
            $this->criadas[] = [$u, $t, null];
            $this->notificacoes[] = $n;

            return $n;
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/master');

        return new AvisoDeDisparoAlto($usuarios, $repoVinculos, $notificacoes, $urls);
    }

    private static function vinculo(int $id, User $user, Tenant $tenant): UserTenant
    {
        $v = new UserTenant($user, $tenant);
        (new \ReflectionProperty($v, 'id'))->setValue($v, $id);

        return $v;
    }

    #[TestDox('escritório criado por cliente não recebe o aviso; a conta do Master (vínculo mais antigo) recebe')]
    public function testSoNaContaDoMaster(): void
    {
        $master = Pessoas::master();
        $cliente = Pessoas::usuario(5);
        $doCliente = Pessoas::escritorio(10, 'Do Cliente', $cliente);
        $doMasterNovo = Pessoas::escritorio(11, 'Do Master novo', $master);
        $doMasterAntigo = Pessoas::escritorio(12, 'Do Master antigo', $master);

        $this->aviso($master, [self::vinculo(1, $master, $doCliente), self::vinculo(9, $master, $doMasterNovo), self::vinculo(3, $master, $doMasterAntigo)])->avisar('texto');

        self::assertCount(1, $this->criadas);
        self::assertSame($doMasterAntigo, $this->criadas[0][1]);
    }

    #[TestDox('o aviso leva o Master direto à seção Indicações do BlueJus do painel')]
    public function testLinkDaSecaoDeIndicacoes(): void
    {
        $master = Pessoas::master();
        $doMaster = Pessoas::escritorio(12, 'Do Master', $master);

        $this->aviso($master, [self::vinculo(3, $master, $doMaster)])->avisar('texto');

        self::assertSame('/master#aba=painel&sec=ind', $this->notificacoes[0]->getUrl());
    }

    #[TestDox('escritório sem criador registrado com vínculo ativo do Master conta como dele')]
    public function testSemCriadorValeComoDoMaster(): void
    {
        $master = Pessoas::master();
        $antigo = Pessoas::escritorio(10, 'Antigo', null);

        $this->aviso($master, [self::vinculo(1, $master, $antigo)])->avisar('texto');

        self::assertSame($antigo, $this->criadas[0][1] ?? null);
    }

    #[TestDox('Master só com escritórios de clientes não recebe no sino')]
    public function testSemContaDoMasterNaoAvisa(): void
    {
        $master = Pessoas::master();

        $this->aviso($master, [self::vinculo(1, $master, Pessoas::escritorio(10, 'Do Cliente', Pessoas::usuario(5)))])->avisar('texto');

        self::assertSame([], $this->criadas);
    }
}
