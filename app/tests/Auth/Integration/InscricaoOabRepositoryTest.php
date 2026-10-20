<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** As consultas do leitor público das inscrições e as travas do banco (spec meu-perfil-oab-inscricoes §2 e §3). */
#[CoversClass(InscricaoOabRepository::class)]
final class InscricaoOabRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InscricaoOabRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(InscricaoOabRepository::class);
    }

    #[TestDox('doUsuario: principal primeiro, depois por UF; paraRevisao filtra por situação e busca por número')]
    public function testConsultas(): void
    {
        $user = $this->usuario();
        $this->inscrever($user, '700', 'SP', TipoInscricaoOab::Suplementar);
        $this->inscrever($user, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        $this->inscrever($user, '800', 'GO', TipoInscricaoOab::Suplementar);
        $this->em->flush();

        self::assertSame(['DF', 'GO', 'SP'], array_map(static fn (InscricaoOab $i): string => $i->getUf(), $this->repo->doUsuario($user)));
        $pendentes = $this->repo->paraRevisao([StatusOab::NaoVerificada], (string) $user->getEmail());
        $ufs = array_map(static fn (InscricaoOab $i): string => $i->getUf(), $pendentes);
        sort($ufs);
        self::assertSame(['GO', 'SP'], $ufs, 'só as pendentes da pessoa');
        self::assertSame(1, $this->repo->contarParaRevisao([], '49613'));
    }

    #[TestDox('paraRevisao: com o mesmo criado_em (backfill), as páginas não repetem nem pulam inscrição')]
    public function testPaginacaoEstavelComMesmoCriadoEm(): void
    {
        $user = $this->usuario();
        $ufs  = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE'];
        foreach ($ufs as $n => $uf) {
            $this->inscrever($user, (string) (1000 + $n), $uf, $n === 0 ? TipoInscricaoOab::Principal : TipoInscricaoOab::Suplementar);
        }
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            "UPDATE inscricao_oab SET criado_em = '2026-10-19 12:00:00' WHERE user_id = ?",
            [$user->getId()],
        );
        $this->em->clear();

        $ids = static fn (array $lista): array => array_map(static fn (InscricaoOab $i): int => (int) $i->getId(), $lista);
        $pagina1 = $ids($this->repo->paraRevisao([], (string) $user->getEmail(), 1, 20));
        $pagina2 = $ids($this->repo->paraRevisao([], (string) $user->getEmail(), 2, 20));

        self::assertCount(20, $pagina1);
        self::assertCount(5, $pagina2);
        self::assertSame([], array_intersect($pagina1, $pagina2), 'nenhuma inscrição em duas páginas');
        $todas = array_merge($pagina1, $pagina2);
        sort($todas);
        $esperadas = array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM inscricao_oab WHERE user_id = ? ORDER BY id',
            [$user->getId()],
        ));
        self::assertSame($esperadas, $todas, 'nenhuma inscrição pulada');
    }

    #[TestDox('criar a principal do espelho duas vezes (duas abas) deixa uma só, sem erro')]
    public function testEspelhoIdempotente(): void
    {
        $user = $this->usuario();
        $user->setOabNumero('49613');
        $user->setOabUf('DF');
        $user->setOabStatus(StatusOab::Confirmada);
        $this->em->flush();

        $this->repo->criarPrincipalDoEspelhoSeFaltar($user);
        $this->repo->criarPrincipalDoEspelhoSeFaltar($user);

        $lista = $this->repo->doUsuario($user);
        self::assertCount(1, $lista);
        self::assertSame(['49613', 'DF', StatusOab::Confirmada, true], [$lista[0]->getNumero(), $lista[0]->getUf(), $lista[0]->getStatus(), $lista[0]->ehPrincipal()]);
    }

    #[TestDox('criarPrincipalSeFaltar duas vezes (duas abas no criar escritório) deixa uma principal só, sem erro')]
    public function testCriarPrincipalSeFaltarIdempotente(): void
    {
        $user = $this->usuario();
        $this->em->flush();
        $quando = new \DateTimeImmutable('2026-10-01 10:00:00');

        $this->repo->criarPrincipalSeFaltar($user, '49613', 'DF', StatusOab::Confirmada, 'PESSOA OAB', $quando);
        $this->repo->criarPrincipalSeFaltar($user, '11111', 'SP', StatusOab::NaoVerificada, null, null);

        $lista = $this->repo->doUsuario($user);
        self::assertCount(1, $lista);
        self::assertSame(
            ['49613', 'DF', StatusOab::Confirmada, 'PESSOA OAB', '2026-10-01 10:00:00', true],
            [$lista[0]->getNumero(), $lista[0]->getUf(), $lista[0]->getStatus(), $lista[0]->getNomeOficial(), $lista[0]->getVerificadaEm()?->format('Y-m-d H:i:s'), $lista[0]->ehPrincipal()],
        );
    }

    /**
     * Prova a principal gravada (com COMMIT) por outra requisição entre o `doUsuario` e o INSERT: o NOT EXISTS a vê.
     * A corrida com a outra linha ainda sem COMMIT não se testa numa conexão só (DAMA); ali quem garante é o
     * `ON CONFLICT DO NOTHING` do Postgres, que usa todos os índices únicos como árbitros.
     */
    #[TestDox('criarPrincipalSeFaltar não duplica se outra requisição já gravou a principal (o caso sem COMMIT fica com o ON CONFLICT do Postgres)')]
    public function testCriarPrincipalSeFaltarComPrincipalGravadaPorOutraRequisicao(): void
    {
        $user = $this->usuario();
        $this->em->flush();
        // Outra requisição gravou a principal sem passar pela consulta desta (o INSERT direto simula a corrida).
        $this->em->getConnection()->executeStatement(
            "INSERT INTO inscricao_oab (user_id, numero, uf, tipo, status, criado_em) VALUES (?, '1', 'DF', 'principal', 'nao_verificada', NOW())",
            [$user->getId()],
        );

        $this->repo->criarPrincipalSeFaltar($user, '1', 'DF', StatusOab::NaoVerificada, null, null);

        self::assertCount(1, $this->repo->doUsuario($user));
    }

    #[TestDox('trocar para uma principal NOVA grava na ordem certa, sem estourar o índice parcial')]
    public function testGravarComNovaPrincipal(): void
    {
        $user = $this->usuario();
        $antiga = new InscricaoOab($user, '1', 'DF', TipoInscricaoOab::Principal);
        $this->em->persist($antiga);
        $this->em->flush();

        $this->repo->gravarComNovaPrincipal(new InscricaoOab($user, '2', 'SP', TipoInscricaoOab::Suplementar), [$antiga]);

        self::assertSame(['SP', 'DF'], array_map(static fn (InscricaoOab $i): string => $i->getUf(), $this->repo->doUsuario($user)));
        self::assertSame('SP', $user->getOabUf(), 'espelho na nova principal');
    }

    #[TestDox('o banco recusa uma segunda principal para a mesma pessoa')]
    public function testUmaPrincipal(): void
    {
        $user = $this->usuario();
        $this->inscrever($user, '1', 'DF', TipoInscricaoOab::Principal);
        $this->inscrever($user, '2', 'SP', TipoInscricaoOab::Principal);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    private function usuario(): User
    {
        $u = new User();
        $u->setEmail(uniqid('oab_') . '@test.com');
        $u->setFullName('Pessoa OAB');
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function inscrever(User $user, string $numero, string $uf, TipoInscricaoOab $tipo, StatusOab $status = StatusOab::NaoVerificada): void
    {
        $i = new InscricaoOab($user, $numero, $uf, $tipo);
        $i->registrarVerificacao($status, null);
        $this->em->persist($i);
    }
}
