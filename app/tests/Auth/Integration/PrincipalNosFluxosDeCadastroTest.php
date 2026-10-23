<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\DTO\AceitarConvitePlataformaInput;
use App\Auth\Entity\InscricaoOab;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Auth\UseCase\AceitarConvitePlataformaUseCase;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Tenant\DTO\CriarEscritorioInput;
use App\Tenant\UseCase\CriarEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Toda pessoa com OAB no User tem a inscrição principal, senão sai da fila de revisão do Master, que é por
 * inscrição (spec docs/specs/oab-revisao-por-inscricao.md). Os três fluxos que gravam a OAB no User criam a
 * principal no mesmo flush.
 */
#[CoversClass(EspelhoDaOabPrincipal::class)]
#[CoversClass(ConfirmarCadastroUseCase::class)]
#[CoversClass(AceitarConvitePlataformaUseCase::class)]
#[CoversClass(CriarEscritorioUseCase::class)]
final class PrincipalNosFluxosDeCadastroTest extends KernelTestCase
{
    /** O filtro Pendentes da fila. */
    private const PENDENTES = [StatusOab::NaoVerificada, StatusOab::Divergente];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('Link de cadastro pendente com OAB (fluxo antigo, ainda confirmado): a principal nasce Pendente e aparece na fila')]
    public function testCadastroPendenteCriaPrincipal(): void
    {
        $c  = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        // O cadastro público novo (CriarContaEEscritorioUseCase) cria a conta sem OAB; os links pendentes de antes dele
        // ainda são confirmados pelo ConfirmarCadastroUseCase, com a OAB do pedido.
        $pendente = new CadastroPendente(
            email: 'ana' . uniqid() . '@adv.com',
            token: bin2hex(random_bytes(16)),
            nomeCompleto: 'Dra. Ana Souza',
            nomeEscritorio: 'Banca Souza',
            oabNumero: '12345',
            oabUf: 'SP',
            senhaHash: 'hash-qualquer',
            ip: '127.0.0.1',
            expiresAt: new \DateTimeImmutable('+1 day'),
            userAgent: 'ua-teste',
        );
        $pendente->setOabStatus(StatusOab::NaoVerificada);
        $em->persist($pendente);
        $em->flush();

        $user = $c->get(ConfirmarCadastroUseCase::class)->executar($pendente->getToken());

        $this->assertNaFila($user, self::PENDENTES, '12345', 'SP', StatusOab::NaoVerificada);
    }

    #[TestDox('Convite da plataforma com OAB: a principal nasce Pendente e aparece na fila')]
    public function testConvitePlataformaCriaPrincipal(): void
    {
        $c  = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $token   = bin2hex(random_bytes(16));
        $convite = new Invitation(
            email: 'convidado_' . uniqid() . '@adv.com',
            tokenHash: Invitation::hashDoToken($token),
            type: 'platform',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($convite);
        $em->flush();

        $user = $c->get(AceitarConvitePlataformaUseCase::class)->executar(new AceitarConvitePlataformaInput(
            token: $token,
            fullName: 'Dr. Bruno Lima',
            senha: 'senha-forte-123',
            oabNumero: '54321',
            oabUf: 'RJ',
            aceiteTermos: true,
            ip: '127.0.0.1',
        ));

        $this->assertNaFila($user, self::PENDENTES, '54321', 'RJ', StatusOab::NaoVerificada);
    }

    #[TestDox('Criar escritório com a OAB já confirmada: a principal nasce Confirmada e aparece na fila')]
    public function testCriarEscritorioComOabConfirmadaCriaPrincipal(): void
    {
        $c  = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('dono_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Carla Dias');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $user->setOabNumero('49613');
        $user->setOabUf('DF');
        $user->setOabStatus(StatusOab::Confirmada);
        $user->setOabVerificadaEm(new \DateTimeImmutable('2026-10-01 10:00:00'));
        $em->persist($user);
        $em->flush();

        $input       = new CriarEscritorioInput();
        $input->nome = 'Banca Dias ' . uniqid();
        $c->get(CriarEscritorioUseCase::class)->executar($input, $user);

        $this->assertNaFila($user, [StatusOab::Confirmada], '49613', 'DF', StatusOab::Confirmada);
    }

    #[TestDox('Criar escritório com a principal já existente: não duplica, a Confirmada segue Confirmada e as suplementares ficam intactas')]
    public function testCriarEscritorioComPrincipalExistenteSoAcompanha(): void
    {
        $c  = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('dono_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Carla Dias');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);

        $verificadaEm = new \DateTimeImmutable('2026-10-01 10:00:00');
        $principal    = new InscricaoOab($user, '49613', 'DF', TipoInscricaoOab::Principal);
        $principal->registrarVerificacao(StatusOab::Confirmada, 'CARLA DIAS', $verificadaEm);
        $principal->espelharNoUsuario();
        $em->persist($principal);
        $sup = new InscricaoOab($user, '700', 'GO', TipoInscricaoOab::Suplementar);
        $sup->registrarVerificacao(StatusOab::Divergente, null);
        $em->persist($sup);
        $em->flush();
        $principalId = (int) $principal->getId();

        $input       = new CriarEscritorioInput();
        $input->nome = 'Banca Dias ' . uniqid();
        $c->get(CriarEscritorioUseCase::class)->executar($input, $user);
        $em->clear();

        $linhas = $em->getConnection()->fetchAllAssociative(
            'SELECT id, numero, uf, tipo, status, nome_oficial, verificada_em FROM inscricao_oab WHERE user_id = ? ORDER BY uf',
            [$user->getId()],
        );
        self::assertSame([
            ['id' => $principalId, 'numero' => '49613', 'uf' => 'DF', 'tipo' => 'principal', 'status' => 'confirmada', 'nome_oficial' => 'CARLA DIAS', 'verificada_em' => '2026-10-01 10:00:00'],
            ['id' => (int) $sup->getId(), 'numero' => '700', 'uf' => 'GO', 'tipo' => 'suplementar', 'status' => 'divergente', 'nome_oficial' => null, 'verificada_em' => $linhas[1]['verificada_em']],
        ], $linhas);
        $this->assertNaFila($user, [StatusOab::Confirmada], '49613', 'DF', StatusOab::Confirmada);
    }

    /** @param list<StatusOab> $filtro */
    private function assertNaFila(User $user, array $filtro, string $numero, string $uf, StatusOab $status): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $fila = static::getContainer()->get(InscricaoOabRepository::class)->paraRevisao($filtro, (string) $user->getEmail());
        self::assertCount(1, $fila, 'a principal aparece na fila');
        self::assertInstanceOf(InscricaoOab::class, $fila[0]);
        self::assertTrue($fila[0]->ehPrincipal());
        self::assertSame([$numero, $uf, $status], [$fila[0]->getNumero(), $fila[0]->getUf(), $fila[0]->getStatus()]);
    }
}
