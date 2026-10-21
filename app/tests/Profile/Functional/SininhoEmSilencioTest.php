<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\Service\SilencioDoSininho;
use App\Profile\UseCase\SalvarPreferenciaDoPerfilUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Notificações no sistema" do Meu Perfil (spec docs/specs/meu-perfil-inventario-2110.md): desligada, o sininho fica em
 * silêncio, sem contador e sem lista, menos os tipos que pedem ação; "Ver todas" mostra tudo; religar volta tudo.
 */
#[CoversClass(SilencioDoSininho::class)]
final class SininhoEmSilencioTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenant = (new Tenant())->setName('Escritório Sininho ' . uniqid());
        $this->em->persist($this->tenant);
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName('Equipe ' . uniqid());
        $perfil->setIsSystem(true);
        $this->em->persist($perfil);
        $this->pessoa = (new User())->setEmail('sino_' . uniqid() . '@test.com')->setFullName('Pessoa Sininho')->setRoles(['ROLE_USER'])->setIsActive(true);
        $this->pessoa->setPassword('x');
        $this->em->persist($this->pessoa);
        $vinculo = new UserTenant($this->pessoa, $this->tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);
        $this->aviso(Notificacao::TIPO_TAREFA_CRIADA, 'Meta nova para você');
        $this->aviso(Notificacao::TIPO_DJEN_PUBLICACAO, 'Publicação no DJEN');
        $this->em->flush();
        $this->logarComTenant($this->client, $this->pessoa, $this->tenant);
    }

    #[TestDox('ligada (padrão): o sino conta e lista as duas')]
    public function testLigada(): void
    {
        self::assertSame(2, $this->contagem());
    }

    #[TestDox('desligada: o sino só conta e lista a publicação do DJEN; "Ver todas" mostra as duas; religar volta tudo')]
    public function testDesligada(): void
    {
        $this->preferencia(false);

        self::assertSame(1, $this->contagem());
        $this->client->request('GET', '/notificacoes/lista-dropdown');
        $html = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['html'];
        self::assertStringContainsString('Publicação no DJEN', $html);
        self::assertStringNotContainsString('Meta nova para você', $html);

        $todas = $this->client->request('GET', '/notificacoes');
        self::assertStringContainsString('Meta nova para você', $todas->text());
        self::assertStringContainsString('Publicação no DJEN', $todas->text());

        $this->preferencia(true);
        self::assertSame(2, $this->contagem());
    }

    private function contagem(): int
    {
        static::getContainer()->get(SilencioDoSininho::class)->reset();
        $this->client->request('GET', '/notificacoes/count');
        self::assertResponseIsSuccessful();

        return (int) json_decode((string) $this->client->getResponse()->getContent(), true)['count'];
    }

    private function preferencia(bool $ligada): void
    {
        static::getContainer()->get(SalvarPreferenciaDoPerfilUseCase::class)->executar($this->tenant, $this->pessoa, CatalogoDePreferenciasDoPerfil::NOTIFICACOES, $ligada);
        static::getContainer()->get(SilencioDoSininho::class)->reset();
    }

    private function aviso(string $tipo, string $titulo): void
    {
        $n = (new Notificacao())->setUsuario($this->pessoa)->setTenant($this->tenant)->setTipo($tipo)->setTitulo($titulo);
        $n->setMensagem($titulo);
        $this->em->persist($n);
    }
}
