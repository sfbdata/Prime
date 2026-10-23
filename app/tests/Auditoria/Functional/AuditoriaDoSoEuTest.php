<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Controller\AuditLogController;
use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Service\Audit\AuditLogSubscriber;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Só eu" promete que só a pessoa vê o compromisso. A auditoria, que quem administra lê, grava a ação,
 * quem e quando, mas não o título, a descrição nem o local (antes e depois), inclusive na troca de
 * visibilidade nos dois sentidos. O resto da auditoria não muda.
 */
#[CoversClass(AuditLogSubscriber::class)]
#[CoversClass(AuditLogController::class)]
final class AuditoriaDoSoEuTest extends JusPrimeWebTestCase
{
    private const SEGREDO = 'Consulta no oncologista';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $admin;
    private User $pessoa;
    private int $marco = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenant = (new Tenant())->setName('Auditoria Só eu ' . uniqid());
        $this->em->persist($this->tenant);
        $this->admin = $this->usuario('admin_soeu', ['ROLE_SUPER_ADMIN']);
        $this->pessoa = $this->usuario('pessoa_soeu', ['ROLE_USER']);
        $this->marco = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_log');
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

    #[TestDox('Criar, editar e excluir um "Só eu": a auditoria registra as três ações sem o título, a descrição nem o local')]
    public function testCicloSemConteudo(): void
    {
        $evento = $this->evento(Evento::VISIBILIDADE_SOMENTE_EU);
        $id = (int) $evento->getId();
        $evento->setTitulo('Consulta remarcada')->setLocal('Hospital X')->setStatus(Evento::STATUS_CONCLUIDO);
        $this->em->flush();
        $this->em->remove($evento);
        $this->em->flush();

        $logs = $this->logsDo($id);

        self::assertSame(['create', 'update', 'delete'], array_column($logs, 'action'));
        foreach ($logs as $log) {
            self::assertStringNotContainsString('oncologista', $this->texto($log['changes']));
            self::assertStringNotContainsString('remarcada', $this->texto($log['changes']));
            self::assertStringNotContainsString('Hospital', $this->texto($log['changes']));
            self::assertStringNotContainsString('Rua do segredo', $this->texto($log['changes']));
            self::assertTrue(json_decode($log['changes'], true)['diff']['conteudo_particular']);
        }
        self::assertArrayHasKey('status', json_decode($logs[1]['changes'], true)['diff']['changes'], 'os outros campos continuam');
    }

    #[TestDox('Trocar a visibilidade nos dois sentidos não expõe o conteúdo, nem o antes nem o depois')]
    public function testTrocaDeVisibilidade(): void
    {
        $publico = $this->evento(Evento::VISIBILIDADE_TODOS);
        $publico->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU)->setTitulo(self::SEGREDO);
        $this->em->flush();
        $particular = $this->evento(Evento::VISIBILIDADE_SOMENTE_EU);
        $particular->setVisibilidade(Evento::VISIBILIDADE_TODOS)->setTitulo('Reunião de equipe');
        $this->em->flush();

        $paraSoEu = $this->logsDo((int) $publico->getId());
        $paraTodos = $this->logsDo((int) $particular->getId());

        self::assertStringNotContainsString('oncologista', $this->texto(end($paraSoEu)['changes']));
        self::assertStringNotContainsString('oncologista', $this->texto(end($paraTodos)['changes']), 'o título antigo, particular, não aparece');
        self::assertStringNotContainsString('Reunião de equipe', $this->texto(end($paraTodos)['changes']), 'nem o novo, da mesma mudança');
        self::assertArrayHasKey('visibilidade', json_decode(end($paraTodos)['changes'], true)['diff']['changes']);
    }

    #[TestDox('Excluir no mesmo flush em que deixa de ser "Só eu": o estado do banco, que era particular, não vai para a auditoria')]
    public function testExcluirDepoisDeTornarPublico(): void
    {
        $evento = $this->evento(Evento::VISIBILIDADE_SOMENTE_EU);
        $id = (int) $evento->getId();
        $evento->setVisibilidade(Evento::VISIBILIDADE_TODOS);
        $this->em->remove($evento);
        $this->em->flush();

        $exclusao = array_values(array_filter($this->logsDo($id), static fn (array $l): bool => 'delete' === $l['action']))[0];

        self::assertStringNotContainsString('oncologista', $this->texto($exclusao['changes']));
    }

    #[TestDox('A tela antiga da Agenda não avisa participantes de um "Só eu" (eles não veem o compromisso, e o aviso levaria o título à auditoria)')]
    public function testSoEuNaoAvisaParticipantes(): void
    {
        $this->logarComTenant($this->client, $this->admin, $this->tenant);
        $this->criarPelaTelaAntiga(self::SEGREDO, Evento::VISIBILIDADE_SOMENTE_EU);

        self::assertSame(0, $this->avisosDaPessoa());

        $this->criarPelaTelaAntiga('Reunião de equipe', Evento::VISIBILIDADE_TODOS);
        self::assertSame(1, $this->avisosDaPessoa(), 'visível à equipe continua avisando');
    }

    /** O formulário "Novo evento" da tela antiga (`/agenda/novo`), com a pessoa como participante. */
    private function criarPelaTelaAntiga(string $titulo, string $visibilidade): void
    {
        $form = $this->client->request('GET', '/agenda/novo')->filter('form[name="evento"]')->form();
        $form['evento[titulo]'] = $titulo;
        $form['evento[visibilidade]'] = $visibilidade;
        $form['evento[participantes]'] = [(string) $this->pessoa->getId()];
        $this->client->submit($form);

        self::assertResponseRedirects();
    }

    private function avisosDaPessoa(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE usuario_id = :u', ['u' => $this->pessoa->getId()]);
    }

    #[TestDox('Na tela /auditoria o admin vê a ação e o autor, mas não o conteúdo do "Só eu"; a mudança de participantes leva o rótulo "Compromisso particular"')]
    public function testAdminNaoVeNaTela(): void
    {
        $evento = $this->evento(Evento::VISIBILIDADE_SOMENTE_EU);
        $evento->addParticipante($this->admin);
        $this->em->flush();
        self::assertNotSame([], $this->logsDo((int) $evento->getId()), 'há registros do compromisso para a tela mostrar');
        $this->logarComTenant($this->client, $this->admin, $this->tenant);

        $this->client->request('GET', '/auditoria');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('oncologista', $html);
        self::assertStringNotContainsString('Rua do segredo', $html);
        self::assertStringContainsString('Compromisso particular', $html);
    }

    #[TestDox('O desfazer continua funcionando nos campos que ficaram (a situação de um "Só eu")')]
    public function testDesfazerNosOutrosCampos(): void
    {
        $evento = $this->evento(Evento::VISIBILIDADE_SOMENTE_EU);
        $evento->setStatus(Evento::STATUS_CANCELADO)->setTitulo('Outro título');
        $this->em->flush();
        $log = array_values(array_filter($this->logsDo((int) $evento->getId()), static fn (array $l): bool => 'update' === $l['action']))[0];
        $this->logarComTenant($this->client, $this->admin, $this->tenant);
        $this->em->clear();

        $this->client->request('POST', '/auditoria/' . $log['id'] . '/desfazer', ['_token' => 'TOKEN_audit_desfazer_' . $log['id']]);

        self::assertResponseRedirects();
        $voltou = $this->em->find(Evento::class, $evento->getId());
        self::assertSame(Evento::STATUS_AGENDADO, $voltou?->getStatus());
        self::assertSame('Outro título', $voltou?->getTitulo(), 'o título não estava no registro e fica como está');
    }

    #[TestDox('Nada muda para o resto: compromisso visível à equipe e pasta continuam com o conteúdo na auditoria')]
    public function testOutrasEntidadesIntactas(): void
    {
        $evento = $this->evento(Evento::VISIBILIDADE_TODOS);
        $evento->setTitulo('Reunião aberta');
        $this->em->flush();
        $pasta = (new Pasta())->setNup('SOEU-' . uniqid())->setTenant($this->tenant)->setPrioridade(PrioridadePasta::Normal)->setNomeAcao('Ação de cobrança');
        $this->em->persist($pasta);
        $this->em->flush();

        $doEvento = $this->logsDo((int) $evento->getId());
        $daPasta = $this->em->getConnection()->fetchOne("SELECT changes FROM audit_log WHERE entity_class = :c AND action = 'create' AND id > :marco", ['c' => Pasta::class, 'marco' => $this->marco]);

        self::assertStringContainsString('Reunião aberta', $this->texto(end($doEvento)['changes']));
        self::assertStringContainsString('oncologista', $this->texto($doEvento[0]['changes']));
        self::assertArrayNotHasKey('conteudo_particular', json_decode($doEvento[0]['changes'], true)['diff']);
        self::assertStringContainsString('AÇÃO DE COBRANÇA', $this->texto((string) $daPasta));
    }

    private function evento(string $visibilidade): Evento
    {
        $inicio = new \DateTimeImmutable('+3 days 09:00');
        $evento = (new Evento())->setTitulo(self::SEGREDO)->setDescricao('Levar exames')->setLocal('Rua do segredo, 10')
            ->setDataInicio($inicio)->setDataFim($inicio->modify('+1 hour'))->setVisibilidade($visibilidade)
            ->setCriador($this->pessoa)->setTenant($this->tenant);
        $this->em->persist($evento);
        $this->em->flush();

        return $evento;
    }

    /**
     * Registros do compromisso (o de criação nasce sem id da entidade, então vale "depois do marco"). Fora
     * de uma requisição o registro não leva o escritório: aqui ele recebe o do teste, como na tela real.
     *
     * @return list<array{id: int, action: string, changes: string}>
     */
    private function logsDo(int $id): array
    {
        $banco = $this->em->getConnection();
        $banco->executeStatement('UPDATE audit_log SET tenant_id = :t WHERE id > :marco AND tenant_id IS NULL', ['t' => $this->tenant->getId(), 'marco' => $this->marco]);

        return $banco->fetchAllAssociative(
            "SELECT id, action, changes FROM audit_log WHERE entity_class = :c AND id > :marco AND (entity_id = :id OR (action = 'create' AND entity_id IS NULL)) ORDER BY id",
            ['c' => Evento::class, 'id' => (string) $id, 'marco' => $this->marco],
        );
    }

    /** Texto do registro como a tela o mostra (o JSON gravado escapa os acentos). */
    private function texto(string $changes): string
    {
        return (string) json_encode(json_decode($changes, true), \JSON_UNESCAPED_UNICODE);
    }

    /** @param list<string> $roles */
    private function usuario(string $prefixo, array $roles): User
    {
        $u = new User();
        $u->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $u->setFullName('Usuário ' . $prefixo);
        $u->setRoles($roles);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->persist(new UserTenant($u, $this->tenant));
        $this->em->flush();

        return $u;
    }
}
