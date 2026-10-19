<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\EventListener\TenantContextValidatorListener;
use App\Tenant\Service\AvisoDeContaSuspensa;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Barreiras do TenantContextValidatorListener (spec perfis-1.2-contas C9 e C13): (a) a conta, depois
 * (c) o vínculo. Vale a mensagem da primeira que falhar; o Master não tem bypass (C12).
 */
#[CoversClass(TenantContextValidatorListener::class)]
#[CoversClass(AvisoDeContaSuspensa::class)]
final class BarreirasDeAcessoAoEscritorioTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('conta suspensa: barra com a mensagem da C9, também com o vínculo inativo (a conta vem antes) e também o Master')]
    public function testContaSuspensa(): void
    {
        $conta = $this->conta(ativa: false, excluida: false);
        $mensagem = 'A conta do escritório ' . $conta->getName() . ' está suspensa. Fale com o responsável pela conta.';

        self::assertSame([$mensagem], $this->barrado($this->usuario(['ROLE_USER']), $conta, vinculoAtivo: true));
        self::assertSame([$mensagem], $this->barrado($this->usuario(['ROLE_USER']), $conta, vinculoAtivo: false));
        self::assertSame([$mensagem], $this->barrado($this->usuario(['ROLE_SUPER_ADMIN']), $conta, vinculoAtivo: true));
    }

    #[TestDox('conta excluída e vínculo inativo em conta ativa: a mensagem de acesso indisponível de sempre')]
    public function testContaExcluidaEVinculoInativo(): void
    {
        $indisponivel = ['Seu acesso a este escritório não está mais disponível. Selecione outro escritório.'];

        self::assertSame($indisponivel, $this->barrado($this->usuario(['ROLE_USER']), $this->conta(ativa: false, excluida: true), vinculoAtivo: true));
        self::assertSame($indisponivel, $this->barrado($this->usuario(['ROLE_USER']), $this->conta(ativa: true, excluida: false), vinculoAtivo: false));
    }

    /**
     * @param list<string> $papeis
     */
    private function usuario(array $papeis): User
    {
        return UserFactory::createOne(['roles' => $papeis, 'isActive' => true])->_real();
    }

    private function conta(bool $ativa, bool $excluida): Tenant
    {
        $conta = (new Tenant())->setName('Barreira ' . uniqid())->setIsActive($ativa)->setExcluidoEm($excluida ? new \DateTimeImmutable() : null);
        $this->em->persist($conta);
        $this->em->flush();

        return $conta;
    }

    /** @return list<string> os avisos da barreira, depois de conferir que ela derrubou a sessão */
    private function barrado(User $user, Tenant $conta, bool $vinculoAtivo): array
    {
        // A requisição anterior pode ter limpado o EntityManager: recarrega antes de ligar os dois.
        $user  = $this->em->find(User::class, $user->getId()) ?? $user;
        $conta = $this->em->find(Tenant::class, $conta->getId()) ?? $conta;
        $vinculo = new UserTenant($user, $conta);
        // A entidade não tem como desativar o vínculo (só reativar): o estado legado vem por reflexão.
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, $vinculoAtivo);
        $this->em->persist($vinculo);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $conta);

        $this->client->request('GET', '/expediente');

        self::assertResponseRedirects('/escritorio/selecionar');
        $sessao = $this->client->getRequest()->getSession();
        self::assertNull($sessao->get('current_tenant_id'), 'a sessão sai do escritório');

        $avisos = $sessao->getFlashBag()->get('warning');
        $sessao->save(); // o cliente reaproveita a sessão no próximo login: o aviso lido não volta

        return $avisos;
    }
}
