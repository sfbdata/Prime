<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261019235500;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A limpeza dos registros ANTIGOS (anteriores ao #81) da auditoria de compromissos "Só eu": roda o SQL da
 * migration sobre registros gravados no formato antigo, com o título, a descrição e o local à vista.
 *
 * As migrations não estão no autoload do composer (o Doctrine as carrega por caminho), daí o require_once.
 */
#[CoversNothing]
final class LimpezaDoSoEuAntigoTest extends KernelTestCase
{
    private const LEGADA = 'App\Entity\Agenda\Evento';
    private const NOVA = 'App\Agenda\Entity\Evento';
    private const SEGREDO = ['titulo' => 'Consulta no oncologista', 'descricao' => 'Levar exames', 'local' => 'Rua do segredo, 10'];

    private Connection $banco;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../migrations/Version20261019235500.php';
    }

    protected function setUp(): void
    {
        $this->banco = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    #[TestDox('Criação e exclusão que mostram o "Só eu" perdem o conteúdo e ficam com a marca; os demais campos ficam')]
    public function testRegistroQueMostraOSoEu(): void
    {
        $criacao = $this->registro(self::LEGADA, 'create', null, ['after' => self::SEGREDO + ['visibilidade' => 'somente_eu', 'status' => 'agendado']]);
        $exclusao = $this->registro(self::LEGADA, 'delete', '999000010', ['before' => self::SEGREDO + ['visibilidade' => 'somente_eu']]);
        $naClasseNova = $this->registro(self::NOVA, 'create', null, ['after' => self::SEGREDO + ['visibilidade' => 'somente_eu']]);

        $this->limpar();

        self::assertSame(['status' => 'agendado', 'visibilidade' => 'somente_eu'], $this->diff($criacao)['after']);
        self::assertTrue($this->diff($criacao)['conteudo_particular']);
        self::assertSame(['visibilidade' => 'somente_eu'], $this->diff($exclusao)['before']);
        self::assertSame(['visibilidade' => 'somente_eu'], $this->diff($naClasseNova)['after']);
    }

    #[TestDox('Um compromisso que foi "Só eu" em algum registro tem todos os registros dele limpos')]
    public function testCompromissoQueFoiSoEu(): void
    {
        $antes = $this->registro(self::LEGADA, 'update', '999000020', ['changes' => ['titulo' => ['Rascunho', 'Consulta no oncologista']]]);
        $troca = $this->registro(self::LEGADA, 'update', '999000020', ['changes' => ['visibilidade' => ['todos', 'somente_eu'], 'status' => ['agendado', 'concluido']]]);

        $this->limpar();

        self::assertSame([], $this->diff($antes)['changes']);
        self::assertSame(['status', 'visibilidade'], array_keys($this->diff($troca)['changes']));
        self::assertTrue($this->diff($troca)['conteudo_particular']);
    }

    #[TestDox('Um compromisso "Só eu" hoje tem os registros antigos limpos, mesmo os que não falam de visibilidade')]
    public function testCompromissoSoEuHoje(): void
    {
        $id = $this->eventoSoEu();
        $antigo = $this->registro(self::LEGADA, 'update', (string) $id, ['changes' => ['local' => ['Sala 1', 'Rua do segredo, 10']]]);

        $this->limpar();

        self::assertSame([], $this->diff($antigo)['changes']);
        self::assertTrue($this->diff($antigo)['conteudo_particular']);
    }

    #[TestDox('Compromisso visível à equipe e outras entidades ficam como estavam')]
    public function testResto(): void
    {
        $publico = $this->registro(self::LEGADA, 'create', null, ['after' => self::SEGREDO + ['visibilidade' => 'todos']]);
        $publicoComId = $this->registro(self::LEGADA, 'update', '999000030', ['changes' => ['titulo' => ['A', 'B']]]);
        $outra = $this->registro('App\Pasta\Entity\Pasta', 'update', '999000020', ['changes' => ['titulo' => ['A', 'B'], 'visibilidade' => ['todos', 'somente_eu']]]);
        $antes = [$publico => $this->diff($publico), $publicoComId => $this->diff($publicoComId), $outra => $this->diff($outra)];

        $this->limpar();

        foreach ($antes as $id => $diff) {
            self::assertSame($diff, $this->diff($id));
        }
    }

    #[TestDox('Mudança de participantes: o título no rótulo do dono vira "Compromisso particular" quando o dono é "Só eu"')]
    public function testRotuloDoDono(): void
    {
        $quando = '2026-05-04 10:00:00';
        $this->registro(self::LEGADA, 'create', null, ['after' => self::SEGREDO + ['visibilidade' => 'somente_eu']], $quando, 1);
        $daCriacaoSoEu = $this->registro(self::LEGADA, 'update', null, self::participantes(null), $quando, 1);
        $this->registro(self::LEGADA, 'create', null, ['after' => self::SEGREDO + ['visibilidade' => 'todos']], '2026-05-04 11:00:00', 1);
        $daCriacaoPublica = $this->registro(self::LEGADA, 'update', null, self::participantes(null), '2026-05-04 11:00:00', 1);
        $outraPessoa = $this->registro(self::LEGADA, 'update', null, self::participantes(null), $quando, 2);
        $this->registro(self::LEGADA, 'update', '999000040', ['changes' => ['visibilidade' => ['todos', 'somente_eu']]]);
        $porId = $this->registro(self::LEGADA, 'update', '999000040', self::participantes('999000040'));
        $publicoAntes = $this->diff($daCriacaoPublica);
        $outraAntes = $this->diff($outraPessoa);

        $this->limpar();

        self::assertSame('Compromisso particular', $this->diff($daCriacaoSoEu)['association_events'][0]['owner']['label']);
        self::assertSame('Ana', $this->diff($daCriacaoSoEu)['association_events'][0]['related'][0]['label'], 'quem participa continua');
        self::assertTrue($this->diff($daCriacaoSoEu)['conteudo_particular']);
        self::assertSame('Compromisso particular', $this->diff($porId)['association_events'][0]['owner']['label']);
        self::assertSame($publicoAntes, $this->diff($daCriacaoPublica));
        self::assertSame($outraAntes, $this->diff($outraPessoa));
    }

    private function limpar(): void
    {
        $this->banco->executeStatement(Version20261019235500::SQL_LIMPEZA);
        $this->banco->executeStatement(Version20261019235500::SQL_DONO_DA_COLECAO);
    }

    /** O formato que o subscriber gravava antes do #81 numa mudança de participantes. @return array<string, mixed> */
    private static function participantes(?string $idDoDono): array
    {
        return [
            'changes' => ['participantes[+:1]' => ['Ana']],
            'association_events' => [[
                'field' => 'participantes',
                'operation' => 'add',
                'owner' => ['class' => self::LEGADA, 'id' => $idDoDono, 'label' => 'Consulta no oncologista'],
                'related' => [['class' => User::class, 'id' => 5, 'label' => 'Ana']],
            ]],
        ];
    }

    /** @param array<string, mixed> $diff */
    private function registro(string $classe, string $acao, ?string $idDaEntidade, array $diff, ?string $quando = null, ?int $quem = null): int
    {
        return (int) $this->banco->fetchOne(
            'INSERT INTO audit_log (action, entity_class, entity_id, changes, created_at, actor_user_id) VALUES (:a, :c, :e, :j, COALESCE(CAST(:q AS timestamp), NOW()), :u) RETURNING id',
            ['a' => $acao, 'c' => $classe, 'e' => $idDaEntidade, 'j' => json_encode(['diff' => $diff, 'context' => ['route' => 'agenda']]), 'q' => $quando, 'u' => $quem],
        );
    }

    /** @return array<string, mixed> */
    private function diff(int $id): array
    {
        return json_decode((string) $this->banco->fetchOne('SELECT changes FROM audit_log WHERE id = :id', ['id' => $id]), true)['diff'];
    }

    private function eventoSoEu(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Limpeza Só eu ' . uniqid());
        $pessoa = (new User())->setEmail('limpeza_' . uniqid() . '@test.com')->setFullName('Pessoa')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $inicio = new \DateTimeImmutable('+3 days 09:00');
        $evento = (new Evento())->setTitulo('Particular')->setDataInicio($inicio)->setDataFim($inicio->modify('+1 hour'))
            ->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU)->setCriador($pessoa)->setTenant($tenant);
        $em->persist($tenant);
        $em->persist($pessoa);
        $em->persist($evento);
        $em->flush();

        return (int) $evento->getId();
    }
}
