<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Master\EventListener\BloqueioDeRecursoDesativado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\SaudeDoVotoController;
use App\Voto\EventListener\PapelDoContainer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * A saúde do container `voto` (spec docs/specs/bluejus-voto.md, A2) e o papel do container pela fiação de verdade: a
 * variável APP_PAPEL entra no ambiente antes do kernel subir, como o docker-compose.prod.yml faz no serviço `voto`.
 * No ambiente de teste a variável não existe, então o papel é `bluejus`.
 *
 * A rota é pública (security.yaml): quem chama é o Samuel ou um monitor, sem login. O "cai fechado" roda a requisição
 * com o papel do banco do Voto (`SET ROLE voto_acesso` dentro da transação do DAMA), para provar que o guarda de
 * recurso do Master só precisa da `recurso_estado` e responde 503, e não 500, com a chave desligada.
 */
#[CoversClass(SaudeDoVotoController::class)]
#[CoversClass(PapelDoContainer::class)]
#[CoversClass(BloqueioDeRecursoDesativado::class)]
final class SaudeDoVotoTest extends JusPrimeWebTestCase
{
    use Factories;

    private const ENDERECO = '/saude-do-voto';
    private const VOLTAR = 'a.bj-recurso-preparo-voltar';

    protected function tearDown(): void
    {
        unset($_SERVER['APP_PAPEL'], $_ENV['APP_PAPEL']);
        putenv('APP_PAPEL');
        parent::tearDown();
    }

    #[TestDox('no BlueJus (sem APP_PAPEL) a saúde do Voto é 404 para anônimo, mesmo com a chave voto ligada')]
    public function testNoBluejusASaudeE404ParaAnonimo(): void
    {
        $client = $this->cliente();
        $this->ligar();

        $client->request('GET', self::ENDERECO);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('no BlueJus a saúde do Voto é 404 também para quem está logado')]
    public function testNoBluejusASaudeE404ParaLogado(): void
    {
        $client = $this->cliente();
        $this->ligar();
        $this->entrar($client);

        $client->request('GET', self::ENDERECO);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('no papel voto, com a chave voto ligada, a saúde responde ok a anônimo, em texto e sem cache')]
    public function testNoVotoComAChaveLigadaRespondeOkAAnonimo(): void
    {
        $client = $this->cliente('voto');
        $this->ligar();

        $client->request('GET', self::ENDERECO);

        $this->conferirOk($client);
    }

    #[TestDox('no papel voto, com a chave voto desligada, a saúde é a página "em preparação" (503), sem o "Voltar ao Expediente"')]
    public function testNoVotoComAChaveDesligadaEmPreparacaoSemVoltar(): void
    {
        $client = $this->cliente('voto');
        $this->desligar();

        $client->request('GET', self::ENDERECO);

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('h1', 'Voto em preparação');
        self::assertSelectorNotExists(self::VOLTAR, 'no container do Voto o Expediente é 404');
    }

    #[TestDox('no BlueJus a página "em preparação" continua com o "Voltar ao Expediente"')]
    public function testNoBluejusEmPreparacaoTemVoltar(): void
    {
        $client = $this->cliente();
        $this->desligar();
        $this->entrar($client);

        $client->request('GET', '/voto');

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorExists(self::VOLTAR);
    }

    #[TestDox('no papel voto o login e a tela da equipe do Voto são 404')]
    public function testNoVotoOLoginEATelaDaEquipeSao404(): void
    {
        $client = $this->cliente('voto');
        $this->ligar();

        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/voto');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('com o papel do banco do Voto (voto_acesso) e a chave ligada, a saúde responde ok')]
    public function testComOPapelDoBancoDoVotoEAChaveLigadaRespondeOk(): void
    {
        $client = $this->cliente('voto');
        $this->ligar();

        $this->comOPapelDoBancoDoVoto(function () use ($client): void {
            $client->request('GET', self::ENDERECO);
            $this->conferirOk($client);
        });
    }

    #[TestDox('com o papel do banco do Voto (voto_acesso) e a chave desligada, cai fechado: 503 "em preparação", nunca 500')]
    public function testComOPapelDoBancoDoVotoEAChaveDesligadaCaiFechado(): void
    {
        $client = $this->cliente('voto');
        $this->desligar();

        $this->comOPapelDoBancoDoVoto(function () use ($client): void {
            $client->request('GET', self::ENDERECO);
            self::assertResponseStatusCodeSame(503);
            self::assertSelectorTextContains('h1', 'Voto em preparação');
        });
    }

    private function conferirOk(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertSame('ok', $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    /**
     * Roda o trecho com a conexão no papel `voto_acesso`, o mesmo do usuário `voto_app` da VPS. O SET ROLE fica dentro
     * da transação do DAMA (o rollback do fim do teste também o desfaz); o RESET ROLE devolve o papel antes disso.
     * O estado do recurso é gravado ANTES: o papel do Voto não escreve em `recurso_estado`. O papel e os GRANT da
     * Version20261109160000 também nascem aqui, na mesma transação ({@see PapelDoVotoNoBancoDeTeste}): o snapshot de
     * teste não os leva.
     */
    private function comOPapelDoBancoDoVoto(callable $trecho): void
    {
        $banco = $this->banco();
        PapelDoVotoNoBancoDeTeste::preparar($banco);
        $banco->executeStatement('SET ROLE voto_acesso');
        self::assertSame('voto_acesso', $banco->fetchOne('SELECT current_user'));
        try {
            $trecho();
        } finally {
            try {
                $banco->executeStatement('RESET ROLE');
            } catch (\Throwable) {
                // Transação abortada por erro na requisição: o rollback do DAMA desfaz o SET ROLE, e a falha que
                // importa é a da asserção, não esta.
            }
        }
    }

    private function cliente(?string $papel = null): KernelBrowser
    {
        if ($papel !== null) {
            $_SERVER['APP_PAPEL'] = $_ENV['APP_PAPEL'] = $papel;
            putenv('APP_PAPEL=' . $papel);
        }
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function entrar(KernelBrowser $client): void
    {
        $em = $this->em();
        $escritorio = TenantFactory::createOne(['name' => 'Escritório da saúde do Voto ' . uniqid()])->_real();
        $user = UserFactory::createOne(['fullName' => 'Usuário da saúde do Voto', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($escritorio)->setName('Perfil da saúde ' . uniqid())->setIsSystem(false);
        $em->persist($papel);
        $em->persist((new UserTenant($user, $escritorio))->setTenantRole($papel));
        $em->flush();

        $this->logarComTenant($client, $user, $escritorio);
    }

    private function ligar(): void
    {
        $estado = $this->estado();
        if ($estado !== null) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em()->flush();
        }
    }

    private function desligar(): void
    {
        $estado = $this->estado() ?? new RecursoEstado('voto');
        $estado->desativar(null, null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
    }

    private function estado(): ?RecursoEstado
    {
        return $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function banco(): Connection
    {
        return $this->em()->getConnection();
    }
}
