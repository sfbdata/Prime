<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Pasta\Entity\Pasta;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A passagem do bastão usa DQL em massa (Pasta/Chamado) e SQL nativo (tarefa_responsaveis,
 * evento_participante, kanban_card_responsavel, kanban_board_participante, kanban_board),
 * que ESCAPAM do TenantFilter. Os três testes provam que o escopo EXPLÍCITO por tenant_id (ou
 * p.tenant/c.tenant no DQL) protege TUDO do outro escritório: remover no A não pode tocar nada
 * do B, mesmo com a mesma pessoa responsável/participante/criadora nos dois.
 */
#[CoversClass(RemoverColaboradorDoEscritorioUseCase::class)]
final class RemoverColaboradorIsolamentoTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('remover no escritorio A nao toca no quadro de Kanban do escritorio B')]
    public function testNaoTocaNoOutroEscritorio(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenantA = new Tenant(); $tenantA->setName('A ' . uniqid());
        $tenantB = new Tenant(); $tenantB->setName('B ' . uniqid());
        $em->persist($tenantA); $em->persist($tenantB);

        $pessoa = new User();
        $pessoa->setEmail('multi_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa em dois escritórios');
        $em->persist($pessoa);

        $admin = new User();
        $admin->setEmail('admin_' . uniqid() . '@test.com');
        $admin->setFullName('Admin A');
        $em->persist($admin);

        $em->persist(new UserTenant($pessoa, $tenantA));
        $em->persist(new UserTenant($pessoa, $tenantB));
        $em->persist(new UserTenant($admin, $tenantA));

        // Um quadro em CADA escritório, ambos criados pela mesma pessoa. KanbanBoard exige
        // nome/tenant/criadoPor no construtor — não tem setTenant/setCriadoPor.
        $boardA = new KanbanBoard('Quadro do A', $tenantA, $pessoa);
        $em->persist($boardA);

        $boardB = new KanbanBoard('Quadro do B', $tenantB, $pessoa);
        $boardB->adicionarParticipante($pessoa);
        $em->persist($boardB);
        $em->flush();

        $useCase = $this->montarUseCase($em);
        $useCase->executar(new RemoverColaboradorInput($admin, $pessoa, $tenantA));

        $em->clear();

        // O quadro do A trocou de dono...
        $recarregadoA = $em->find(KanbanBoard::class, $boardA->getId());
        self::assertSame(
            $admin->getId(),
            $recarregadoA->getCriadoPor()?->getId(),
            'o quadro do escritório A devia ter sido herdado pelo executor'
        );

        // ...e o do B não foi tocado.
        $recarregadoB = $em->find(KanbanBoard::class, $boardB->getId());
        self::assertSame(
            $pessoa->getId(),
            $recarregadoB->getCriadoPor()?->getId(),
            'o criador do quadro do escritório B não podia ter mudado'
        );
        self::assertCount(1, $recarregadoB->getParticipantes(), 'o participante do B foi apagado');
    }

    #[TestDox('remover SEM substituto desatribui Pasta/Chamado/tarefa/evento/card do A e nao toca o B')]
    public function testDesatribuicaoNaoCruzaTenant(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $c  = $this->montarCenario($em);

        $useCase = $this->montarUseCase($em);
        $useCase->executar(new RemoverColaboradorInput($c['admin'], $c['pessoa'], $c['tenantA']));

        $em->clear();

        $pessoaId = $c['pessoa']->getId();

        // Tenant A: a afirmação que dói — tudo que era da pessoa ficou sem responsável/participante.
        self::assertNull(
            $em->find(Pasta::class, $c['pastaA'])->getResponsavel(),
            'pasta do escritório A deveria perder o responsável'
        );
        self::assertNull(
            $em->find(Chamado::class, $c['chamadoA'])->getResponsavel(),
            'chamado do escritório A deveria perder o responsável'
        );
        self::assertSame(
            [],
            $this->ids($em->find(Tarefa::class, $c['tarefaA'])->getResponsaveis()),
            'tarefa do escritório A deveria perder o responsável'
        );
        self::assertSame(
            [],
            $this->ids($em->find(Evento::class, $c['eventoA'])->getParticipantes()),
            'evento do escritório A deveria perder o participante'
        );
        self::assertSame(
            [],
            $this->ids($em->find(KanbanCard::class, $c['cardA'])->getResponsaveis()),
            'card de Kanban do escritório A deveria perder o responsável'
        );

        // Tenant B: nada pode ter sido tocado — cada asserção prova isolamento de UMA tabela.
        self::assertSame(
            $pessoaId,
            $em->find(Pasta::class, $c['pastaB'])->getResponsavel()?->getId(),
            'pasta do escritório B NÃO deveria ser tocada'
        );
        self::assertSame(
            $pessoaId,
            $em->find(Chamado::class, $c['chamadoB'])->getResponsavel()?->getId(),
            'chamado do escritório B NÃO deveria ser tocado'
        );
        self::assertSame(
            [$pessoaId],
            $this->ids($em->find(Tarefa::class, $c['tarefaB'])->getResponsaveis()),
            'tarefa do escritório B NÃO deveria ser tocada'
        );
        self::assertSame(
            [$pessoaId],
            $this->ids($em->find(Evento::class, $c['eventoB'])->getParticipantes()),
            'evento do escritório B NÃO deveria ser tocado'
        );
        self::assertSame(
            [$pessoaId],
            $this->ids($em->find(KanbanCard::class, $c['cardB'])->getResponsaveis()),
            'card de Kanban do escritório B NÃO deveria ser tocado'
        );
    }

    #[TestDox('remover COM substituto transfere Pasta/Chamado/tarefa/evento/card do A e nao toca o B')]
    public function testTransferenciaNaoCruzaTenant(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $c  = $this->montarCenario($em);

        $substituto = new User();
        $substituto->setEmail('substituto_' . uniqid() . '@test.com');
        $substituto->setFullName('Substituto');
        $em->persist($substituto);
        $em->persist(new UserTenant($substituto, $c['tenantA']));
        $em->flush();

        $useCase = $this->montarUseCase($em);
        $useCase->executar(new RemoverColaboradorInput($c['admin'], $c['pessoa'], $c['tenantA'], $substituto));

        $em->clear();

        $pessoaId = $c['pessoa']->getId();
        $subId    = $substituto->getId();

        // Tenant A: a afirmação que dói — tudo que era da pessoa foi para o substituto.
        self::assertSame(
            $subId,
            $em->find(Pasta::class, $c['pastaA'])->getResponsavel()?->getId(),
            'pasta do escritório A deveria ir para o substituto'
        );
        self::assertSame(
            $subId,
            $em->find(Chamado::class, $c['chamadoA'])->getResponsavel()?->getId(),
            'chamado do escritório A deveria ir para o substituto'
        );
        self::assertSame(
            [$subId],
            $this->ids($em->find(Tarefa::class, $c['tarefaA'])->getResponsaveis()),
            'tarefa do escritório A deveria ir para o substituto'
        );
        self::assertSame(
            [$subId],
            $this->ids($em->find(Evento::class, $c['eventoA'])->getParticipantes()),
            'evento do escritório A deveria ir para o substituto'
        );
        self::assertSame(
            [$subId],
            $this->ids($em->find(KanbanCard::class, $c['cardA'])->getResponsaveis()),
            'card de Kanban do escritório A deveria ir para o substituto'
        );

        // Tenant B: nada pode ter sido tocado — o substituto não existe lá.
        self::assertSame(
            $pessoaId,
            $em->find(Pasta::class, $c['pastaB'])->getResponsavel()?->getId(),
            'pasta do escritório B NÃO deveria ser tocada'
        );
        self::assertSame(
            $pessoaId,
            $em->find(Chamado::class, $c['chamadoB'])->getResponsavel()?->getId(),
            'chamado do escritório B NÃO deveria ser tocado'
        );
        self::assertSame(
            [$pessoaId],
            $this->ids($em->find(Tarefa::class, $c['tarefaB'])->getResponsaveis()),
            'tarefa do escritório B NÃO deveria ser tocada'
        );
        self::assertSame(
            [$pessoaId],
            $this->ids($em->find(Evento::class, $c['eventoB'])->getParticipantes()),
            'evento do escritório B NÃO deveria ser tocado'
        );
        self::assertSame(
            [$pessoaId],
            $this->ids($em->find(KanbanCard::class, $c['cardB'])->getResponsaveis()),
            'card de Kanban do escritório B NÃO deveria ser tocado'
        );
    }

    #[TestDox('ACHADO 2a: remover COM substituto o quadro de Kanban vai para o SUBSTITUTO, e o do B nao e tocado')]
    public function testHerancaDoQuadroComSubstituto(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenantA = new Tenant(); $tenantA->setName('A ' . uniqid());
        $tenantB = new Tenant(); $tenantB->setName('B ' . uniqid());
        $em->persist($tenantA); $em->persist($tenantB);

        $pessoa = new User();
        $pessoa->setEmail('multi_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa em dois escritórios');
        $em->persist($pessoa);

        $admin = new User();
        $admin->setEmail('admin_' . uniqid() . '@test.com');
        $admin->setFullName('Admin A');
        $em->persist($admin);

        $substituto = new User();
        $substituto->setEmail('substituto_' . uniqid() . '@test.com');
        $substituto->setFullName('Substituto');
        $em->persist($substituto);

        $em->persist(new UserTenant($pessoa, $tenantA));
        $em->persist(new UserTenant($pessoa, $tenantB));
        $em->persist(new UserTenant($admin, $tenantA));
        $em->persist(new UserTenant($substituto, $tenantA));

        // Quadro em CADA escritório, os dois criados pela mesma pessoa.
        $boardA = new KanbanBoard('Quadro do A', $tenantA, $pessoa);
        $em->persist($boardA);
        $boardB = new KanbanBoard('Quadro do B', $tenantB, $pessoa);
        $em->persist($boardB);
        $em->flush();

        $useCase = $this->montarUseCase($em);
        $useCase->executar(new RemoverColaboradorInput($admin, $pessoa, $tenantA, $substituto));

        $em->clear();

        $recarregadoA = $em->find(KanbanBoard::class, $boardA->getId());
        self::assertSame(
            $substituto->getId(),
            $recarregadoA->getCriadoPor()?->getId(),
            'o quadro do escritório A devia ter sido herdado pelo SUBSTITUTO — é a prioridade '
                . 'mais alta de resolverHerdeiroDosQuadros(), e nenhum teste anterior provava isso'
        );

        $recarregadoB = $em->find(KanbanBoard::class, $boardB->getId());
        self::assertSame(
            $pessoa->getId(),
            $recarregadoB->getCriadoPor()?->getId(),
            'o criador do quadro do escritório B não podia ter mudado (o substituto não existe lá)'
        );
    }

    #[TestDox('ACHADO 2b: remover pela porta da SAIDA (sem substituto) o quadro vai para o admin ativo mais antigo, e o do B nao e tocado')]
    public function testHerancaDoQuadroNaSaidaVoluntaria(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenantA = new Tenant(); $tenantA->setName('A ' . uniqid());
        $tenantB = new Tenant(); $tenantB->setName('B ' . uniqid());
        $em->persist($tenantA); $em->persist($tenantB);

        $pessoa = new User();
        $pessoa->setEmail('multi_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa em dois escritórios');
        $em->persist($pessoa);

        $adminAntigo = new User();
        $adminAntigo->setEmail('admin_antigo_' . uniqid() . '@test.com');
        $adminAntigo->setFullName('Admin Antigo');
        $em->persist($adminAntigo);

        $em->persist(new UserTenant($pessoa, $tenantA));
        $em->persist(new UserTenant($pessoa, $tenantB));

        // Admin de vínculo ativo em A, DIFERENTE da pessoa que está saindo — necessário para
        // que resolverHerdeiroDosQuadros() caia no ramo findAdminAtivoMaisAntigo(), que nenhum
        // teste anterior exercitava (a porta Saída nunca tinha um RemoverColaboradorInput seu).
        $role = new TenantRole();
        $role->setTenant($tenantA);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculoAdmin = new UserTenant($adminAntigo, $tenantA);
        $vinculoAdmin->setTenantRole($role);
        $em->persist($vinculoAdmin);

        // Quadro em CADA escritório, os dois criados pela pessoa que está saindo.
        $boardA = new KanbanBoard('Quadro do A', $tenantA, $pessoa);
        $em->persist($boardA);
        $boardB = new KanbanBoard('Quadro do B', $tenantB, $pessoa);
        $em->persist($boardB);
        $em->flush();

        $useCase = $this->montarUseCase($em);
        // origem = Saída: a própria pessoa é executor E colaborador, sem substituto — é o único
        // caminho real que aciona este ramo (Task 5 depende dele, sem teste de isolamento próprio).
        $useCase->executar(new RemoverColaboradorInput($pessoa, $pessoa, $tenantA, null, OrigemRemocao::Saida));

        $em->clear();

        $recarregadoA = $em->find(KanbanBoard::class, $boardA->getId());
        self::assertSame(
            $adminAntigo->getId(),
            $recarregadoA->getCriadoPor()?->getId(),
            'o quadro do escritório A devia ter sido herdado pelo admin ativo mais antigo '
                . '(findAdminAtivoMaisAntigo, ramo exclusivo da porta Saída)'
        );

        $recarregadoB = $em->find(KanbanBoard::class, $boardB->getId());
        self::assertSame(
            $pessoa->getId(),
            $recarregadoB->getCriadoPor()?->getId(),
            'o criador do quadro do escritório B não podia ter mudado (a pessoa continua vinculada lá)'
        );
    }

    #[TestDox('ACHADO 1: uma falha no meio de passarOBastao desfaz TUDO — nada fica pela metade')]
    public function testFalhaNoMeioDaSequenciaDesfazTudo(): void
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $c    = $this->montarCenario($em);

        // Trigger que derruba a DELETE de tarefa_responsaveis SÓ para a tarefa do cenário A —
        // simula uma falha no MEIO da sequência de ~9 statements de passarOBastao(): as UPDATEs
        // de Pasta e Chamado (que rodam ANTES, na ordem do código) já aconteceram quando ela
        // estoura; evento_participante/kanban_card_responsavel/kanban_board_participante/
        // kanban_board/auditoria/remove (que rodam DEPOIS) nunca chegam a executar.
        $conn->executeStatement(
            'CREATE OR REPLACE FUNCTION teste_boom_tarefa_responsaveis() RETURNS trigger AS $$ '
            . 'BEGIN RAISE EXCEPTION \'boom de teste: falha forcada no meio da sequencia\'; END; '
            . '$$ LANGUAGE plpgsql'
        );
        $conn->executeStatement(sprintf(
            'CREATE TRIGGER trg_boom_tarefa_responsaveis BEFORE DELETE ON tarefa_responsaveis '
                . 'FOR EACH ROW WHEN (OLD.tarefa_id = %d) '
                . 'EXECUTE FUNCTION teste_boom_tarefa_responsaveis()',
            $c['tarefaA']
        ));

        try {
            $useCase = $this->montarUseCase($em);

            $capturado = null;
            try {
                $useCase->executar(new RemoverColaboradorInput($c['admin'], $c['pessoa'], $c['tenantA']));
            } catch (\Throwable $e) {
                $capturado = $e;
            }
            self::assertNotNull($capturado, 'o UseCase deveria propagar a falha do banco, não engoli-la');

            $em->clear();

            $pessoaId = $c['pessoa']->getId();

            // Nada pode ter ficado aplicado — nem o que rodou ANTES do statement que estourou...
            self::assertSame(
                $pessoaId,
                $em->find(Pasta::class, $c['pastaA'])->getResponsavel()?->getId(),
                'pasta do A deveria ter voltado ao estado original — a UPDATE dela roda ANTES do '
                    . 'statement que falha, e mesmo assim o rollback tem que desfazê-la'
            );
            self::assertSame(
                $pessoaId,
                $em->find(Chamado::class, $c['chamadoA'])->getResponsavel()?->getId(),
                'chamado do A deveria ter voltado ao estado original — a UPDATE dele roda ANTES '
                    . 'do statement que falha, e mesmo assim o rollback tem que desfazê-la'
            );
            // ...nem o que nunca chegou a rodar (DEPOIS do statement que estourou).
            self::assertSame(
                [$pessoaId],
                $this->ids($em->find(Evento::class, $c['eventoA'])->getParticipantes()),
                'evento do A NÃO deveria ter sido tocado — roda DEPOIS do statement que falha'
            );
            self::assertSame(
                [$pessoaId],
                $this->ids($em->find(KanbanCard::class, $c['cardA'])->getResponsaveis()),
                'card de Kanban do A NÃO deveria ter sido tocado — roda DEPOIS do statement que falha'
            );

            // ...nem o vínculo: a remoção não chegou nem perto do em->remove()+flush().
            $vinculoAindaAtivo = static::getContainer()->get(UserTenantRepository::class)
                ->findAtivoPorUserETenant($c['pessoa'], $c['tenantA']);
            self::assertNotNull(
                $vinculoAindaAtivo,
                'o vínculo NÃO podia ter sido removido — o rollback deveria ter desfeito TUDO, inclusive isso'
            );
        } finally {
            $conn->executeStatement('DROP TRIGGER IF EXISTS trg_boom_tarefa_responsaveis ON tarefa_responsaveis');
            $conn->executeStatement('DROP FUNCTION IF EXISTS teste_boom_tarefa_responsaveis()');
        }
    }

    // ----------------------------------------------------------------- helpers

    private function montarUseCase(EntityManagerInterface $em): RemoverColaboradorDoEscritorioUseCase
    {
        // O UseCase ainda não tem consumidor em produção (controller vem em tarefa posterior)
        // — o compilador do container de teste remove/inlina serviço privado sem consumidor,
        // então buscá-lo direto por `getContainer()->get()` falha com ServiceNotFoundException.
        // Suas duas dependências (EM e o repositório) têm outros consumidores e sobrevivem à
        // compilação; montamos o UseCase à mão com elas, preservando que a query rode contra a
        // conexão/EM REAIS de teste.
        return new RemoverColaboradorDoEscritorioUseCase(
            $em,
            static::getContainer()->get(UserTenantRepository::class),
        );
    }

    /**
     * Monta o cenário-base compartilhado pelos dois testes de passagem do bastão: pessoa com
     * vínculo em A e B, responsável/participante/criadora de uma Pasta, um Chamado, uma Tarefa,
     * um Evento e um KanbanCard EM CADA escritório.
     *
     * @return array{tenantA: Tenant, tenantB: Tenant, pessoa: User, admin: User,
     *     pastaA: int, pastaB: int, chamadoA: int, chamadoB: int,
     *     tarefaA: int, tarefaB: int, eventoA: int, eventoB: int, cardA: int, cardB: int}
     */
    private function montarCenario(EntityManagerInterface $em): array
    {
        $tenantA = new Tenant(); $tenantA->setName('A ' . uniqid());
        $tenantB = new Tenant(); $tenantB->setName('B ' . uniqid());
        $em->persist($tenantA); $em->persist($tenantB);

        $pessoa = new User();
        $pessoa->setEmail('multi_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa em dois escritórios');
        $em->persist($pessoa);

        $admin = new User();
        $admin->setEmail('admin_' . uniqid() . '@test.com');
        $admin->setFullName('Admin A');
        $em->persist($admin);

        $em->persist(new UserTenant($pessoa, $tenantA));
        $em->persist(new UserTenant($pessoa, $tenantB));
        $em->persist(new UserTenant($admin, $tenantA));
        $em->flush();

        $pastaA   = $this->criarPasta($em, $tenantA, $pessoa);
        $pastaB   = $this->criarPasta($em, $tenantB, $pessoa);
        $chamadoA = $this->criarChamado($em, $tenantA, $pessoa);
        $chamadoB = $this->criarChamado($em, $tenantB, $pessoa);
        $tarefaA  = $this->criarTarefa($em, $pastaA, $pessoa);
        $tarefaB  = $this->criarTarefa($em, $pastaB, $pessoa);
        $eventoA  = $this->criarEvento($em, $tenantA, $pessoa);
        $eventoB  = $this->criarEvento($em, $tenantB, $pessoa);
        $cardA    = $this->criarKanbanCard($em, $tenantA, $pessoa);
        $cardB    = $this->criarKanbanCard($em, $tenantB, $pessoa);

        return [
            'tenantA' => $tenantA, 'tenantB' => $tenantB, 'pessoa' => $pessoa, 'admin' => $admin,
            'pastaA'   => $pastaA->getId(),   'pastaB'   => $pastaB->getId(),
            'chamadoA' => $chamadoA->getId(), 'chamadoB' => $chamadoB->getId(),
            'tarefaA'  => $tarefaA->getId(),  'tarefaB'  => $tarefaB->getId(),
            'eventoA'  => $eventoA->getId(),  'eventoB'  => $eventoB->getId(),
            'cardA'    => $cardA->getId(),    'cardB'    => $cardB->getId(),
        ];
    }

    /**
     * @param iterable<User> $users
     * @return list<int>
     */
    private function ids(iterable $users): array
    {
        $out = [];
        foreach ($users as $u) {
            $out[] = (int) $u->getId();
        }
        sort($out);

        return $out;
    }

    private function criarPasta(EntityManagerInterface $em, Tenant $tenant, User $responsavel): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('REM-' . (++$this->seq) . '-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setResponsavel($responsavel);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarChamado(EntityManagerInterface $em, Tenant $tenant, User $responsavel): Chamado
    {
        $chamado = new Chamado();
        $chamado->setTitulo('Chamado ' . uniqid());
        $chamado->setDescricao('Descrição');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($responsavel);
        $chamado->setResponsavel($responsavel);
        $em->persist($chamado);
        $em->flush();

        return $chamado;
    }

    private function criarTarefa(EntityManagerInterface $em, Pasta $pasta, User $responsavel): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo('Tarefa ' . uniqid());
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($pasta->getTenant());
        $tarefa->addResponsavel($responsavel);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    private function criarEvento(EntityManagerInterface $em, Tenant $tenant, User $participante): Evento
    {
        $evento = new Evento();
        $evento->setTitulo('Evento ' . uniqid());
        $evento->setDataInicio(new \DateTimeImmutable('2026-03-10 08:00:00'));
        $evento->setDataFim(new \DateTimeImmutable('2026-03-10 09:00:00'));
        $evento->setCriador($participante);
        $evento->setTenant($tenant);
        $evento->addParticipante($participante);
        $em->persist($evento);
        $em->flush();

        return $evento;
    }

    private function criarKanbanCard(EntityManagerInterface $em, Tenant $tenant, User $responsavel): KanbanCard
    {
        $board  = new KanbanBoard('Quadro ' . uniqid(), $tenant, $responsavel);
        $em->persist($board);

        $coluna = new KanbanColuna('Coluna ' . uniqid(), KanbanColuna::TIPO_A_FAZER, 0, $board);
        $em->persist($coluna);

        $card = new KanbanCard('Card ' . uniqid(), $coluna, $board, $responsavel);
        $card->adicionarResponsavel($responsavel);
        $em->persist($card);
        $em->flush();

        return $card;
    }
}
