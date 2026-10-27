<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaModelosController;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Aba Modelos (M2): dados da peça, "Usar nesta pasta" (vira documento PECA da pasta) e Word.
 */
#[CoversClass(PastaModelosController::class)]
#[Group('pasta')]
final class PastaModelosUsoTest extends JusPrimeWebTestCase
{
    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Modelos ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_modelos_' . uniqid() . '@test.com');
        $user->setFullName('Quem Modela');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup((string) random_int(10000, 99999));
        $pasta->setNomeCliente('CLIENTE DOS MODELOS');
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarModelo(Tenant $tenant, string $titulo, MateriaDeModelo $materia = MateriaDeModelo::Civel, TipoDePeca $tipo = TipoDePeca::PeticaoInicial, string $corpo = '<p>Texto</p>'): ModeloDePeca
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $modelo = new ModeloDePeca($tenant);
        $modelo->atualizar($titulo, $materia, $tipo, $corpo);
        $em->persist($modelo);
        $em->flush();

        return $modelo;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: Tenant, 2: Pasta} */
    private function entrar(): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $tenant, $pasta];
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function token(Pasta $pasta): string
    {
        return 'TOKEN_modelos_pasta_' . $pasta->getId();
    }

    private function corpo(): string
    {
        return '<p>{cliente}, CPF {cpf}, processo {processo}, pasta {pasta}.</p>';
    }

    #[TestDox('Abrir devolve as chaves do corpo e os valores tirados da pasta; sem dado fica vazio')]
    public function testAbrirComDados(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Com variáveis', corpo: $this->corpo());

        $client->request('GET', '/pasta/' . $pasta->getId() . '/modelos/' . $modelo->getId());
        self::assertResponseIsSuccessful();
        $dados = $this->json($client)['dados'];

        self::assertSame(['cliente', 'cpf', 'processo', 'pasta'], $dados['chaves']);
        self::assertSame('CLIENTE DOS MODELOS', $dados['valores']['cliente']);
        self::assertSame($pasta->getNup(), $dados['valores']['pasta']);
        self::assertSame('', $dados['valores']['cpf']);
    }

    #[TestDox('Usar na pasta: cria documento PECA preenchido, soma 1 em usos e registra o uso')]
    public function testUsar(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Contestação X', corpo: $this->corpo());
        $url    = '/pasta/' . $pasta->getId() . '/modelos/' . $modelo->getId() . '/usar';

        $client->request('POST', $url, ['_token' => $this->token($pasta), 'valores' => ['cpf' => '111.222.333-44']]);
        self::assertResponseStatusCodeSame(201);
        $resp = $this->json($client);
        self::assertSame(1, $resp['usos']);
        self::assertStringContainsString('CONTESTAÇÃO X', $resp['documento']['titulo']);
        self::assertStringContainsString($pasta->getNup(), $resp['documento']['titulo']);

        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM modelo_de_peca_uso WHERE modelo_id = ? AND pasta_id = ? AND documento_id = ?', [$modelo->getId(), $pasta->getId(), $resp['documento']['id']]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT usos FROM modelo_de_peca WHERE id = ?', [$modelo->getId()]));
    }

    #[TestDox('Usar na pasta sem token CSRF é 403 e não grava nada')]
    public function testUsarSemToken(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Sem token', corpo: $this->corpo());

        $client->request('POST', '/pasta/' . $pasta->getId() . '/modelos/' . $modelo->getId() . '/usar', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM modelo_de_peca_uso WHERE modelo_id = ?', [$modelo->getId()]));
    }

    #[TestDox('Modelo de outro escritório: usar e baixar dão 404')]
    public function testOutroEscritorio(): void
    {
        [$client, , $pasta] = $this->entrar();
        [, $outro]          = $this->criarAdmin();
        $alheio             = $this->criarModelo($outro, 'Alheio', corpo: $this->corpo());
        $base               = '/pasta/' . $pasta->getId() . '/modelos/' . $alheio->getId();

        $client->request('POST', $base . '/usar', ['_token' => $this->token($pasta)]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $base . '/word', ['_token' => $this->token($pasta)]);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Word: devolve .docx real (ZIP), do modelo cru ou preenchido')]
    public function testWord(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Para baixar', corpo: $this->corpo());
        $url    = '/pasta/' . $pasta->getId() . '/modelos/' . $modelo->getId() . '/word';

        foreach ([false, true] as $preenchido) {
            $client->request('POST', $url, ['_token' => $this->token($pasta), 'preenchido' => $preenchido ? '1' : '0', 'valores' => ['cliente' => 'Maria']]);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString('.docx', (string) $client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('PK', substr((string) $client->getResponse()->getContent(), 0, 2), 'docx é um ZIP');
        }
    }

    #[TestDox('Word sem token CSRF é 403')]
    public function testWordSemToken(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Sem token', corpo: $this->corpo());

        $client->request('POST', '/pasta/' . $pasta->getId() . '/modelos/' . $modelo->getId() . '/word', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Painel de visualização: seletor, DADOS DA PEÇA e barra de ações são filhos diretos do painel, como no desenho')]
    public function testMarcacaoDaVisualizacao(): void
    {
        [$client, , $pasta] = $this->entrar();

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $ver = '.mdl-dir > #mdlPainelVer';
        self::assertCount(2, $crawler->filter($ver . ' > .mdl-modos > .mdl-seletor > .mdl-seletor-btn'));
        self::assertSame(['Modelo', 'Preenchido com esta pasta'], $crawler->filter($ver . ' .mdl-seletor-btn')->each(static fn ($b): string => trim($b->text())));
        self::assertCount(1, $crawler->filter($ver . ' > #mdlDados.mdl-dados > #mdlDadosGrade'));
        self::assertStringContainsString('DADOS DA PEÇA', $crawler->filter('#mdlDados')->text());
        self::assertStringContainsString('Revise sempre a peça antes de protocolar.', $crawler->filter('#mdlDados')->text());
        self::assertCount(1, $crawler->filter($ver . ' > .mdl-acoes > #mdlCopiar i.bi-copy'));
        self::assertCount(1, $crawler->filter($ver . ' > .mdl-acoes > #mdlWord i.bi-file-earmark-word'));
        self::assertCount(1, $crawler->filter($ver . ' > .mdl-acoes > #mdlUsar i.bi-journal-plus'));
        self::assertStringContainsString('Usar na pasta ' . $pasta->getNup(), $crawler->filter('#mdlUsar')->text());
        self::assertStringContainsString('{cliente}', $crawler->filter('.mdl-dica')->text());
        self::assertCount(0, $crawler->filter('.mdl button:contains("Salvar…")'), 'Salvar… é da M3');
    }
}
