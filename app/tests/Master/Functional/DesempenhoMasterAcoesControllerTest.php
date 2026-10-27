<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\EventoDeDesempenho;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Entity\RegistroDeBackup;
use App\Master\Enum\TipoDeEventoDeDesempenho;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/** Gravação da seção Desempenho (spec master-desempenho D3 e D6): só Master, CSRF, validação e "limpar" só dos eventos. */
final class DesempenhoMasterAcoesControllerTest extends JusPrimeWebTestCase
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

    #[TestDox('quem não é Master recebe 403')]
    public function testUsuarioComumNaoEntra(): void
    {
        $this->entrar(['ROLE_USER']);

        $this->enviar('/master/desempenho/eventos/limpar', [], 'qualquer');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('sem token CSRF válido recusa e não grava')]
    public function testCsrfInvalido(): void
    {
        $this->entrar(['ROLE_SUPER_ADMIN']);
        $antes = $this->em->getRepository(IncidenteDeDesempenho::class)->count([]);

        $this->enviar('/master/desempenho/incidentes', ['titulo' => 'X', 'categoria' => 'login', 'gravidade' => 'alta'], 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame($antes, $this->em->getRepository(IncidenteDeDesempenho::class)->count([]));
    }

    #[TestDox('abre um incidente e depois o resolve; resolver de novo é recusado')]
    public function testAbrirEResolver(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);

        $id = $this->enviar('/master/desempenho/incidentes', ['titulo' => 'Lentidão no login', 'categoria' => 'login', 'gravidade' => 'alta'], $token);
        self::assertResponseStatusCodeSame(201);

        $this->enviar('/master/desempenho/incidentes/' . $id['id'] . '/resolver', ['solucao' => 'Reiniciei o worker'], $token);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $incidente = $this->em->find(IncidenteDeDesempenho::class, $id['id']);
        self::assertFalse($incidente->estaAberto());

        $r = $this->enviar('/master/desempenho/incidentes/' . $id['id'] . '/resolver', ['solucao' => 'de novo'], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('erro', $r);
    }

    #[TestDox('incidente sem título, com categoria ou gravidade inválida dá 422')]
    public function testIncidenteInvalido(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);

        foreach ([['titulo' => '  ', 'categoria' => 'login', 'gravidade' => 'alta'], ['titulo' => 'X', 'categoria' => 'nada', 'gravidade' => 'alta'], ['titulo' => 'X', 'categoria' => 'login', 'gravidade' => 'nada']] as $corpo) {
            $this->enviar('/master/desempenho/incidentes', $corpo, $token);
            self::assertResponseStatusCodeSame(422);
        }
    }

    #[TestDox('resolver incidente que não existe dá 422')]
    public function testResolverInexistente(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);

        $this->enviar('/master/desempenho/incidentes/999999999/resolver', ['solucao' => ''], $token);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('registra backup e teste de restauração; tipo inválido dá 422')]
    public function testRegistrarBackup(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);
        $antes = $this->em->getRepository(RegistroDeBackup::class)->count([]);

        $this->enviar('/master/desempenho/backups', ['tipo' => 'backup', 'ok' => true, 'observacao' => 'Disco B'], $token);
        self::assertResponseStatusCodeSame(201);
        $this->enviar('/master/desempenho/backups', ['tipo' => 'restauracao', 'ok' => false, 'observacao' => ''], $token);
        self::assertResponseStatusCodeSame(201);
        $this->enviar('/master/desempenho/backups', ['tipo' => 'outro', 'ok' => true], $token);
        self::assertResponseStatusCodeSame(422);

        self::assertSame($antes + 2, $this->em->getRepository(RegistroDeBackup::class)->count([]));
    }

    #[TestDox('limpar apaga os eventos e não mexe em incidentes nem backups')]
    public function testLimpar(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);
        $this->em->persist(new EventoDeDesempenho(TipoDeEventoDeDesempenho::Erro, new \DateTimeImmutable(), null, null));
        $this->em->flush();
        $this->enviar('/master/desempenho/incidentes', ['titulo' => 'Fica', 'categoria' => 'outro', 'gravidade' => 'baixa'], $token);
        $incidentes = $this->em->getRepository(IncidenteDeDesempenho::class)->count([]);

        $r = $this->enviar('/master/desempenho/eventos/limpar', [], $token);

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $r['apagados']);
        self::assertSame(0, $this->em->getRepository(EventoDeDesempenho::class)->count([]));
        self::assertSame($incidentes, $this->em->getRepository(IncidenteDeDesempenho::class)->count([]));
    }

    /**
     * @param list<string> $papeis
     * @return string o token CSRF da seção (lido da tela do painel, como o JS faz)
     */
    private function entrar(array $papeis): string
    {
        $user = UserFactory::createOne(['fullName' => 'Fulano', 'roles' => $papeis, 'isActive' => true])->_real();
        $conta = $this->contaDe($user);
        $this->logarComTenant($this->client, $user, $conta);
        if (!in_array('ROLE_SUPER_ADMIN', $papeis, true)) {
            return '';
        }
        $crawler = $this->client->request('GET', '/master');

        return (string) $crawler->filter('[data-sec-externa="desemp"] .dpm')->attr('data-token');
    }

    /**
     * @param array<string, mixed> $corpo
     * @return array<string, mixed>
     */
    private function enviar(string $url, array $corpo, string $token): array
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $token], content: json_encode($corpo, \JSON_THROW_ON_ERROR));
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($dados) ? $dados : [];
    }

    private function contaDe(User $user): Tenant
    {
        $conta = (new Tenant())->setName('Conta ' . uniqid())->setCriadoPor($user);
        $this->em->persist($conta);
        $perfil = (new TenantRole())->setTenant($conta)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $conta))->setTenantRole($perfil));
        $this->em->flush();

        return $conta;
    }
}
