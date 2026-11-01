<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\AvisoDaCotaDeEnvioController;
use App\Comercial\DTO\SituacaoDaCota;
use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Twig\AvisoDaCotaDeEnvioExtension;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O aviso da cota de envio de e-mail no topo de toda tela (spec comercial-e-emails.md §3.6), só para o Master: perto
 * do limite (80% do teto) e campanhas pausadas (com a hora da volta); o "Dispensar" (POST com CSRF) esconde o aviso na
 * sessão até o estado mudar. Cota de teste: teto 10, campanhas 6 (reserva 4), aviso em 80%.
 */
#[CoversClass(AvisoDaCotaDeEnvioController::class)]
#[CoversClass(AvisoDaCotaDeEnvioExtension::class)]
#[CoversClass(CotaDeEnvio::class)]
#[CoversClass(SituacaoDaCota::class)]
final class AvisoDaCotaDeEnvioTest extends JusPrimeWebTestCase
{
    /** Filho direto do contêiner dos avisos do topo, que o _globais põe logo dentro do <body>. */
    private const AVISO = 'body > .ae-avisos > .ae-aviso[data-aviso-cota-de-envio]';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarTokensFalsos();
        $c = static::getContainer();
        $c->set(CotaDeEnvio::class, new CotaDeEnvio($c->get(EnvioDeEmailRepository::class), $c->get(DestinoDaCampanhaRepository::class), $c->get(ClockInterface::class), 10, 6, 10, 80));
        $this->escritorio = (new Tenant())->setName('Escritório Cota ' . uniqid());
        $this->em->persist($this->escritorio);
        $this->em->flush();
    }

    #[TestDox('Master com 80% do teto vê o aviso de atenção com enviados e teto')]
    public function testMasterVePerto(): void
    {
        $this->semear(8, 'sistema', 'enviado', new \DateTimeImmutable('-1 hour'));
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        $crawler = $this->client->request('GET', '/novidades');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::AVISO . '[data-aviso-cota-de-envio="perto"]:not(.ae-aviso--cheio)'));
        self::assertStringContainsString('E-mails: 8 de 10 enviados nas últimas 24 horas. Perto do limite diário.', $crawler->filter(self::AVISO)->text());
        self::assertCount(1, $crawler->filter(self::AVISO . ' > form[method="post"] > button.af-dispensar'));
    }

    #[TestDox('Abaixo de 80% não há aviso')]
    public function testAbaixoDoPercentualSemAviso(): void
    {
        $this->semear(7, 'sistema', 'enviado', new \DateTimeImmutable('-1 hour'));
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        self::assertCount(0, $this->client->request('GET', '/novidades')->filter('[data-aviso-cota-de-envio]'));
    }

    #[TestDox('Usuário comum não vê o aviso e o Dispensar dele recebe 403')]
    public function testUsuarioComumNaoVe(): void
    {
        $this->semear(9, 'sistema', 'enviado', new \DateTimeImmutable('-1 hour'));
        $this->entrar($this->usuario(['ROLE_USER']));

        $crawler = $this->client->request('GET', '/novidades');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-aviso-cota-de-envio]'));
        $this->dispensar('/novidades');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Campanhas pausadas: aviso cheio com a hora e o dia em que a vaga volta (mais antigo + 24 h + 60 s)')]
    public function testPausadasMostraOHorario(): void
    {
        $maisAntigo = new \DateTimeImmutable(date('Y-m-d H:i:00', strtotime('-3 hours')));
        $this->semear(6, 'campanha:1', 'enviado', $maisAntigo);
        $this->semear(1, 'campanha:1', 'enfileirado', new \DateTimeImmutable('-1 minute'));
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        $crawler = $this->client->request('GET', '/novidades');

        $volta = $maisAntigo->modify('+24 hours 60 seconds');
        self::assertCount(1, $crawler->filter(self::AVISO . '.ae-aviso--cheio[data-aviso-cota-de-envio="pausadas"]'));
        $texto = $crawler->filter(self::AVISO)->text();
        self::assertStringContainsString('Limite de e-mail de campanha atingido', $texto);
        self::assertStringContainsString('voltam a enviar por volta das ' . $volta->format('H:i') . ' de ' . $volta->format('d/m') . '.', $texto);
        self::assertStringContainsString('E-mails de conta e convite continuam saindo.', $texto);
    }

    #[TestDox('Dispensar esconde o aviso na sessão, volta para a página e o aviso reaparece quando o estado muda')]
    public function testDispensarEsconde(): void
    {
        $this->semear(8, 'sistema', 'enviado', new \DateTimeImmutable('-1 hour'));
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/novidades');
        self::assertCount(1, $crawler->filter(self::AVISO));
        $chave = (string) $crawler->filter(self::AVISO . ' > form > input[name="chave"]')->attr('value');
        self::assertSame('perto:' . date('Y-m-d'), $chave, 'o formulário manda a chave do aviso visto');

        $this->dispensar('/novidades', $chave);

        self::assertResponseRedirects('/novidades');
        self::assertCount(0, $this->client->request('GET', '/novidades')->filter('[data-aviso-cota-de-envio]'));

        $this->semear(1, 'campanha:1', 'enfileirado', new \DateTimeImmutable('-1 minute'));
        $this->semear(2, 'campanha:1', 'enviado', new \DateTimeImmutable('-30 minutes'));
        self::assertCount(1, $this->client->request('GET', '/novidades')->filter(self::AVISO . '[data-aviso-cota-de-envio="pausadas"]'), '2 de campanha + 8 demais = teto, com campanha na fila: o estado mudou');
    }

    #[TestDox('CSRF inválido é recusado e o aviso continua')]
    public function testCsrfInvalido(): void
    {
        $this->semear(8, 'sistema', 'enviado', new \DateTimeImmutable('-1 hour'));
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        $this->client->request('POST', '/master/comercial/cota/dispensar', ['_token' => 'errado', 'voltar' => '/novidades']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->client->request('GET', '/novidades')->filter(self::AVISO));
    }

    #[TestDox('Chave fora do formato (nem perto:AAAA-MM-DD nem pausadas:AAAA-MM-DD) não grava nada: o aviso continua')]
    public function testChaveInvalidaNaoDispensa(): void
    {
        $this->semear(8, 'sistema', 'enviado', new \DateTimeImmutable('-1 hour'));
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        foreach (['perto', 'perto:ontem', 'qualquer:2030-01-01', ''] as $chave) {
            $this->dispensar('/novidades', $chave);
            self::assertResponseRedirects('/novidades');
        }

        self::assertCount(1, $this->client->request('GET', '/novidades')->filter(self::AVISO));
    }

    #[TestDox('voltar para fora do sistema cai no início (sem redirecionamento aberto)')]
    public function testVoltarParaForaCaiNoInicio(): void
    {
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        foreach (['https://mal.example/x', '//mal.example/x', '/\\mal.example', '/%2F%2Fmal.example', ''] as $voltar) {
            $this->dispensar($voltar);
            self::assertResponseRedirects('/inicio', null, $voltar);
        }
    }

    private function semear(int $quantos, string $origem, string $situacao, \DateTimeImmutable $em): void
    {
        for ($i = 0; $i < $quantos; ++$i) {
            $this->conexao()->executeStatement(
                "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, origem, situacao, criado_em, enviado_em, aberturas, cliques)
                 VALUES (:token, 'semente@exemplo.com', 'x', 'transacional', :origem, :situacao, :em, :enviado, 0, 0)",
                [
                    'token' => bin2hex(random_bytes(16)), 'origem' => $origem, 'situacao' => $situacao,
                    'em' => $em->format('Y-m-d H:i:s'), 'enviado' => $situacao === 'enviado' ? $em->format('Y-m-d H:i:s') : null,
                ],
            );
        }
    }

    private function dispensar(string $voltar, ?string $chave = null): void
    {
        $chave ??= 'perto:' . date('Y-m-d');
        $this->client->request('POST', '/master/comercial/cota/dispensar', ['_token' => 'TOKEN_' . AvisoDaCotaDeEnvioController::CSRF, 'chave' => $chave, 'voltar' => $voltar]);
    }

    private function entrar(User $usuario): void
    {
        $this->escritorio = $this->em->find(Tenant::class, $this->escritorio->getId()) ?? $this->escritorio;
        $this->em->persist(new UserTenant($usuario, $this->escritorio));
        $this->em->flush();
        $this->logarComTenant($this->client, $usuario, $this->escritorio);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $user = (new User())->setEmail('cota_' . uniqid() . '@test.com')->setFullName('Pessoa Cota')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function instalarTokensFalsos(): void
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
