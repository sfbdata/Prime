<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Controller\ComercialDoMasterController;
use App\Comercial\UseCase\AlterarEnvioDeCampanhasUseCase;
use App\Comercial\UseCase\ConsultarEnvioDeCampanhasUseCase;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Tests\Comercial\Support\CriaCampanhas;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A chave do envio de campanhas na Central de envios (pedido do Farlei M8.1 a M8.4, 23/11/2026): o Master vê o estado,
 * liga e desliga, a troca entra na Trilha de Auditoria e, ao religar, as campanhas que venceram no meio passam a valer
 * agora (as das datas comemorativas não).
 */
#[CoversClass(ComercialDoMasterController::class)]
#[CoversClass(AlterarEnvioDeCampanhasUseCase::class)]
#[CoversClass(ConsultarEnvioDeCampanhasUseCase::class)]
#[CoversClass(ChaveDasCampanhas::class)]
#[CoversClass(ConfiguracaoGlobal::class)]
final class EnvioDeCampanhasDoMasterTest extends JusPrimeWebTestCase
{
    use CriaCampanhas;

    #[TestDox('Quem não é Master não vê nem troca a chave: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório envio ' . uniqid());
        $em->persist($tenant);
        $comum = $this->usuario('comum');
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();
        $this->logarComTenant($client, $comum, $tenant);

        $client->request('GET', '/master/comercial/envio');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/envio', [], [], ['CONTENT_TYPE' => 'application/json'], '{"ligada":true}');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravado());
    }

    #[TestDox('Sem mexer no painel vale o ambiente (desligado no teste); ligar grava no painel, vale na hora e entra na trilha')]
    public function testLigarPeloPainel(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/envio');
        self::assertResponseIsSuccessful();
        self::assertSame(['ligada' => false, 'origem' => 'ambiente', 'esperando' => 0], $this->json($client));

        $this->postar($client, ['ligada' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ligada' => true, 'origem' => 'painel', 'esperando' => 0], $this->json($client));
        self::assertTrue($this->gravado());
        self::assertTrue(static::getContainer()->get(ChaveDasCampanhas::class)->ligada());
        $trilha = $this->conexao()->fetchFirstColumn('SELECT changes FROM audit_log WHERE entity_class = ? ORDER BY id DESC LIMIT 1', [ConfiguracaoGlobal::class]);
        self::assertCount(1, $trilha);
        self::assertStringContainsString('comercial.campanhasLigadas', (string) $trilha[0]);

        $this->postar($client, ['ligada' => false]);
        self::assertSame(['ligada' => false, 'origem' => 'painel', 'esperando' => 0], $this->json($client));
        self::assertFalse($this->gravado());
    }

    #[TestDox('Corpo sem "ligada" booleano: 422 com o motivo, e nada gravado')]
    public function testCorpoInvalido(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, ['ligada' => 'sim']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe se o envio de campanhas fica ligado.', $this->json($client)['erro']);
        self::assertNull($this->gravado());
    }

    #[TestDox('Salvar as configurações globais não apaga a chave do envio, e ela não aparece entre os campos do catálogo')]
    public function testChaveForaDoCatalogo(): void
    {
        $linha = new ConfiguracaoGlobal();
        $linha->definirEnvioDeCampanhas(true, null, new \DateTimeImmutable());
        $linha->definirValores(['manutencao' => '0'], null, new \DateTimeImmutable());

        self::assertTrue($linha->getEnvioDeCampanhas());
        self::assertSame(['manutencao' => '0'], $linha->getValores());
    }

    #[TestDox('Desligada, "esperando" conta as vencidas do Master e as que estão saindo (não as das datas); religar traz as do Master para agora, não as das datas nem as futuras')]
    public function testReligarTrazAsVencidas(): void
    {
        $client = $this->clienteMaster();
        $emails = [(string) $this->cadastradoAtivo()->getEmail()];
        $tresDias = new \DateTimeImmutable('-3 days');
        $vencida = $this->campanha($emails, ['proxima' => $tresDias]);
        $recorrente = $this->campanha($emails, ['estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => $tresDias, 'proxima' => $tresDias]);
        $daData = $this->campanha($emails, ['proxima' => $tresDias, 'modelo_chave' => 'data-natal']);
        $futura = $this->campanha($emails, ['estado' => 'agendada', 'agendada_para' => new \DateTimeImmutable('+2 days'), 'proxima' => new \DateTimeImmutable('+2 days')]);
        $saindo = $this->campanha($emails, ['estado' => 'enviando']);
        $this->postar($client, ['ligada' => false]);
        self::assertSame(3, $this->json($client)['esperando'], 'vencida, recorrente e a que está saindo; a da data e a futura não');

        $antes = new \DateTimeImmutable('-1 second');
        $this->postar($client, ['ligada' => true]);

        self::assertResponseIsSuccessful();
        foreach ([$vencida, $recorrente] as $id) {
            self::assertGreaterThanOrEqual($antes, $this->proxima($id), 'trazida para agora: o expansor não a cancela pelo atraso');
        }
        self::assertSame($tresDias->format('Y-m-d H:i'), $this->proxima($daData)->format('Y-m-d H:i'), 'a felicitação que perdeu o dia não sai atrasada');
        self::assertGreaterThan(new \DateTimeImmutable('+1 day'), $this->proxima($futura));
        self::assertSame('enviando', $this->conexao()->fetchOne('SELECT estado FROM comercial_campanha WHERE id = ?', [$saindo]));
    }

    #[TestDox('Ligar com a chave já ligada não mexe no horário de ninguém')]
    public function testLigarDeNovoNaoMexe(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, ['ligada' => true]);
        $tresDias = new \DateTimeImmutable('-3 days');
        $vencida = $this->campanha([(string) $this->cadastradoAtivo()->getEmail()], ['proxima' => $tresDias]);

        $this->postar($client, ['ligada' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame($tresDias->format('Y-m-d H:i'), $this->proxima($vencida)->format('Y-m-d H:i'));
    }

    private function proxima(int $id): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) $this->conexao()->fetchOne('SELECT proxima_execucao FROM comercial_campanha WHERE id = ?', [$id]));
    }

    private function gravado(): ?bool
    {
        $valor = $this->conexao()->fetchOne('SELECT valores ->> ? FROM configuracao_global WHERE id = 1', [ConfiguracaoGlobal::chaveDoEnvioDeCampanhas()]);

        return \is_string($valor) ? $valor === '1' : null;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, array $corpo): void
    {
        $client->request('POST', '/master/comercial/envio', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
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
        $master = $this->usuario('master');
        $master->setRoles(['ROLE_SUPER_ADMIN']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
