<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\AbaOculta;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Clock\MockClock;

/**
 * Cenário do "ocultar aba" sem banco: um escritório com um primário, um Master, dois colaboradores
 * comuns e um vínculo desativado; os repositórios do domínio são dublês. A `RegraDosPrimarios` é a
 * de verdade (só depende do repositório de vínculos), para o teste provar a regra real de quem configura.
 */
trait CriaCenarioDeAbaOcultaTrait
{
    private const AGORA = '2026-10-19 10:00:00';

    private Tenant $tenant;
    private Pasta $pasta;
    private User $primario;
    private User $master;
    private User $ana;
    private User $beto;
    private User $desligado;
    /** @var list<UserTenant> */
    private array $vinculosAtivos;
    private AbaOcultaDaPastaRepository&MockObject $regras;
    private UserTenantRepository&MockObject $vinculos;

    private function montarCenario(): void
    {
        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório Teste');
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($this->pasta, 42);

        $this->primario = $this->usuario(1, 'Paula Primária');
        $this->master = $this->usuario(2, 'Mário Master', ['ROLE_SUPER_ADMIN']);
        $this->ana = $this->usuario(3, 'Ana Advogada');
        $this->beto = $this->usuario(4, 'Beto Estagiário');
        $this->desligado = $this->usuario(5, 'Davi Desligado');

        $sistema = $this->perfil(true);
        $comum = $this->perfil(false);
        $vPrimario = $this->vinculo($this->primario, $sistema);
        $vMaster = $this->vinculo($this->master, $comum);
        $vAna = $this->vinculo($this->ana, $comum, 'Advogada', 'Contencioso');
        $vBeto = $this->vinculo($this->beto, $comum, 'Estagiário');
        $this->vinculosAtivos = [$vAna, $vBeto, $vMaster, $vPrimario];

        $this->regras = $this->createMock(AbaOcultaDaPastaRepository::class);
        // A trava de verdade é do banco (teste funcional); aqui a operação só roda.
        $this->regras->method('comTravaDaPasta')->willReturnCallback(static fn (Pasta $p, callable $operacao): mixed => $operacao());
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->vinculos->method('findAtivosComCargoELotacaoPorTenant')->willReturn($this->vinculosAtivos);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            fn (User $u): ?UserTenant => array_values(array_filter($this->vinculosAtivos, static fn (UserTenant $v): bool => $v->getUser() === $u))[0] ?? null,
        );
    }

    /** @param list<string> $roles */
    private function usuario(int $id, string $nome, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setFullName($nome);
        $user->setEmail('u' . $id . '@escritorio.test');
        $user->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function perfil(bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName($sistema ? 'Primário' : 'Colaborador');
        $perfil->setIsSystem($sistema);

        return $perfil;
    }

    private function vinculo(User $user, TenantRole $perfil, ?string $cargo = null, ?string $lotacao = null): UserTenant
    {
        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($perfil);
        if ($cargo !== null) {
            $vinculo->setCargo((new Cargo())->setNome($cargo));
        }
        if ($lotacao !== null) {
            $vinculo->setLotacao((new Lotacao())->setNome($lotacao));
        }

        return $vinculo;
    }

    private function regraPrimarios(): RegraDosPrimarios
    {
        return new RegraDosPrimarios($this->vinculos);
    }

    private function visibilidade(): VisibilidadeDasAbasDaPasta
    {
        return new VisibilidadeDasAbasDaPasta($this->regras, $this->regraPrimarios(), $this->equipe());
    }

    private function equipe(): EquipeQuePodeTerAbaOculta
    {
        return new EquipeQuePodeTerAbaOculta($this->vinculos, $this->regraPrimarios());
    }

    private function relogio(): MockClock
    {
        return new MockClock(self::AGORA);
    }

    private function regraParaTodos(AbaDaPasta $aba): AbaOcultaDaPasta
    {
        return AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, $aba, $this->primario, new \DateTimeImmutable(self::AGORA));
    }

    /** @param list<int> $ids */
    private function regraPara(AbaDaPasta $aba, array $ids): AbaOcultaDaPasta
    {
        return AbaOcultaDaPasta::paraAlguns($this->tenant, $this->pasta, $aba, $ids, $this->primario, new \DateTimeImmutable(self::AGORA));
    }
}
