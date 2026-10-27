<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Controller\ConferirCupomController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Conferência ao vivo do cupom no "Criar conta" (spec `login-cupom-ao-vivo.md`): POST público que responde só
 * `{"valido": bool}`. O limitador volta a ser em memória a cada teste para o IP do teste não acumular entre execuções.
 */
#[CoversClass(ConferirCupomController::class)]
final class ConferirCupomAoVivoTest extends JusPrimeWebTestCase
{
    private const CODIGO = 'QWER2345';
    private const URL = '/cadastro/cupom/conferir';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conexao;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('limiter.indicacao_cupom', new RateLimiterFactory(['id' => 'indicacao_cupom_teste', 'policy' => 'sliding_window', 'limit' => 20, 'interval' => '15 minutes'], new InMemoryStorage()));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conexao = $this->em->getConnection();
        $this->ana = new User();
        $this->ana->setEmail(uniqid('cupom_') . '@exemplo.com');
        $this->ana->setFullName('Ana Souza Lima');
        $this->ana->setRoles(['ROLE_USER']);
        $this->ana->setPassword('x');
        $this->em->persist($this->ana);
        $this->em->flush();
        $this->conexao->executeStatement('INSERT INTO indicacao_codigo (user_id, codigo) VALUES (?, ?)', [$this->ana->getId(), self::CODIGO]);
    }

    private function conferir(mixed $codigo, string $corpoBruto = ''): array
    {
        $conteudo = $corpoBruto !== '' ? $corpoBruto : json_encode(['codigo' => $codigo], JSON_THROW_ON_ERROR);
        $this->client->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: $conteudo);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    #[TestDox('código existente, com ou sem BJ- e em qualquer caixa: valido true')]
    #[TestWith(['QWER2345'])]
    #[TestWith(['BJ-QWER2345'])]
    #[TestWith(['bj-qwer2345'])]
    #[TestWith([' BJQWER2345 '])]
    public function testExistente(string $codigo): void
    {
        self::assertSame(['valido' => true], $this->conferir($codigo));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('código que não existe: valido false')]
    public function testInexistente(): void
    {
        self::assertSame(['valido' => false], $this->conferir('BJ-ZZZZ9999'));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('vazio, malformado ou de tipo errado: valido false, sem erro 500')]
    #[TestWith([''])]
    #[TestWith(['BJ-12'])]
    #[TestWith(['<script>'])]
    #[TestWith(['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'])]
    public function testMalformado(string $codigo): void
    {
        self::assertSame(['valido' => false], $this->conferir($codigo));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('codigo em lista, número ou nulo: valido false')]
    #[TestWith([[]])]
    #[TestWith([['QWER2345']])]
    #[TestWith([12345678])]
    #[TestWith([null])]
    public function testTipoErrado(mixed $codigo): void
    {
        self::assertSame(['valido' => false], $this->conferir($codigo));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('corpo que não é JSON não dá 500: valido false')]
    #[TestWith(['isto não é json'])]
    #[TestWith(['"QWER2345"'])]
    public function testCorpoQueNaoEJson(string $corpo): void
    {
        self::assertSame(['valido' => false], $this->conferir(null, $corpo));
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
    }

    #[TestDox('a resposta tem só a chave valido e não vaza nome nem e-mail do dono')]
    public function testRespostaMinima(): void
    {
        $this->conferir(self::CODIGO);

        $corpo = (string) $this->client->getResponse()->getContent();
        self::assertSame(['valido'], array_keys(json_decode($corpo, true)));
        self::assertStringNotContainsString('Ana', $corpo);
        self::assertStringNotContainsString($this->ana->getEmail(), $corpo);
    }

    #[TestDox('responde sem cache')]
    public function testSemCache(): void
    {
        $this->conferir(self::CODIGO);

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[TestDox('é sem estado: não grava o código na sessão')]
    public function testNaoGravaNaSessao(): void
    {
        $this->conferir(self::CODIGO);

        self::assertFalse($this->client->getRequest()->hasSession() && $this->client->getRequest()->getSession()->has('indicacao_codigo'));
    }

    #[TestDox('GET responde 405')]
    public function testGetNaoPermitido(): void
    {
        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('o 21º pedido em 15 minutos responde 429 sem a chave valido; o 20º ainda passa')]
    public function testLimite(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->conferir(self::CODIGO);
            self::assertResponseStatusCodeSame(200, "pedido $i");
        }

        $this->conferir(self::CODIGO);

        self::assertResponseStatusCodeSame(429);
        self::assertStringNotContainsString('valido', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('código malformado também consome o limite')]
    public function testMalformadoConsomeLimite(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->conferir('lixo!');
        }

        $this->conferir(self::CODIGO);

        self::assertResponseStatusCodeSame(429);
    }

    #[TestDox('quem está logado também pode consultar')]
    public function testLogadoPodeConsultar(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Souza Advogados ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->ana, $tenant);

        self::assertSame(['valido' => true], $this->conferir(self::CODIGO));
        self::assertResponseIsSuccessful();
    }
}
