<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\QuemAcompanhaAPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A decisão em memória de {@see QuemAcompanhaAPasta}: o recorte do banco (vínculo ativo, usuário
 * ativo, quem desligou) vem do repositório e é provado com o banco no `PastaAcompanhamentoAvisoTest`.
 */
#[CoversClass(QuemAcompanhaAPasta::class)]
final class QuemAcompanhaAPastaTest extends TestCase
{
    private PastaAcompanhamentoDesligadoRepository&MockObject $desligados;
    private PermissionChecker&MockObject $permissionChecker;
    private AbaOcultaDaPastaRepository&MockObject $regrasDeAba;
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->desligados        = $this->createMock(PastaAcompanhamentoDesligadoRepository::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->regrasDeAba       = $this->createMock(AbaOcultaDaPastaRepository::class);

        $this->tenant = $this->comId(new Tenant(), 7);
        $this->pasta  = $this->comId(new Pasta(), 41);
        $this->pasta->setTenant($this->tenant);
    }

    private function servico(): QuemAcompanhaAPasta
    {
        $vinculos  = $this->createStub(UserTenantRepository::class);
        $primarios = new RegraDosPrimarios($vinculos);

        return new QuemAcompanhaAPasta(
            $this->desligados,
            $this->permissionChecker,
            new VisibilidadeDasAbasDaPasta($this->regrasDeAba, $primarios, new EquipeQuePodeTerAbaOculta($vinculos, $primarios)),
        );
    }

    private function pessoa(int $id): User
    {
        return $this->comId((new User())->setEmail('p' . $id . '@test.com'), $id);
    }

    /** @var array<int, UserTenant> userId => o vínculo que o repositório devolveu */
    private array $vinculos = [];

    /** @param array<int, bool> $acesso userId => vê a pasta 41 */
    private function acesso(array $acesso): void
    {
        // A checagem usa o vínculo que veio na consulta dos candidatos: a mesma instância, sem buscar de novo.
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->permissionChecker->method('canAccessResourceComVinculo')->willReturnCallback(
            function (User $u, UserTenant $v, string $tipo, int $id, string $acao) use ($acesso): bool {
                self::assertSame($this->vinculos[(int) $u->getId()], $v);
                self::assertSame([AccessRequest::RESOURCE_PASTA, 41, AccessRequest::ACTION_VIEW], [$tipo, $id, $acao]);

                return $acesso[(int) $u->getId()] ?? false;
            },
        );
    }

    /** @param list<User> $pessoas */
    private function candidatos(array $pessoas): void
    {
        $this->vinculos = [];
        foreach ($pessoas as $pessoa) {
            $this->vinculos[(int) $pessoa->getId()] = new UserTenant($pessoa, $this->tenant);
        }
        $this->desligados->method('vinculosQueNaoDesligaram')
            ->with($this->identicalTo($this->pasta), $this->identicalTo($this->tenant))
            ->willReturn(array_values($this->vinculos));
    }

    /**
     * @param list<User> $pessoas
     *
     * @return list<int>
     */
    private static function ids(array $pessoas): array
    {
        return array_map(static fn (User $u): int => (int) $u->getId(), $pessoas);
    }

    #[TestDox('Padrão ligado: todo colega que não desligou e vê a pasta recebe')]
    public function testPadraoTodosQueVeem(): void
    {
        $this->candidatos([$this->pessoa(2), $this->pessoa(3)]);
        $this->acesso([2 => true, 3 => true]);

        self::assertSame([2, 3], self::ids($this->servico()->destinatarios($this->pasta, $this->tenant, [])));
    }

    #[TestDox('Quem desligou não está entre os candidatos do repositório e não recebe')]
    public function testDesligadoFora(): void
    {
        // O repositório já tira quem desligou: só o 3 volta.
        $this->candidatos([$this->pessoa(3)]);
        $this->acesso([2 => true, 3 => true]);

        self::assertSame([3], self::ids($this->servico()->destinatarios($this->pasta, $this->tenant, [])));
    }

    #[TestDox('Quem não vê a pasta (permissão ou cadeado, pela mesma checagem da pasta_show) fica de fora')]
    public function testSemAcessoFora(): void
    {
        $this->candidatos([$this->pessoa(2), $this->pessoa(3)]);
        $this->acesso([2 => false, 3 => true]);

        self::assertSame([3], self::ids($this->servico()->destinatarios($this->pasta, $this->tenant, [])));
    }

    #[TestDox('Quem tem a aba "Dados da pasta" (o Registro) oculta fica de fora')]
    public function testAbaDadosOcultaFora(): void
    {
        $dois = $this->pessoa(2);
        $this->candidatos([$dois, $this->pessoa(3)]);
        $this->acesso([2 => true, 3 => true]);
        $regra = AbaOcultaDaPasta::paraAlguns($this->tenant, $this->pasta, AbaDaPasta::Dados, [2], $this->pessoa(9), new \DateTimeImmutable());
        // A regra da aba é lida UMA vez para todos os candidatos, não uma por pessoa.
        $this->regrasDeAba->expects(self::once())->method('daAba')
            ->willReturnCallback(static fn (Pasta $p, AbaDaPasta $aba): ?AbaOcultaDaPasta => $aba === AbaDaPasta::Dados ? $regra : null);

        self::assertSame([3], self::ids($this->servico()->destinatarios($this->pasta, $this->tenant, [])));
    }

    #[TestDox('O autor, o dono do comentário respondido e os mencionados ficam de fora (eles já têm o aviso próprio)')]
    public function testAutorMencionadoERespondidoFora(): void
    {
        $autor      = $this->pessoa(1);
        $respondido = $this->pessoa(2);
        $mencionado = $this->pessoa(3);
        $terceiro   = $this->pessoa(4);
        $this->candidatos([$autor, $respondido, $mencionado, $terceiro]);
        $this->acesso([1 => true, 2 => true, 3 => true, 4 => true]);

        $destinatarios = $this->servico()->destinatarios($this->pasta, $this->tenant, [$autor, $respondido, null, $mencionado]);

        self::assertSame([4], self::ids($destinatarios));
    }

    #[TestDox('Pasta de outro escritório: ninguém, e nem consulta o banco')]
    public function testPastaDeOutroEscritorio(): void
    {
        $this->desligados->expects(self::never())->method('vinculosQueNaoDesligaram');

        self::assertSame([], $this->servico()->destinatarios($this->pasta, $this->comId(new Tenant(), 8), []));
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
