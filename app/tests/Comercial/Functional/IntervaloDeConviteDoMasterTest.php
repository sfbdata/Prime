<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\IntervaloDosConvites;
use App\Comercial\Controller\ComercialDoMasterController;
use App\Comercial\UseCase\AlterarIntervaloDeConviteUseCase;
use App\Comercial\UseCase\ConsultarIntervaloDeConviteUseCase;
use App\Comercial\UseCase\ContarPublicosUseCase;
use App\Comercial\DTO\PedidoDePublico;
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
 * O intervalo entre convites aos contatos salvos na Central de envios (decisão de 25/11/2026): só o Master vê e troca,
 * a troca exige o token CSRF, vale de 1 a 365 dias (padrão 7), entra na Trilha de Auditoria e a contagem do público
 * Contatos salvos mostra quantos saem pelo intervalo.
 */
#[CoversClass(ComercialDoMasterController::class)]
#[CoversClass(AlterarIntervaloDeConviteUseCase::class)]
#[CoversClass(ConsultarIntervaloDeConviteUseCase::class)]
#[CoversClass(IntervaloDosConvites::class)]
#[CoversClass(ConfiguracaoGlobal::class)]
final class IntervaloDeConviteDoMasterTest extends JusPrimeWebTestCase
{
    use CriaCampanhas;

    #[TestDox('Quem não é Master não vê nem troca o intervalo: 403, e nada gravado')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório intervalo ' . uniqid());
        $em->persist($tenant);
        $comum = $this->usuario('comum');
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();
        $this->logarComTenant($client, $comum, $tenant);

        $client->request('GET', '/master/comercial/convite-intervalo');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/convite-intervalo', [], [], ['CONTENT_TYPE' => 'application/json'], '{"dias":30}');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravado());
    }

    #[TestDox('Sem token CSRF a troca é recusada (403) e nada é gravado')]
    public function testSemCsrf(): void
    {
        $client = $this->clienteMaster();
        $client->request('POST', '/master/comercial/convite-intervalo', [], [], ['CONTENT_TYPE' => 'application/json'], '{"dias":30}');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravado());
    }

    #[TestDox('Sem mexer vale o padrão de 7 dias; trocar grava, vale na hora e entra na Trilha de Auditoria')]
    public function testTrocarPeloPainel(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/convite-intervalo');
        self::assertResponseIsSuccessful();
        self::assertSame(['dias' => 7, 'origem' => 'padrao', 'padrao' => 7, 'minimo' => 1, 'maximo' => 365], $this->json($client));

        $this->postar($client, ['dias' => 30]);

        self::assertResponseIsSuccessful();
        self::assertSame(['dias' => 30, 'origem' => 'painel', 'padrao' => 7, 'minimo' => 1, 'maximo' => 365], $this->json($client));
        self::assertSame(30, $this->gravado());
        self::assertSame(30, static::getContainer()->get(IntervaloDosConvites::class)->dias());
        $trilha = $this->conexao()->fetchFirstColumn('SELECT changes FROM audit_log WHERE entity_class = ? ORDER BY id DESC LIMIT 1', [ConfiguracaoGlobal::class]);
        self::assertCount(1, $trilha);
        self::assertStringContainsString('comercial.intervaloConviteDias', (string) $trilha[0]);
    }

    #[TestDox('Os limites 1 e 365 valem; 0, 366, texto e decimal são recusados (422) sem gravar')]
    public function testFaixa(): void
    {
        $client = $this->clienteMaster();
        foreach ([1, 365] as $valido) {
            $this->postar($client, ['dias' => $valido]);
            self::assertResponseIsSuccessful();
            self::assertSame($valido, $this->gravado());
        }
        foreach ([['dias' => 0], ['dias' => 366], ['dias' => -3], ['dias' => '30'], ['dias' => 7.5], []] as $invalido) {
            $this->postar($client, $invalido);
            self::assertResponseStatusCodeSame(422, (string) json_encode($invalido));
            self::assertSame(365, $this->gravado(), 'o último valor válido continua');
        }
        $this->postar($client, ['dias' => 0]);
        self::assertSame('Informe de 1 a 365 dias.', $this->json($client)['erro']);
    }

    #[TestDox('A contagem de Contatos salvos separa os pulados pelo intervalo dos descadastrados, e o intervalo do painel manda')]
    public function testContagemMostraOsPulados(): void
    {
        $client = $this->clienteMaster();
        $c = static::getContainer();
        $agora = new \DateTimeImmutable();
        $this->conexao()->executeStatement('DELETE FROM comercial_contato');
        foreach ([['livre' . uniqid() . '@exemplo.com', null], ['recente' . uniqid() . '@exemplo.com', $agora->modify('-3 days')]] as [$email, $ultimo]) {
            $this->conexao()->executeStatement(
                "INSERT INTO comercial_contato (email, nome, origem, criado_em, atualizado_em, ultimo_convite_em) VALUES (?, '', 'importacao', ?, ?, ?)",
                [$email, $agora->format('Y-m-d H:i:s'), $agora->format('Y-m-d H:i:s'), $ultimo?->format('Y-m-d H:i:s')],
            );
        }

        $padrao = $c->get(ContarPublicosUseCase::class)->executar(new PedidoDePublico('contatos'));
        self::assertSame([1, 1, 0, 2], [$padrao->destinatarios, $padrao->puladosPeloIntervalo, $padrao->descadastrados, $padrao->publicos['contatos']]);

        $this->postar($client, ['dias' => 2]);
        $curto = $c->get(ContarPublicosUseCase::class)->executar(new PedidoDePublico('contatos'));
        self::assertSame([2, 0], [$curto->destinatarios, $curto->puladosPeloIntervalo], 'com 2 dias, quem foi convidado há 3 já volta');
        self::assertSame(0, $curto->paraArray()['puladosPeloIntervalo']);
    }

    private function gravado(): ?int
    {
        $valor = $this->conexao()->fetchOne('SELECT valores ->> ? FROM configuracao_global WHERE id = 1', [ConfiguracaoGlobal::chaveDoIntervaloDeConvite()]);

        return \is_string($valor) ? (int) $valor : null;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, array $corpo): void
    {
        $client->request('POST', '/master/comercial/convite-intervalo', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function clienteMaster(bool $comToken = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class ($comToken) implements ClearableTokenStorageInterface {
            public function __construct(private readonly bool $comToken)
            {
            }

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
                return $this->comToken;
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
