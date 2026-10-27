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
 * Banco de modelos da aba Modelos (M1): lista com filtros, abrir, criar, editar, excluir, favoritar,
 * isolamento por escritório, CSRF e a marcação da aba (cartão do desenho, Relatório inicial recolhido).
 */
#[CoversClass(PastaModelosController::class)]
#[Group('pasta')]
final class PastaModelosControllerTest extends JusPrimeWebTestCase
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

    #[TestDox('A aba Modelos: cartão do desenho com coluna e painel como filhos diretos do corpo; Relatório inicial recolhido; sem moldura do AdminLTE')]
    public function testArranjoDaAba(): void
    {
        [$client, , $pasta] = $this->entrar();

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#detalhes .mdl .mdl-cartao > .mdl-cab > b.mdl-cab-titulo'));
        self::assertSame('Banco de modelos de petições', trim($crawler->filter('.mdl-cab-titulo')->text()));
        self::assertCount(1, $crawler->filter('.mdl-cartao > .mdl-corpo > .mdl-esq'));
        self::assertCount(1, $crawler->filter('.mdl-cartao > .mdl-corpo > .mdl-dir'));
        self::assertCount(1, $crawler->filter('.mdl-dir > #mdlPainelLista > .mdl-barra > #mdlNovo'));
        self::assertCount(16, $crawler->filter('#mdlCampoMateria option'));
        self::assertCount(14, $crawler->filter('#mdlCampoTipo option'));
        self::assertCount(15, $crawler->filter('#mdlTipo option'), 'Todos os tipos + 14');
        self::assertSame(['Mais usados', 'Mais recentes', 'A a Z'], $crawler->filter('#mdlOrdem option')->each(static fn ($o): string => trim($o->text())));

        $detalhes = $crawler->filter('#detalhes > details.mdl-relatorio');
        self::assertCount(1, $detalhes);
        self::assertNull($detalhes->attr('open'), 'O Relatório inicial nasce recolhido');
        self::assertSame('Relatório inicial da pasta (objetivos, acordos e resultado esperado)', trim($detalhes->filter('summary')->text()));
        self::assertCount(1, $crawler->filter('#detalhes > details.mdl-relatorio .ps-grade'), 'O Relatório continua por dentro');

        self::assertCount(0, $crawler->filter('#detalhes .mdl .card, #detalhes .mdl .modal, #detalhes .mdl .select2'));
        self::assertSame('Modelos', trim($crawler->filter('#detalhes-tab')->text()));
        self::assertCount(1, $crawler->filter('#detalhes-tab i.bi-journal-text'));
    }

    #[TestDox('Lista: só os modelos do escritório, com contadores; filtro por matéria, tipo e busca no corpo; ordenação A a Z')]
    public function testListaComFiltros(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $this->criarModelo($tenant, 'Cobrança de cotas', MateriaDeModelo::Condominial, TipoDePeca::PeticaoInicial, '<p>cotas atrasadas</p>');
        $this->criarModelo($tenant, 'Contestação geral', MateriaDeModelo::Civel, TipoDePeca::Contestacao, '<p>impugno</p>');
        [, $outro] = $this->criarAdmin();
        $this->criarModelo($outro, 'Modelo de outro escritório');
        $url = '/pasta/' . $pasta->getId() . '/modelos';

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertSame(2, $dados['total']);
        self::assertStringContainsString('2 modelos', $dados['contador']);
        self::assertStringNotContainsString('outro escritório', $dados['lista']);
        self::assertStringContainsString('Contestação · do escritório', $dados['lista']);

        $client->request('GET', $url . '?materia=condominial');
        self::assertStringContainsString('Cobrança de cotas', $this->json($client)['lista']);
        self::assertStringNotContainsString('Contestação geral', $this->json($client)['lista']);

        $client->request('GET', $url . '?tipo=contestacao');
        self::assertStringNotContainsString('Cobrança de cotas', $this->json($client)['lista']);

        $client->request('GET', $url . '?q=ATRASADAS');
        self::assertStringContainsString('Cobrança de cotas', $this->json($client)['lista']);
        self::assertStringNotContainsString('Contestação geral', $this->json($client)['lista']);

        $client->request('GET', $url . '?materia=civel&q=zzz');
        self::assertStringContainsString('Nenhum modelo encontrado.', $this->json($client)['lista']);

        $client->request('GET', $url . '?ord=az');
        $lista = $this->json($client)['lista'];
        self::assertLessThan(strpos($lista, 'Contestação geral'), strpos($lista, 'Cobrança de cotas'));
    }

    #[TestDox('Coluna esquerda: contadores por matéria e bloco, matéria ativa e zero sem número')]
    public function testColuna(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $this->criarModelo($tenant, 'A', MateriaDeModelo::Civel);
        $this->criarModelo($tenant, 'B', MateriaDeModelo::Saude);

        $client->request('GET', '/pasta/' . $pasta->getId() . '/modelos?materia=saude');
        $html = new \Symfony\Component\DomCrawler\Crawler($this->json($client)['coluna']);

        self::assertSame('2', trim($html->filter('[data-materia=""] .mdl-chip-contador')->text()));
        self::assertSame('2', trim($html->filter('[data-bloco="contencioso_civel"] .mdl-bloco-total')->text()));
        self::assertCount(1, $html->filter('.mdl-chip--ativo[data-materia="saude"]'));
        self::assertSame('', trim($html->filter('[data-materia="trabalhista"] .mdl-chip-contador')->text()));
        self::assertCount(5, $html->filter('.mdl-bloco-tit'));
        self::assertCount(16, $html->filter('.mdl-chip[data-materia][data-materia!=""]:not([data-materia="★"])'));
    }

    #[TestDox('Criar: grava no escritório, sanitiza o corpo e devolve 201; título vazio devolve 422 com a mensagem')]
    public function testCriar(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $url = '/pasta/' . $pasta->getId() . '/modelos';

        $client->request('POST', $url, ['_token' => $this->token($pasta), 'titulo' => 'Novo', 'materia' => 'familia', 'tipo' => 'acordo', 'corpo' => '<p onclick="x()">Olá</p><script>alert(1)</script>']);
        self::assertResponseStatusCodeSame(201);
        $modelo = $this->json($client)['modelo'];
        self::assertSame('<p>Olá</p>', $modelo['corpo']);
        self::assertSame('Família', $modelo['materiaRotulo']);

        $salvo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(ModeloDePeca::class)->find($modelo['id']);
        self::assertSame($tenant->getId(), $salvo->getTenant()->getId());

        $client->request('POST', $url, ['_token' => $this->token($pasta), 'titulo' => '  ', 'materia' => 'familia', 'tipo' => 'acordo', 'corpo' => '<p>x</p>']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o título.', $this->json($client)['erro']);
    }

    #[TestDox('Editar muda o conteúdo; abrir devolve o modelo; excluir apaga')]
    public function testEditarAbrirExcluir(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Antigo');
        $base   = '/pasta/' . $pasta->getId() . '/modelos/' . $modelo->getId();

        $client->request('POST', $base, ['_token' => $this->token($pasta), 'titulo' => 'Novo nome', 'materia' => 'recursos', 'tipo' => 'recurso', 'corpo' => '<p>Outro</p>']);
        self::assertResponseIsSuccessful();

        $client->request('GET', $base);
        self::assertSame('Novo nome', $this->json($client)['modelo']['titulo']);
        self::assertSame('recursos', $this->json($client)['modelo']['materia']);

        $client->request('POST', $base . '/excluir', ['_token' => $this->token($pasta)]);
        self::assertResponseIsSuccessful();
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Modelo de outro escritório: 404 ao abrir, editar, excluir e favoritar, e nada muda')]
    public function testOutroEscritorio(): void
    {
        [$client, , $pasta] = $this->entrar();
        [, $outro]          = $this->criarAdmin();
        $deOutro            = $this->criarModelo($outro, 'Intocável');
        $base               = '/pasta/' . $pasta->getId() . '/modelos/' . $deOutro->getId();

        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $base, ['_token' => $this->token($pasta), 'titulo' => 'X', 'materia' => 'civel', 'tipo' => 'parecer', 'corpo' => '<p>x</p>']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $base . '/excluir', ['_token' => $this->token($pasta)]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $base . '/favorito', ['_token' => $this->token($pasta)]);
        self::assertResponseStatusCodeSame(404);

        $titulo = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT titulo FROM modelo_de_peca WHERE id = ?', [$deOutro->getId()]);
        self::assertSame('Intocável', $titulo, 'Nada mudou e nada foi apagado');
    }

    #[TestDox('Sem token CSRF válido as escritas devolvem 403 e nada é gravado')]
    public function testSemCsrf(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Fica');
        $base   = '/pasta/' . $pasta->getId() . '/modelos';

        $client->request('POST', $base, ['_token' => 'errado', 'titulo' => 'X', 'materia' => 'civel', 'tipo' => 'parecer', 'corpo' => '<p>x</p>']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $base . '/' . $modelo->getId() . '/excluir', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $base . '/' . $modelo->getId() . '/favorito');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM modelo_de_peca WHERE id = ?', [$modelo->getId()]));
    }

    #[TestDox('Favoritos por usuário: estrela do modelo e da matéria alternam e aparecem na coluna e no filtro ★')]
    public function testFavoritos(): void
    {
        [$client, $tenant, $pasta] = $this->entrar();
        $modelo = $this->criarModelo($tenant, 'Com estrela', MateriaDeModelo::Saude);
        $this->criarModelo($tenant, 'Sem estrela', MateriaDeModelo::Civel);
        $base = '/pasta/' . $pasta->getId() . '/modelos';

        $client->request('POST', $base . '/' . $modelo->getId() . '/favorito', ['_token' => $this->token($pasta)]);
        self::assertTrue($this->json($client)['favorito']);
        $client->request('POST', $base . '/materias/saude/favorita', ['_token' => $this->token($pasta)]);
        self::assertTrue($this->json($client)['favorita']);
        $client->request('POST', $base . '/materias/astrologia/favorita', ['_token' => $this->token($pasta)]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', $base . '?materia=★');
        $dados = $this->json($client);
        self::assertStringContainsString('Com estrela', $dados['lista']);
        self::assertStringNotContainsString('Sem estrela', $dados['lista']);
        self::assertStringContainsString('Matérias favoritas', $dados['coluna']);

        $client->request('POST', $base . '/' . $modelo->getId() . '/favorito', ['_token' => $this->token($pasta)]);
        self::assertFalse($this->json($client)['favorito']);
    }
}
