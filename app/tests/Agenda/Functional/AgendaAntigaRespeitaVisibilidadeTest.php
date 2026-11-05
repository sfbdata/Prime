<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Entity\Evento;
use App\Controller\AgendaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria de segurança 3.02/3.03: as rotas antigas da Agenda (GET /agenda/{id}, editar, excluir e cancelar)
 * aplicam a mesma visibilidade da Agenda nova (`EventoDaAgendaRepository::visivelPara`): "Só eu" só para o
 * criador; "Para Todos" com participantes só para criador e participantes. Quem não vê recebe 404, até o
 * administrador. O criador e o administrador continuam agindo no que veem.
 */
#[CoversClass(AgendaController::class)]
final class AgendaAntigaRespeitaVisibilidadeTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const AGENDA = ['modules.agenda.view'];
    private const ADMIN = ['modules.agenda.view', 'admin.users.manage'];

    #[TestDox('"Só eu" de outra pessoa: 404 no detalhe e o título não aparece; o criador abre')]
    public function testDetalheDoSoEu(): void
    {
        $this->prepararCliente();
        [, $tenant] = $this->criarEscritorio();
        $dona = $this->criarColaborador($tenant, 'Dora Dona', self::AGENDA);
        $colega = $this->criarColaborador($tenant, 'Caio Colega', self::AGENDA);
        $evento = $this->evento($tenant, $dona, Evento::VISIBILIDADE_SOMENTE_EU, 'Consulta medica particular');

        $this->entrar($colega, $tenant);
        $this->client->request('GET', '/agenda/' . $evento->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Consulta medica particular', (string) $this->client->getResponse()->getContent());

        $this->entrar($dona, $tenant);
        $this->client->request('GET', '/agenda/' . $evento->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Consulta medica particular', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('"Para Todos" com participantes: 404 para quem não participa, 200 para o participante')]
    public function testDetalheComParticipantes(): void
    {
        $this->prepararCliente();
        [, $tenant] = $this->criarEscritorio();
        $dona = $this->criarColaborador($tenant, 'Dora Dona', self::AGENDA);
        $convidado = $this->criarColaborador($tenant, 'Gil Convidado', self::AGENDA);
        $fora = $this->criarColaborador($tenant, 'Rui de Fora', self::AGENDA);
        $evento = $this->evento($tenant, $dona, Evento::VISIBILIDADE_TODOS, 'Reuniao restrita', [$convidado]);

        $this->entrar($fora, $tenant);
        $this->client->request('GET', '/agenda/' . $evento->getId());
        self::assertResponseStatusCodeSame(404);

        $this->entrar($convidado, $tenant);
        $crawler = $this->client->request('GET', '/agenda/' . $evento->getId());
        self::assertResponseIsSuccessful();
        // O participante vê, mas não cancela nem exclui: sem os formulários e os tokens.
        self::assertCount(0, $crawler->filter('form[action$="/cancelar"], form[action$="/excluir"]'));

        $this->entrar($dona, $tenant);
        $crawler = $this->client->request('GET', '/agenda/' . $evento->getId());
        self::assertCount(2, $crawler->filter('form[action$="/cancelar"], form[action$="/excluir"]'));
    }

    #[TestDox('administrador recebe 404 ao editar, excluir e cancelar o "Só eu" de outro colega, que fica intacto')]
    public function testAdministradorNaoMexeNoSoEuAlheio(): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $dona = $this->criarColaborador($tenant, 'Dora Dona', self::AGENDA);
        $admin = $this->criarColaborador($tenant, 'Ada Admin', self::ADMIN);
        $evento = $this->evento($tenant, $dona, Evento::VISIBILIDADE_SOMENTE_EU, 'Particular da Dora');
        $id = (int) $evento->getId();

        foreach ([$admin, $primario] as $quem) {
            $this->entrar($quem, $tenant);
            $this->client->request('GET', "/agenda/{$id}/editar");
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Particular da Dora', (string) $this->client->getResponse()->getContent());

            $this->client->request('POST', "/agenda/{$id}/excluir", ['_token' => 'TOKEN_delete' . $id]);
            self::assertResponseStatusCodeSame(404);

            $this->client->request('POST', "/agenda/{$id}/cancelar", ['_token' => 'TOKEN_cancelar' . $id]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->em()->clear();
        $intacto = $this->em()->find(Evento::class, $id);
        self::assertNotNull($intacto);
        self::assertSame(Evento::STATUS_AGENDADO, $intacto->getStatus());
    }

    #[TestDox('o criador continua editando, cancelando e excluindo o próprio "Só eu"')]
    public function testCriadorAgeNoProprioSoEu(): void
    {
        $this->prepararCliente();
        [, $tenant] = $this->criarEscritorio();
        $dona = $this->criarColaborador($tenant, 'Dora Dona', self::AGENDA);
        $evento = $this->evento($tenant, $dona, Evento::VISIBILIDADE_SOMENTE_EU, 'Meu particular');
        $id = (int) $evento->getId();

        $this->entrar($dona, $tenant);
        $this->client->request('GET', "/agenda/{$id}/editar");
        self::assertResponseIsSuccessful();

        $this->client->request('POST', "/agenda/{$id}/cancelar", ['_token' => 'TOKEN_cancelar' . $id]);
        self::assertResponseRedirects('/agenda');
        $this->em()->clear();
        self::assertSame(Evento::STATUS_CANCELADO, $this->em()->find(Evento::class, $id)?->getStatus());

        $this->client->request('POST', "/agenda/{$id}/excluir", ['_token' => 'TOKEN_delete' . $id]);
        self::assertResponseRedirects('/agenda');
        $this->em()->clear();
        self::assertNull($this->em()->find(Evento::class, $id));
    }

    #[TestDox('administrador continua editando "Para Todos" sem participantes de outro colega')]
    public function testAdministradorEditaEventoAberto(): void
    {
        $this->prepararCliente();
        [, $tenant] = $this->criarEscritorio();
        $dona = $this->criarColaborador($tenant, 'Dora Dona', self::AGENDA);
        $admin = $this->criarColaborador($tenant, 'Ada Admin', self::ADMIN);
        $evento = $this->evento($tenant, $dona, Evento::VISIBILIDADE_TODOS, 'Aberto a todos');

        $this->entrar($admin, $tenant);
        $this->client->request('GET', '/agenda/' . $evento->getId() . '/editar');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('sem o módulo agenda, editar não abre nem o próprio evento')]
    public function testEditarExigeOModulo(): void
    {
        $this->prepararCliente();
        [, $tenant] = $this->criarEscritorio();
        $semModulo = $this->criarColaborador($tenant, 'Sem Modulo', ['modules.expediente.view']);
        $evento = $this->evento($tenant, $semModulo, Evento::VISIBILIDADE_TODOS, 'Sem modulo');

        $this->entrar($semModulo, $tenant);
        $this->client->request('GET', '/agenda/' . $evento->getId() . '/editar');
        self::assertResponseRedirects('/inicio');
    }

    /** @param list<User> $participantes */
    private function evento(Tenant $tenant, User $criador, string $visibilidade, string $titulo, array $participantes = []): Evento
    {
        $evento = new Evento();
        $evento->setTitulo($titulo);
        $evento->setDataInicio((new \DateTimeImmutable('+2 days'))->setTime(9, 0));
        $evento->setDataFim((new \DateTimeImmutable('+2 days'))->setTime(10, 0));
        $evento->setCriador($criador);
        $evento->setTenant($tenant);
        $evento->setVisibilidade($visibilidade);
        foreach ($participantes as $p) {
            $evento->addParticipante($p);
        }
        $this->em()->persist($evento);
        $this->em()->flush();

        return $evento;
    }
}
