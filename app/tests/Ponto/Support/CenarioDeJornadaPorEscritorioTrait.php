<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Support;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Cenário da jornada por escritório (spec seguranca-b-jornada): escritórios, pessoas, vínculos com a chave do ponto,
 * jornadas pessoal e padrão em blocos, batidas e chamadas JSON com o CSRF falso.
 *
 * Bloco = [dias, entrada, repouso, retorno, saída], com repouso e retorno nulos para jornada corrida.
 */
trait CenarioDeJornadaPorEscritorioTrait
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function pessoa(string $nome, array $papeis = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail('jornada_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * Vínculo ativo com um papel que tem só as permissões pedidas; `$primario` dá o papel de sistema (passa em tudo).
     *
     * @param list<string> $codigos
     */
    private function vincular(User $user, Tenant $tenant, array $codigos = ['modules.ponto.view'], bool $primario = false, bool $registraPonto = true): UserTenant
    {
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem($primario);
        $this->em()->persist($papel);
        foreach ($codigos as $codigo) {
            $this->darPermissao($papel, $codigo);
        }
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $vinculo->alterarRegistroDePonto($registraPonto);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $vinculo;
    }

    private function darPermissao(TenantRole $papel, string $codigo): void
    {
        $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setDescription($codigo);
            $permissao->setGroup(explode('.', $codigo)[0]);
            $this->em()->persist($permissao);
        }
        $ligacao = new TenantRolePermission();
        $ligacao->setTenantRole($papel);
        $ligacao->setPermission($permissao);
        $this->em()->persist($ligacao);
        $papel->getTenantRolePermissions()->add($ligacao);
    }

    /** @param list<array{0: list<int>, 1: string, 2: ?string, 3: ?string, 4: string}> $blocos */
    private function jornadaPessoal(User $user, Tenant $tenant, array $blocos): JornadaColaborador
    {
        $jornada = (new JornadaColaborador())->setUser($user)->setTenant($tenant);
        foreach ($blocos as [$dias, $entrada, $repouso, $retorno, $saida]) {
            $bloco = (new BlocoJornadaColaborador())->setDiasSemana($dias)->setEntrada($entrada)->setRepouso($repouso)
                ->setRetorno($retorno)->setSaida($saida)->setMinutosBloco($this->minutos($entrada, $repouso, $retorno, $saida));
            $jornada->addBloco($bloco);
        }
        $this->em()->persist($jornada);
        $this->em()->flush();

        return $jornada;
    }

    /** @param list<array{0: list<int>, 1: string, 2: ?string, 3: ?string, 4: string}> $blocos */
    private function jornadaPadrao(Tenant $tenant, array $blocos): JornadaTenant
    {
        $jornada = (new JornadaTenant())->setTenant($tenant);
        foreach ($blocos as [$dias, $entrada, $repouso, $retorno, $saida]) {
            $bloco = (new BlocoJornada())->setDiasSemana($dias)->setEntrada($entrada)->setRepouso($repouso)
                ->setRetorno($retorno)->setSaida($saida)->setMinutosBloco($this->minutos($entrada, $repouso, $retorno, $saida));
            $jornada->addBloco($bloco);
        }
        $tenant->setJornadaTenant($jornada);
        $this->em()->persist($jornada);
        $this->em()->flush();

        return $jornada;
    }

    private function minutos(string $entrada, ?string $repouso, ?string $retorno, string $saida): int
    {
        $m = static fn (string $hhmm): int => (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
        $total = $m($saida) - $m($entrada);

        return $repouso !== null && $retorno !== null ? $total - ($m($retorno) - $m($repouso)) : $total;
    }

    private function registrarBatida(User $user, Tenant $tenant, string $tipo, \DateTimeInterface $quando): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(\DateTime::createFromInterface($quando));
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    /**
     * Corpo do POST da jornada pessoal, com a carga semanal somada dos blocos.
     *
     * @param list<array{0: list<int>, 1: string, 2: ?string, 3: ?string, 4: string}> $blocos
     *
     * @return array<string, mixed>
     */
    private function corpoDaJornada(array $blocos): array
    {
        $carga = 0;
        $lista = [];
        foreach ($blocos as [$dias, $entrada, $repouso, $retorno, $saida]) {
            $carga += $this->minutos($entrada, $repouso, $retorno, $saida) * count($dias);
            $lista[] = ['diasSemana' => $dias, 'entrada' => $entrada, 'repouso' => $repouso ?? '', 'retorno' => $retorno ?? '', 'saida' => $saida];
        }

        return ['cargaHorariaSemanal' => $carga, 'alertaHabilitado' => true, 'blocos' => $lista];
    }

    /**
     * @param array<string, mixed>|null $corpo
     *
     * @return array<string, mixed>
     */
    private function chamarJson(KernelBrowser $client, string $metodo, string $url, ?array $corpo = null): array
    {
        $client->request($metodo, $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax',
        ], $corpo === null ? null : (string) json_encode($corpo));

        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function instalarCsrfFalso(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }
}
