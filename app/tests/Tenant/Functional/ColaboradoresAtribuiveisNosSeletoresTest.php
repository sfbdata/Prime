<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\PastaController;
use App\Controller\ServiceDeskController;
use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Seletores de responsável com a equipe da conta (spec docs/specs/colaboradores-atribuiveis.md): quem está suspenso
 * não é oferecido nem aceito como atribuição NOVA, e quem já estava no registro continua ao editar.
 * Ana está aprovada; Carla está suspensa e já atribuída; Bruna está suspensa e não está em nada.
 */
#[CoversClass(ColaboradoresAtribuiveis::class)]
#[CoversClass(TarefaController::class)]
#[CoversClass(ServiceDeskController::class)]
#[CoversClass(PastaController::class)]
final class ColaboradoresAtribuiveisNosSeletoresTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $gestor;
    private User $ana;
    private User $carla;
    private User $bruna;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório Atribuíveis ' . uniqid());
        $this->em->persist($this->tenant);
        $sistema    = $this->perfil(true);
        $secundario = $this->perfil(false);

        $this->gestor = $this->membro('Gestor Primário', $sistema);
        $this->ana    = $this->membro('Ana Aprovada', $secundario);
        $this->carla  = $this->membro('Carla Suspensa', $secundario, SituacaoNaEquipe::Suspenso);
        $this->bruna  = $this->membro('Bruna Suspensa', $secundario, SituacaoNaEquipe::Suspenso);

        $this->pasta = new Pasta();
        $this->pasta->setNup('ATR-' . uniqid());
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setCriadoPor($this->gestor);
        $this->pasta->setResponsavel($this->carla);
        $this->em->persist($this->pasta);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->gestor, $this->tenant);
    }

    #[TestDox('meta: a lista oferece aprovados e a suspensa já atribuída; editar preserva ela e recusa a suspensa nova')]
    public function testMetaPreservaASuspensaAtribuida(): void
    {
        $tarefa = $this->meta([$this->carla]);

        $tela  = $this->client->request('GET', '/tarefas/' . $tarefa->getId());
        $nomes = $tela->filter('#listaResponsaveis .resp-item')->each(static fn ($l): string => trim($l->text()));
        self::assertSame([true, true, false], [
            $this->contem($nomes, 'Ana Aprovada'), $this->contem($nomes, 'Carla Suspensa'), $this->contem($nomes, 'Bruna Suspensa'),
        ]);

        $token = (string) $tela->filter('form[action$="/responsaveis"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tarefas/' . $tarefa->getId() . '/responsaveis', [
            '_token'       => $token,
            'responsaveis' => [$this->carla->getId(), $this->bruna->getId(), $this->ana->getId()],
        ]);
        self::assertTrue($this->client->getResponse()->isRedirection() || $this->client->getResponse()->isSuccessful());

        self::assertSame(['Ana Aprovada', 'Carla Suspensa'], $this->responsaveisDaMeta((int) $tarefa->getId()));
    }

    #[TestDox('meta nova pela pasta: a suspensa enviada pelo navegador não entra')]
    public function testMetaNovaRecusaSuspensa(): void
    {
        $tela  = $this->client->request('GET', '/pasta/' . $this->pasta->getId());
        $token = (string) $tela->filter('input[name="_token"]')->reduce(
            static fn ($i): bool => str_contains((string) $i->closest('form')?->attr('action'), '/tarefas/pasta/'),
        )->attr('value');

        $this->client->request('POST', '/tarefas/pasta/' . $this->pasta->getId() . '/criar', [
            '_token'       => $token,
            'titulo'       => 'Meta nova',
            'descricao'    => 'Descrição da meta',
            'responsaveis' => [$this->ana->getId(), $this->bruna->getId()],
        ]);
        self::assertResponseIsSuccessful();

        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        self::assertSame(['Ana Aprovada'], $this->responsaveisDaMeta($id));
    }

    #[TestDox('chamado: a suspensa responsável aparece selecionada, reenviar mantém, e uma suspensa nova dá 404')]
    public function testChamadoPreservaResponsavelSuspensa(): void
    {
        $chamado = $this->chamado($this->carla);

        $tela    = $this->client->request('GET', '/servicedesk/' . $chamado->getId());
        $opcao   = $tela->filter('select[name="responsavel_id"] option[selected]');
        self::assertSame((string) $this->carla->getId(), $opcao->attr('value'), 'sem isso o formulário mandaria "Não atribuído"');
        self::assertCount(0, $tela->filter('select[name="responsavel_id"] option[value="' . $this->bruna->getId() . '"]'));
        $token = (string) $tela->filter('form[action$="/atribuir"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/servicedesk/' . $chamado->getId() . '/atribuir', ['_token' => $token, 'responsavel_id' => $this->carla->getId()]);
        self::assertResponseRedirects();
        $this->client->request('POST', '/servicedesk/' . $chamado->getId() . '/atribuir', ['_token' => $token, 'responsavel_id' => $this->bruna->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertSame($this->carla->getId(), $this->em->find(Chamado::class, $chamado->getId())?->getResponsavel()?->getId());
    }

    #[TestDox('pasta: o menu tem a suspensa responsável e não a suspensa nova; escolher de novo a atual não dá 404')]
    public function testPastaPreservaResponsavelSuspensa(): void
    {
        $tela  = $this->client->request('GET', '/pasta/' . $this->pasta->getId());
        $ids   = $tela->filter('[data-user-id]')->each(static fn ($i): string => (string) $i->attr('data-user-id'));
        self::assertContains((string) $this->carla->getId(), $ids);
        self::assertContains((string) $this->ana->getId(), $ids);
        self::assertNotContains((string) $this->bruna->getId(), $ids);
        $token = (string) $tela->filter('.pasta-resp-chip')->attr('data-csrf');

        $this->client->request('PATCH', '/pasta/' . $this->pasta->getId() . '/responsavel', ['_token' => $token, 'responsavel_id' => $this->carla->getId()]);
        self::assertResponseIsSuccessful();
        $this->client->request('PATCH', '/pasta/' . $this->pasta->getId() . '/responsavel', ['_token' => $token, 'responsavel_id' => $this->bruna->getId()]);
        self::assertResponseStatusCodeSame(404);
    }

    /** @param list<string> $nomes */
    private function contem(array $nomes, string $nome): bool
    {
        return array_filter($nomes, static fn (string $n): bool => str_contains($n, $nome)) !== [];
    }

    /** @return list<string> */
    private function responsaveisDaMeta(int $id): array
    {
        $this->em->clear();
        $nomes = array_map(static fn (User $u): string => (string) $u->getFullName(), $this->em->find(Tarefa::class, $id)?->getResponsaveis()->toArray() ?? []);
        sort($nomes);

        return $nomes;
    }

    /** @param list<User> $responsaveis */
    private function meta(array $responsaveis): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta ' . uniqid());
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($this->pasta);
        $tarefa->setTenant($this->tenant);
        $tarefa->setCriadoPor($this->gestor);
        foreach ($responsaveis as $u) {
            $tarefa->addResponsavel($u);
        }
        $this->em->persist($tarefa);
        $this->em->flush();

        return $tarefa;
    }

    private function chamado(User $responsavel): Chamado
    {
        $chamado = new Chamado();
        $chamado->setTitulo('Chamado ' . uniqid());
        $chamado->setDescricao('Descrição');
        $chamado->setCategoria(Chamado::CATEGORIA_SOFTWARE);
        $chamado->setPrioridade(Chamado::PRIORIDADE_MEDIA);
        $chamado->setStatus(Chamado::STATUS_EM_ANDAMENTO);
        $chamado->setSolicitante($this->gestor);
        $chamado->setResponsavel($responsavel);
        $chamado->setTenant($this->tenant);
        $this->em->persist($chamado);
        $this->em->flush();

        return $chamado;
    }

    private function perfil(bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName(($sistema ? 'Administrador ' : 'Equipe ') . uniqid());
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        return $perfil;
    }

    private function membro(string $nome, TenantRole $perfil, SituacaoNaEquipe $situacao = SituacaoNaEquipe::Aprovado): User
    {
        $u = new User();
        $u->setEmail('atr_' . uniqid() . '@test.com');
        $u->setFullName($nome);
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);
        $vinculo = new UserTenant($u, $this->tenant);
        $vinculo->setTenantRole($perfil);
        $vinculo->alterarSituacao($situacao);
        $this->em->persist($vinculo);

        return $u;
    }
}
