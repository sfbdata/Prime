<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Controller\DespachoDaMetaController;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Despacho da meta, Fase 2a (spec `minhas-metas-esteira-2210.md`, C9 a C15): Despachar com validação no servidor e
 * código do registro, ciência ao abrir, certidão de cumprimento e validação do código só no escritório.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(DespachoDaMetaController::class)]
#[Group('tarefa')]
final class DespachoDaMetaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private User $colega;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Um kernel só no teste inteiro: o armazenamento falso do CSRF e o EntityManager valem em todas as requisições.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFalso();

        $this->escritorio = $this->criarEscritorio('Maceió');
        $this->criador = $this->criarPessoa($this->escritorio, 'Carla Dias');
        $this->responsavel = $this->criarPessoa($this->escritorio, 'Rui Prado');
        $this->colega = $this->criarPessoa($this->escritorio, 'Olga Lima');
        $this->meta = $this->criarMeta($this->escritorio, $this->criador, $this->responsavel);
    }

    #[TestDox('Despachar grava texto em HTML, natureza, prazo e um código XXXX.XXXX')]
    public function testDespacharGeraCodigo(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $prazo = (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');

        $this->despachar(['mensagem' => "Cumpra-se:\r\njuntar a procuração.", 'natureza' => 'cumpra_se', 'prazo' => $prazo]);

        self::assertResponseRedirects('/tarefas/' . $this->meta->getId());
        $despacho = $this->unicoDespacho();
        self::assertMatchesRegularExpression('/^\d{4}\.\d{4}$/', (string) $despacho->getCodigo());
        self::assertSame('<p>Cumpra-se:<br />juntar a procuração.</p>', $despacho->getMensagem());
        self::assertSame(NaturezaDoDespacho::CumpraSe, $despacho->getNatureza());
        self::assertSame($prazo, $despacho->getPrazoCumprimento()?->format('Y-m-d'));
    }

    #[TestDox('5.000 caracteres passam; 5.001 são recusados no servidor, sem despacho')]
    public function testLimiteDeCincoMil(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => str_repeat('a', 5001)]);
        self::assertResponseRedirects();
        self::assertCount(0, $this->despachosDaMeta());

        $this->despachar(['mensagem' => str_repeat('a', 4998) . "\r\nb"]);
        self::assertCount(1, $this->despachosDaMeta(), 'a quebra de linha conta um caractere, não dois');
    }

    #[TestDox('Prazo antes de hoje é recusado, sem despacho')]
    public function testPrazoNoPassado(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar(['mensagem' => 'Ok', 'prazo' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->despachosDaMeta());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'não pode ser antes de hoje');
    }

    #[TestDox('A tela mostra o despacho com número, natureza, local e data, registro e link de validação')]
    public function testTelaMostraORegistro(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar(['mensagem' => 'Para ciência: segue.', 'natureza' => 'ciencia']);
        $codigo = (string) $this->unicoDespacho()->getCodigo();

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.despacho-numero', 'DESPACHO 1');
        self::assertSelectorTextContains('.despacho-tipo', 'Para ciência');
        self::assertSelectorTextContains('.despacho-local', 'Maceió,');
        self::assertSelectorTextContains('.despacho-registro', 'Registrado por CARLA DIAS');
        self::assertSelectorTextNotContains('.despacho-registro', 'Assinado');
        self::assertCount(1, $crawler->filter('.despacho-registro a[href="/tarefas/registro/' . $codigo . '"]'));
        self::assertSelectorExists('form[data-despacho] input[type="date"][name="prazo"][min]');
    }

    #[TestDox('Ciência: o responsável abre e marca o despacho do criador; quem só olha não marca')]
    public function testCiencia(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar(['mensagem' => 'Para ciência.']);
        $id = (int) $this->unicoDespacho()->getId();

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertResponseIsSuccessful();
        self::assertNull($this->despacho($id)->getCienteEm(), 'colega que só olha não dá ciência');

        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertSame($this->responsavel->getId(), $this->despacho($id)->getCientePor()?->getId());
        self::assertSelectorTextContains('.despacho-ciente', 'Ciente · Rui,');
    }

    #[TestDox('Certidão: responsável certifica; colega recebe 403; outro escritório recebe 404')]
    public function testCertidao(): void
    {
        $despacho = $this->despachoGravado($this->criador);
        $id = (int) $despacho->getId();

        $outroEscritorio = $this->criarEscritorio(null);
        $estranho = $this->criarPessoa($outroEscritorio, 'Ivo Reis');
        $this->logarComTenant($this->client, $estranho, $outroEscritorio);
        $this->certificar($id, 'Certifico que fiz.');
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->certificar($id, 'Certifico que fiz.');
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);
        $this->certificar($id, 'Começo errado.');
        self::assertNull($this->despacho($id)->getCertidao(), 'sem "Certifico que" não grava');

        $this->certificar($id, 'Certifico que juntei a procuração.');
        self::assertResponseRedirects();
        self::assertSame('Certifico que juntei a procuração.', $this->despacho($id)->getCertidao());
        self::assertSame($this->responsavel->getId(), $this->despacho($id)->getCertificadoPor()?->getId());
    }

    #[TestDox('Validação do código: confere no escritório, sem o texto; outro escritório e código inexistente dão 404')]
    public function testValidacaoDoCodigo(): void
    {
        $despacho = $this->despachoGravado($this->criador, NaturezaDoDespacho::Providencia);
        $codigo = (string) $despacho->getCodigo();

        $this->logarComTenant($this->client, $this->colega, $this->escritorio);
        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Carla Dias');
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Para providência');
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Sócia');
        self::assertSelectorTextNotContains('[data-registro-encontrado]', 'Texto secreto do despacho');

        $this->client->request('GET', '/tarefas/registro/0000.0000');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-registro-nao-encontrado]');

        $outroEscritorio = $this->criarEscritorio(null);
        $estranho = $this->criarPessoa($outroEscritorio, 'Ivo Reis');
        $this->logarComTenant($this->client, $estranho, $outroEscritorio);
        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-registro-nao-encontrado]');
        self::assertSelectorTextNotContains('body', 'Carla Dias');
    }

    #[TestDox('O registro congela o nome: renomear quem despachou não muda a linha nem a validação')]
    public function testRegistroCongelaONome(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar(['mensagem' => 'Ok.']);
        $codigo = (string) $this->unicoDespacho()->getCodigo();
        $criador = $this->em->find(User::class, $this->criador->getId());
        $criador?->setFullName('Carla Dias Mendes');
        $this->em->flush();

        $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertSelectorTextContains('.despacho-registro', 'Registrado por CARLA DIAS em');

        $this->client->request('GET', '/tarefas/registro/' . $codigo);
        self::assertSelectorTextContains('[data-registro-encontrado]', 'Carla Dias');
        self::assertSelectorTextNotContains('[data-registro-encontrado]', 'Mendes');
    }

    #[TestDox('Despacho registrado não se edita: sem botão na tela e 422 no POST; o texto não muda')]
    public function testRegistradoNaoEdita(): void
    {
        $despacho = $this->despachoGravado($this->criador);
        $id = (int) $despacho->getId();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        self::assertCount(0, $crawler->filter('#despacho-' . $id . ' .btn-editar-msg'));

        $this->client->request('POST', '/tarefas/mensagem/' . $id . '/editar', [
            '_token'   => 'TOKEN_editar_mensagem_tarefa_' . $id,
            'conteudo' => 'Texto trocado',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Despacho registrado não pode ser editado.', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);
        self::assertSame('<p>Texto secreto do despacho</p>', $this->despacho($id)->getMensagem());
    }

    #[TestDox('Mensagem antiga, sem código, continua com o botão de editar')]
    public function testAntigaContinuaEditavel(): void
    {
        $antiga = (new TarefaMensagem())->setUsuario($this->criador)->setTenant($this->escritorio)->setMensagem('Antiga');
        $this->meta->addMensagem($antiga);
        $this->em->persist($antiga);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $crawler = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertCount(1, $crawler->filter('#despacho-' . $antiga->getId() . ' .btn-editar-msg'));
    }

    /** @param array<string, string> $campos */
    private function despachar(array $campos): void
    {
        $id = (int) $this->meta->getId();
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', $campos + ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => '']);
    }

    private function certificar(int $despachoId, string $texto): void
    {
        $this->client->request('POST', '/tarefas/mensagem/' . $despachoId . '/certificar', [
            '_token'   => 'TOKEN_certificar_despacho_' . $despachoId,
            'certidao' => $texto,
        ]);
    }

    private function despachoGravado(User $autor, ?NaturezaDoDespacho $natureza = null): TarefaMensagem
    {
        $despacho = (new TarefaMensagem())->setUsuario($autor)->setTenant($this->escritorio)->setMensagem('<p>Texto secreto do despacho</p>');
        $despacho->setNatureza($natureza)->registrar(sprintf('%04d.%04d', random_int(0, 9999), random_int(0, 9999)), (string) $autor->getFullName(), 'Sócia');
        $this->meta->addMensagem($despacho);
        $this->em->persist($despacho);
        $this->em->flush();

        return $despacho;
    }

    /** @return list<TarefaMensagem> */
    private function despachosDaMeta(): array
    {
        $this->em->clear();

        return $this->em->getRepository(TarefaMensagem::class)->findBy(['tarefa' => $this->meta->getId()]);
    }

    private function unicoDespacho(): TarefaMensagem
    {
        $despachos = $this->despachosDaMeta();
        self::assertCount(1, $despachos);

        return $despachos[0];
    }

    private function despacho(int $id): TarefaMensagem
    {
        $this->em->clear();

        return $this->em->find(TarefaMensagem::class, $id) ?? throw new \LogicException('Despacho sumiu.');
    }

    private function criarEscritorio(?string $cidade): Tenant
    {
        $tenant = (new Tenant())->setCidade($cidade)->setEstado('AL');
        $tenant->setName('Escritório Despacho ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarPessoa(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('despacho_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $this->em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function criarMeta(Tenant $tenant, User $criador, User $responsavel): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-DESP-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $this->em->persist($pasta);

        $meta = new Tarefa();
        $meta->setTitulo('Meta do despacho');
        $meta->setDescricao('Descrição');
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $meta->addResponsavel($responsavel);
        $this->em->persist($meta);
        $this->em->flush();

        return $meta;
    }

    private function instalarCsrfFalso(): void
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
