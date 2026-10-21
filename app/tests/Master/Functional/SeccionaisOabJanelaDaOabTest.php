<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\SeccionaisOabController;
use App\Master\Entity\NotaDaCidadeOab;
use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\SeccionalOab;
use App\Master\Entity\SubsecaoOab;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Janela da OAB dos estados que faltam pelo HTTP (spec docs/specs/master-oab-estados-que-faltam.md): adicionar e
 * remover subseção e salvar a nota, só o Master, CSRF `master_seccionais` no header, `{mensagem}`/`{erro}`, auditoria
 * de plataforma; o GET das seccionais traz subseções (por cidade), a nota do estado e as notas das cidades por UF. Mesmo armazém de CSRF fixo do
 * SeccionaisOabControllerTest ('TOKEN_<id>'): o 403 de quem não é Master vem da regra; o CSRF errado tem teste próprio.
 */
#[CoversClass(SeccionaisOabController::class)]
final class SeccionaisOabJanelaDaOabTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $master;
    private User $comum;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();

        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório da janela da OAB ' . uniqid()])->_real();
        $this->master = UserFactory::createOne(['fullName' => 'Master da Janela', 'roles' => ['ROLE_SUPER_ADMIN']])->_real();
        $this->comum = UserFactory::createOne(['fullName' => 'Primário Curioso', 'roles' => ['ROLE_USER']])->_real();
        $this->vincular($this->master);
        $this->vincular($this->comum);
    }

    #[TestDox('GET: toda UF traz subseções (vazia) e nota (nula), e os moldes das três URLs novas')]
    public function testGetTrazOsMoldes(): void
    {
        $this->entrar($this->master);

        $json = $this->seccionais();

        self::assertSame('/master/seccionais/XX/subsecoes', $json['urlSubsecoes']);
        self::assertSame('/master/seccionais/XX/subsecoes/0/remover', $json['urlSubsecaoRemover']);
        self::assertSame('/master/seccionais/XX/nota', $json['urlNota']);
        $ac = self::uf($json, 'AC');
        self::assertSame([], $ac['subsecoes']);
        self::assertNull($ac['nota']);
        self::assertSame([], $ac['notasCidades']);
    }

    #[TestDox('adicionar subseção: 200, aparece no GET da UF e a auditoria fica sem escritório')]
    public function testAdicionarSubsecao(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/TO/subsecoes', ['cidade' => 'Araguaína', 'nome' => 'Subseção de Araguaína', 'endereco' => 'Rua Exemplo, 5', 'telefone' => '(63) 0000-0000']);

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Subseção adicionada na OAB Seccional Tocantins.'], $this->json());
        $to = self::uf($this->seccionais(), 'TO');
        self::assertCount(1, $to['subsecoes']);
        self::assertSame('Araguaína', $to['subsecoes'][0]['cidade']);
        self::assertSame('Subseção de Araguaína', $to['subsecoes'][0]['nome']);
        self::assertSame('Rua Exemplo, 5', $to['subsecoes'][0]['endereco']);
        self::assertSame('(63) 0000-0000', $to['subsecoes'][0]['telefone']);
        self::assertIsInt($to['subsecoes'][0]['id']);
        $subsecao = $this->em->getRepository(SubsecaoOab::class)->find($to['subsecoes'][0]['id']);
        self::assertSame($this->master->getId(), $subsecao?->getAutor()?->getId());
        $this->assertAuditoriaDePlataforma(SubsecaoOab::class);
    }

    #[TestDox('adicionar subseção: sem cidade, sem endereço e nome longo dão 422 com a mensagem e nada é gravado')]
    public function testAdicionarSubsecao422(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/TO/subsecoes', ['nome' => 'Subseção', 'endereco' => 'Rua']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe a cidade da subseção.', $this->json()['erro']);
        $this->postar('/master/seccionais/TO/subsecoes', ['cidade' => 'Palmas', 'nome' => 'Subseção', 'endereco' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o endereço da subseção.', $this->json()['erro']);
        $this->postar('/master/seccionais/TO/subsecoes', ['cidade' => 'Palmas', 'nome' => str_repeat('a', 121), 'endereco' => 'Rua']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O nome passa de 120 caracteres.', $this->json()['erro']);

        self::assertSame(0, $this->subsecoesDe('TO'));
    }

    #[TestDox('adicionar subseção sem nome: vira "Subseção <cidade>"')]
    public function testNomePadrao(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/TO/subsecoes', ['cidade' => 'Gurupi', 'endereco' => 'Rua Um']);

        self::assertResponseIsSuccessful();
        self::assertSame('Subseção Gurupi', self::uf($this->seccionais(), 'TO')['subsecoes'][0]['nome']);
    }

    #[TestDox('remover subseção: 200 e some; id de OUTRA UF ou que não existe dão 404 e nada é apagado')]
    public function testRemoverSubsecao(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/seccionais/PI/subsecoes', ['cidade' => 'Parnaíba', 'nome' => 'Subseção de Parnaíba', 'endereco' => 'Av. Um']);
        $this->postar('/master/seccionais/MA/subsecoes', ['cidade' => 'Imperatriz', 'nome' => 'Subseção de Imperatriz', 'endereco' => 'Av. Dois']);
        $json = $this->seccionais();
        $pi = self::uf($json, 'PI')['subsecoes'][0]['id'];

        $this->postar('/master/seccionais/MA/subsecoes/' . $pi . '/remover', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Subseção não encontrada.', $this->json()['erro']);
        $this->postar('/master/seccionais/PI/subsecoes/999999/remover', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->subsecoesDe('PI'));

        $this->postar('/master/seccionais/PI/subsecoes/' . $pi . '/remover', []);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Subseção removida da OAB Seccional Piauí.'], $this->json());
        self::assertSame(0, $this->subsecoesDe('PI'));
        self::assertSame(1, $this->subsecoesDe('MA'), 'a da outra UF continua');
        $remocao = $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => SubsecaoOab::class, 'action' => 'delete']);
        self::assertCount(1, $remocao);
        self::assertNull($remocao[0]->getTenantId(), 'ato de plataforma: sem escritório');
    }

    #[TestDox('salvar nota do estado: 200, o GET traz texto e data com hora; reescrever troca a mesma; vazio apaga')]
    public function testSalvarNota(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/RR/nota', ['texto' => '  Contato com a presidente da subseção  ']);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Nota da OAB Seccional Roraima salva.'], $this->json());
        $nota = self::uf($this->seccionais(), 'RR')['nota'];
        self::assertSame('Contato com a presidente da subseção', $nota['texto']);
        self::assertSame(['texto', 'atualizadaEm'], array_keys($nota));
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $nota['atualizadaEm']), 'data com hora (o painel mostra "Nota salva em dd/mm/aaaa, hh:mm")');
        $this->assertAuditoriaDePlataforma(NotaDaSeccional::class);

        $this->postar('/master/seccionais/RR/nota', ['texto' => 'Enviar material em novembro']);
        self::assertResponseIsSuccessful();
        self::assertSame('Enviar material em novembro', self::uf($this->seccionais(), 'RR')['nota']['texto']);
        self::assertSame(1, $this->em->getRepository(NotaDaSeccional::class)->count([]), 'uma nota por UF');

        $this->postar('/master/seccionais/RR/nota', ['texto' => '   ']);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Nota da OAB Seccional Roraima apagada.'], $this->json());
        self::assertNull(self::uf($this->seccionais(), 'RR')['nota']);
        self::assertSame(0, $this->em->getRepository(NotaDaSeccional::class)->count([]));
    }

    #[TestDox('nota da cidade: 200, sai em notasCidades da UF, separada da nota do estado; uma por cidade; vazio apaga')]
    public function testNotaDaCidade(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/AP/nota', ['texto' => 'Falar com a subseção', 'cidade' => 'Macapá']);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Nota de Macapá salva.'], $this->json());
        $ap = self::uf($this->seccionais(), 'AP');
        self::assertNull($ap['nota'], 'a nota da cidade não é a do estado');
        self::assertSame(['Macapá'], array_keys($ap['notasCidades']));
        self::assertSame('Falar com a subseção', $ap['notasCidades']['Macapá']['texto']);
        $this->assertAuditoriaDePlataforma(NotaDaCidadeOab::class);

        $this->postar('/master/seccionais/AP/nota', ['texto' => 'Reescrita', 'cidade' => 'Macapá']);
        self::assertSame('Reescrita', self::uf($this->seccionais(), 'AP')['notasCidades']['Macapá']['texto']);
        self::assertSame(1, $this->em->getRepository(NotaDaCidadeOab::class)->count(['cidade' => 'Macapá']), 'uma nota por UF e cidade');

        $this->postar('/master/seccionais/AP/nota', ['texto' => '', 'cidade' => 'Macapá']);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::uf($this->seccionais(), 'AP')['notasCidades']);
    }

    #[TestDox('salvar nota: acima de 1000 caracteres dá 422 com a mensagem e nada é gravado')]
    public function testSalvarNota422(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/RR/nota', ['texto' => str_repeat('a', 1001)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A nota passa de 1000 caracteres.', $this->json()['erro']);
        self::assertNull(self::uf($this->seccionais(), 'RR')['nota']);
    }

    #[TestDox('quem não é Master: 403 no GET e nos três POST, e nada é gravado')]
    public function testNaoMaster403(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/seccionais/GO/subsecoes', ['cidade' => 'Anápolis', 'nome' => 'Subseção', 'endereco' => 'Rua']);
        $id = self::uf($this->seccionais(), 'GO')['subsecoes'][0]['id'];
        $this->entrar($this->comum);

        $this->client->request('GET', '/master/dados/seccionais', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/GO/subsecoes', ['cidade' => 'Anápolis', 'nome' => 'Invasora', 'endereco' => 'Rua']);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/GO/subsecoes/' . $id . '/remover', []);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/GO/nota', ['texto' => 'Invasora']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->subsecoesDe('GO'));
        self::assertSame(0, $this->em->getRepository(NotaDaSeccional::class)->count([]));
    }

    #[TestDox('CSRF errado ou de outra ação: 403 nos três POST e nada muda')]
    public function testCsrfInvalido(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/seccionais/GO/subsecoes', ['cidade' => 'Anápolis', 'nome' => 'Subseção', 'endereco' => 'Rua']);
        $id = self::uf($this->seccionais(), 'GO')['subsecoes'][0]['id'];

        $this->postar('/master/seccionais/GO/subsecoes', ['cidade' => 'Anápolis', 'nome' => 'Outra', 'endereco' => 'Rua'], token: 'errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token CSRF inválido. Recarregue a página.', $this->json()['erro']);
        $this->postar('/master/seccionais/GO/subsecoes/' . $id . '/remover', [], token: 'TOKEN_master_fontes');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/GO/nota', ['texto' => 'Nota'], token: '');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->subsecoesDe('GO'));
        self::assertSame(0, $this->em->getRepository(NotaDaSeccional::class)->count([]));
    }

    #[TestDox('UF fora das 27: 404 nos três POST')]
    public function testUfInvalida404(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/XX/subsecoes', ['cidade' => 'X', 'nome' => 'Subseção', 'endereco' => 'Rua']);
        self::assertResponseStatusCodeSame(404);
        $this->postar('/master/seccionais/ZZ/subsecoes/1/remover', []);
        self::assertResponseStatusCodeSame(404);
        $this->postar('/master/seccionais/XX/nota', ['texto' => 'Nota']);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('trilha "Segurança e auditoria": subseção adicionada e nota gravada aparecem como "Plataforma (sem conta)"')]
    public function testTrilhaDoMaster(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/seccionais/SE/subsecoes', ['cidade' => 'Itabaiana', 'nome' => 'Subseção de Itabaiana', 'endereco' => 'Rua']);
        $this->postar('/master/seccionais/SE/nota', ['texto' => 'Nota']);

        $this->client->request('GET', '/master/dados/auditoria', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $registros = $this->json()['registros'];

        foreach (['Subseção da OAB adicionada', 'Nota da seccional gravada'] as $acao) {
            $achados = array_values(array_filter($registros, static fn (array $r): bool => $r['acao'] === $acao));
            self::assertNotEmpty($achados, 'a trilha mostra "' . $acao . '"');
            self::assertSame('Plataforma (sem conta)', $achados[0]['conta']);
            self::assertSame('Master da Janela', $achados[0]['quem']);
        }
    }

    #[TestDox('trilha: reescrever a nota mostra "Campos alterados: texto" (os carimbos de quem e quando não contam)')]
    public function testTrilhaDaNotaReescrita(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/seccionais/AL/nota', ['texto' => 'Primeira']);
        self::assertResponseIsSuccessful();
        $this->postar('/master/seccionais/AL/nota', ['texto' => 'Segunda']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/master/dados/auditoria', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $alteradas = array_values(array_filter($this->json()['registros'], static fn (array $r): bool => $r['acao'] === 'Nota da seccional alterada'));

        self::assertNotEmpty($alteradas, 'a trilha mostra "Nota da seccional alterada"');
        self::assertSame('Campos alterados: texto', $alteradas[0]['detalhe']);
        self::assertSame('Plataforma (sem conta)', $alteradas[0]['conta']);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function uf(array $json, string $uf): array
    {
        $achadas = array_values(array_filter($json['seccionais'], static fn (array $s): bool => $s['uf'] === $uf));
        self::assertCount(1, $achadas);

        return $achadas[0];
    }

    /** @return array<string, mixed> */
    private function seccionais(): array
    {
        $this->client->request('GET', '/master/dados/seccionais', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function assertAuditoriaDePlataforma(string $classe): void
    {
        $linhas = $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => $classe]);
        self::assertNotEmpty($linhas, 'a gravação entra no audit_log');
        foreach ($linhas as $linha) {
            self::assertNull($linha->getTenantId(), 'ato de plataforma: sem escritório');
        }
    }

    private function subsecoesDe(string $uf): int
    {
        $this->em->clear();
        $seccional = $this->em->getRepository(SeccionalOab::class)->findOneBy(['uf' => $uf]);
        self::assertInstanceOf(SeccionalOab::class, $seccional);

        return $this->em->getRepository(SubsecaoOab::class)->count(['seccional' => $seccional]);
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo, string $token = 'TOKEN_master_seccionais'): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    private function vincular(User $user): void
    {
        $perfil = (new TenantRole())->setTenant($this->escritorio)->setName('Administrador do Escritório ' . uniqid())->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($perfil));
        $this->em->flush();
    }

    private function instalarCsrfFixo(): void
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
