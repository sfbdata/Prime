<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Tarefa\Controller\AprendizadoDasMetasController;
use App\Tarefa\Controller\PassagemDeEtapaController;
use App\Tarefa\Entity\AprendizadoDaPendencia;
use App\Tarefa\Entity\AprendizadoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Aprendizado de Minhas Metas, Fase 4 (spec `minhas-metas-esteira-2210.md`, 1.1 itens 1 e 2, C2 e D4; desenho 04B de
 * 22/10, L61, L99 e L145-173): o despacho e a passagem ensinam, a página e o modal sugerem, só para a própria pessoa
 * no escritório da sessão; a chave do Meu Perfil desliga tudo e o "Apagar o que foi aprendido" limpa.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(PassagemDeEtapaController::class)]
#[CoversClass(AprendizadoDasMetasController::class)]
#[Group('tarefa')]
final class AprendizadoDaMetaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFalso();

        $this->escritorio = $this->criarEscritorio();
        $this->criador = $this->criarPessoa($this->escritorio, 'Carla Dias');
        $this->responsavel = $this->criarPessoa($this->escritorio, 'Rui Prado');
        $this->meta = $this->criarMeta();
    }

    #[TestDox('Despachar grava o aprendizado da pessoa: etapa da meta, natureza, texto e dias úteis do prazo; o envio para revisão não')]
    public function testDespacharEnsina(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar('Junte a procuração.', 'providencia');
        $this->despachar('Revise, por favor.', '', 'enviar');

        $registros = $this->despachosAprendidos($this->criador);
        self::assertCount(1, $registros);
        self::assertSame(EtapaDaPasta::Controladoria, $registros[0]->getEtapa());
        self::assertSame(NaturezaDoDespacho::Providencia, $registros[0]->getNatureza());
        self::assertSame('Junte a procuração.', $registros[0]->getTexto());
        self::assertSame(5, $registros[0]->getDiasUteis(), 'o prazo padrão: 5 dias úteis');
        self::assertSame($this->escritorio->getId(), $registros[0]->getTenant()?->getId());
    }

    #[TestDox('Com 2 despachos na etapa, a página sugere as frases embaixo do campo, marca a natureza preferida e mostra a dica do desenho')]
    public function testPaginaSugere(): void
    {
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);
        $this->despachar('Junte a procuração.', 'providencia');
        $this->despachar('Junte a procuração.', 'providencia');

        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());
        $frases = $c->filter('form[data-despacho] > .despacho-campo + .despacho-sugestoes > .despacho-sugestoes-dica + .despacho-sugestoes-lista > button.despacho-sugestao');
        self::assertCount(1, $frases);
        self::assertSame('Junte a procuração.', $frases->filter('.despacho-sugestao > .despacho-sugestao-texto')->text());
        self::assertSame('2x', $frases->filter('.despacho-sugestao > .despacho-sugestao-vezes')->text());
        self::assertSame('Junte a procuração.', $frases->attr('data-texto'));
        self::assertSame('Aprendido com seus despachos nesta etapa · prazo usual: 5 dias úteis', trim($c->filter('.despacho-sugestoes-dica')->text()));
        self::assertSame('providencia', $c->filter('#despachoNatureza')->attr('value'));
        self::assertSame('true', $c->filter('.despacho-chip[data-natureza="providencia"]')->attr('aria-pressed'));
        self::assertSame('false', $c->filter('.despacho-chip[data-natureza="ciencia"]')->attr('aria-pressed'));
        self::assertStringNotContainsString('I.A', $c->filter('.despacho-sugestoes')->text(), 'sem rótulo de I.A. (C2)');
    }

    #[TestDox('Prazo usual diferente do padrão: a data do prazo e o "N dias úteis" já vêm trocados')]
    public function testPrazoUsual(): void
    {
        $this->aprendido($this->criador, $this->escritorio, 'Confira.', 3);
        $this->aprendido($this->criador, $this->escritorio, 'Confira de novo.', 3);
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertSame('3 dias úteis', $c->filter('#despachoUteis')->text());
        self::assertNotSame('', (string) $c->filter('#despachoPrazo')->attr('value'));
        self::assertStringContainsString('prazo usual: 3 dias úteis', $c->filter('.despacho-sugestoes-dica')->text());
    }

    #[TestDox('Isolamento: o que um colega ou outro escritório aprendeu nunca aparece para a pessoa')]
    public function testIsolamento(): void
    {
        $this->aprendido($this->responsavel, $this->escritorio, 'Frase do colega.', 0);
        $this->aprendido($this->responsavel, $this->escritorio, 'Frase do colega.', 0);
        $outro = $this->criarEscritorio();
        $this->aprendido($this->criador, $outro, 'Frase do outro escritório.', 0);
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertCount(0, $c->filter('.despacho-sugestoes'));
        self::assertStringNotContainsString('Frase do colega.', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Frase do outro escritório.', (string) $this->client->getResponse()->getContent());
        self::assertSame('', $c->filter('#despachoNatureza')->attr('value'));
    }

    #[TestDox('Chave desligada no Meu Perfil (D4): despachar não grava e a página não sugere')]
    public function testDesligado(): void
    {
        $this->aprendido($this->criador, $this->escritorio, 'Antiga.', 0);
        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)->gravar($this->escritorio, $this->criador, CatalogoDePreferenciasDoPerfil::SUGESTOES_DESPACHOS, false);
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->despachar('Nova.', '');
        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        self::assertCount(1, $this->despachosAprendidos($this->criador), 'só a antiga');
        self::assertCount(0, $c->filter('.despacho-sugestoes'));
    }

    #[TestDox('Passar a etapa grava cada linha das pendências pela etapa destino; o modal recebe só as da pessoa')]
    public function testPassagemEnsinaOsPedidos(): void
    {
        $outro = new AprendizadoDaPendencia($this->escritorio, $this->criador, EtapaDaPasta::Documentacao, 'Do colega');
        $this->em->persist($outro);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $this->client->request('POST', '/tarefas/' . $this->meta->getId() . '/passar', ['_token' => 'TOKEN_passar_etapa_' . $this->meta->getId(), 'tipo' => 'judicial', 'pendencias' => "Procuração\n\nRG"]);
        self::assertResponseIsSuccessful();

        $linhas = $this->em->getRepository(AprendizadoDaPendencia::class)->findBy(['usuario' => $this->responsavel], ['id' => 'ASC']);
        self::assertSame(['Procuração', 'RG'], array_map(static fn (AprendizadoDaPendencia $p): string => $p->getLinha(), $linhas));
        self::assertSame(EtapaDaPasta::Documentacao, $linhas[0]->getEtapaDestino());

        $nova = $this->em->getRepository(Tarefa::class)->findOneBy(['pasta' => $this->meta->getPasta(), 'status' => Tarefa::STATUS_PENDENTE]);
        self::assertNotNull($nova);
        $nova->setEtapa(EtapaDaPasta::Controladoria);
        $this->em->flush();
        $this->client->request('GET', '/tarefas/' . $nova->getId() . '/passagem');
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['documentacao' => ['RG', 'Procuração']], $dados['costumaPedir'], 'sem as linhas de outra pessoa; no empate, a mais recente primeiro');
    }

    #[TestDox('Página da meta: o modal da passagem tem o "Você costuma pedir:" embaixo do campo das pendências')]
    public function testModalComOVoceCostumaPedir(): void
    {
        $this->logarComTenant($this->client, $this->responsavel, $this->escritorio);

        $c = $this->client->request('GET', '/tarefas/' . $this->meta->getId());

        $caixa = $c->filter('#mpPassagem .mp-com-proxima > label.mp-campo > textarea[data-mp="pend"] + div.mp-costuma[data-mp="costuma"][hidden]');
        self::assertCount(1, $caixa);
        self::assertSame('Você costuma pedir:', trim($caixa->filter('.mp-costuma > .mp-costuma-dica')->text()));
        self::assertCount(1, $caixa->filter('.mp-costuma > .mp-costuma-dica + .mp-costuma-lista[data-mp="costumaLista"]'));
    }

    #[TestDox('Apagar o que foi aprendido: sai só o da pessoa no escritório da sessão; sem o token, 403 e nada sai')]
    public function testApagar(): void
    {
        $this->aprendido($this->criador, $this->escritorio, 'Minha.', 0);
        $this->em->persist(new AprendizadoDaPendencia($this->escritorio, $this->criador, EtapaDaPasta::Juridico, 'RG'));
        $this->aprendido($this->responsavel, $this->escritorio, 'Do colega.', 0);
        $outro = $this->criarEscritorio();
        $this->aprendido($this->criador, $outro, 'De outro escritório.', 0);
        $this->logarComTenant($this->client, $this->criador, $this->escritorio);

        $this->client->request('POST', '/perfil/aprendizado/apagar', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['De outro escritório.', 'Minha.'], $this->textosNoBanco($this->criador));

        $this->client->request('POST', '/perfil/aprendizado/apagar', ['_token' => 'TOKEN_apagar_aprendizado']);
        self::assertResponseRedirects('/perfil');
        self::assertSame(['De outro escritório.'], $this->textosNoBanco($this->criador));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa_aprendizado_pendencia WHERE usuario_id = ?', [$this->criador->getId()]));
        self::assertSame(['Do colega.'], $this->textosNoBanco($this->responsavel));
    }

    private function despachar(string $texto, string $natureza, string $acao = ''): void
    {
        $id = (int) $this->meta->getId();
        $prazo = $acao === '' ? $this->diaUtil(5) : '';
        $this->client->request('POST', '/tarefas/' . $id . '/mensagem', ['_token' => 'TOKEN_mensagem_tarefa_' . $id, 'acao' => $acao, 'mensagem' => $texto, 'natureza' => $natureza, 'prazo' => $prazo]);
        self::assertResponseRedirects();
    }

    /** O prazo padrão da tela: N dias úteis depois de hoje pela regra do escritório. */
    private function diaUtil(int $n): string
    {
        return static::getContainer()->get(\App\Pasta\Service\DiasUteisDoEscritorio::class)
            ->somarUteis(new \DateTimeImmutable('today'), $n, $this->escritorio->getEstado())->format('Y-m-d');
    }

    /**
     * Direto no banco: o filtro de escritório do Doctrine fica ligado depois do login e esconderia o outro escritório.
     *
     * @return list<string>
     */
    private function textosNoBanco(User $pessoa): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT texto FROM tarefa_aprendizado_despacho WHERE usuario_id = ? ORDER BY texto', [$pessoa->getId()]));
    }

    private function aprendido(User $pessoa, Tenant $escritorio, string $texto, int $dias): void
    {
        $this->em->persist(new AprendizadoDoDespacho($escritorio, $pessoa, EtapaDaPasta::Controladoria, null, $texto, $dias));
        $this->em->flush();
    }

    /** @return list<AprendizadoDoDespacho> */
    private function despachosAprendidos(User $pessoa): array
    {
        $this->em->clear();

        return $this->em->getRepository(AprendizadoDoDespacho::class)->findBy(['usuario' => $pessoa->getId()], ['id' => 'ASC']);
    }

    private function criarEscritorio(): Tenant
    {
        $tenant = (new Tenant())->setCidade('Maceió')->setEstado('AL');
        $tenant->setName('Escritório Aprendizado ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarPessoa(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('aprende_' . uniqid() . '@test.com');
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

    private function criarMeta(): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-APR-' . uniqid());
        $pasta->setTenant($this->escritorio);
        $pasta->setCriadoPor($this->criador);
        $this->em->persist($pasta);

        $meta = new Tarefa();
        $meta->setTitulo('Meta do aprendizado');
        $meta->setDescricao('Descrição');
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPasta($pasta);
        $meta->setTenant($this->escritorio);
        $meta->setCriadoPor($this->criador);
        $meta->addResponsavel($this->responsavel);
        $meta->setEtapa(EtapaDaPasta::Controladoria);
        $meta->setTipoDeDemanda(TipoDeDemanda::Judicial);
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
