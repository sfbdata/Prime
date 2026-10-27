<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Acompanhamento;

use App\Dashboard\DTO\EncerrarAcompanhamentoInput;
use App\Dashboard\DTO\GerirAcompanhamentoInput;
use App\Dashboard\Entity\AcompanhamentoDaPessoa;
use App\Dashboard\Exception\AcompanhamentoEmConflitoException;
use App\Dashboard\Exception\AcompanhamentoInvalidoException;
use App\Dashboard\Exception\AcompanhamentoNaoPermitidoException;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Repository\AcompanhamentoDaPessoaRepository;
use App\Dashboard\UseCase\CriarAcompanhamentoDaPessoaUseCase;
use App\Dashboard\UseCase\EncerrarAcompanhamentoDaPessoaUseCase;
use App\Dashboard\UseCase\GuardaDoAcompanhamento;
use App\Dashboard\UseCase\RegistrarAuditoriaDoAcompanhamentoUseCase;
use App\Dashboard\UseCase\RenovarAcompanhamentoDaPessoaUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarAcompanhamentoDaPessoaUseCase::class)]
#[CoversClass(RenovarAcompanhamentoDaPessoaUseCase::class)]
#[CoversClass(EncerrarAcompanhamentoDaPessoaUseCase::class)]
#[CoversClass(GuardaDoAcompanhamento::class)]
#[CoversClass(RegistrarAuditoriaDoAcompanhamentoUseCase::class)]
#[Group('dashboard')]
final class AcompanhamentoUseCasesTest extends TestCase
{
    private UserRepository&MockObject $users;
    private UserTenantRepository&MockObject $vinculos;
    private AcompanhamentoDaPessoaRepository&MockObject $repo;
    private EntityManagerInterface&MockObject $em;
    private Tenant $tenant;
    private User $gestor;
    private User $ana;
    private \DateTimeImmutable $agora;
    /** @var list<object> */
    private array $persistidos = [];

    protected function setUp(): void
    {
        $this->users    = $this->createMock(UserRepository::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->repo     = $this->createMock(AcompanhamentoDaPessoaRepository::class);
        $this->em       = $this->createMock(EntityManagerInterface::class);
        $this->tenant   = new Tenant();
        $this->gestor   = $this->usuario(1, ['ROLE_SUPER_ADMIN']);
        $this->ana      = $this->usuario(7);
        $this->agora    = new \DateTimeImmutable('2026-10-27 10:00:00');

        $this->users->method('findColaboradorAtivoPorIdETenant')->willReturnCallback(fn (int $id): ?User => $id === 7 ? $this->ana : ($id === 1 ? $this->gestor : null));
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f): mixed => $f());
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistidos[] = $o;
        });
        $this->repo->method('salvar')->willReturnCallback(function (AcompanhamentoDaPessoa $a): void {
            $this->persistidos[] = $a;
        });
    }

    /** @param list<string> $papeis */
    private function usuario(int $id, array $papeis = ['ROLE_USER']): User
    {
        $u = new User();
        $u->setRoles($papeis);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    private function guarda(): GuardaDoAcompanhamento
    {
        return new GuardaDoAcompanhamento($this->users, new RegraDosPrimarios($this->vinculos));
    }

    private function auditoria(): RegistrarAuditoriaDoAcompanhamentoUseCase
    {
        return new RegistrarAuditoriaDoAcompanhamentoUseCase($this->em);
    }

    private function criar(): CriarAcompanhamentoDaPessoaUseCase
    {
        return new CriarAcompanhamentoDaPessoaUseCase($this->guarda(), $this->repo, $this->auditoria(), $this->em);
    }

    private function pedido(int $pessoaId = 7, int $prazo = 7, ?User $ator = null): GerirAcompanhamentoInput
    {
        return new GerirAcompanhamentoInput($this->tenant, $ator ?? $this->gestor, $pessoaId, $prazo, $this->agora);
    }

    private function aberto(): AcompanhamentoDaPessoa
    {
        return new AcompanhamentoDaPessoa($this->tenant, $this->ana, $this->gestor, $this->agora, 1);
    }

    /** @return list<AuditLog> */
    private function logs(): array
    {
        return array_values(array_filter($this->persistidos, static fn (object $o): bool => $o instanceof AuditLog));
    }

    #[TestDox('Cria o acompanhamento com prazo de 7 dias e audita acomp_cria sem nome nem texto, na mesma transação')]
    public function testCria(): void
    {
        $this->repo->method('buscarAbertoDaPessoa')->willReturn(null);
        $this->em->expects(self::once())->method('flush');

        $out = $this->criar()->executar($this->pedido());

        self::assertTrue($out->aberto);
        self::assertSame('2026-11-03', $out->revisarEm?->format('Y-m-d'));
        self::assertFalse($out->vencido);
        self::assertCount(2, $this->persistidos);
        $log = $this->logs()[0];
        self::assertSame('acomp_cria', $log->getAction());
        self::assertLessThanOrEqual(10, strlen($log->getAction()));
        self::assertSame(['evento' => 'criou', 'pessoa_id' => 7, 'revisar_em' => '2026-11-03'], $log->getChanges());
    }

    #[TestDox('Prazos 1 e 2 dias valem; qualquer outro prazo dá AcompanhamentoInvalidoException e nada é gravado')]
    public function testPrazos(): void
    {
        $this->repo->method('buscarAbertoDaPessoa')->willReturn(null);
        self::assertSame('2026-10-28', $this->criar()->executar($this->pedido(7, 1))->revisarEm?->format('Y-m-d'));

        $this->persistidos = [];
        $this->expectException(AcompanhamentoInvalidoException::class);
        try {
            $this->criar()->executar($this->pedido(7, 3));
        } finally {
            self::assertSame([], $this->persistidos);
        }
    }

    #[TestDox('Já em acompanhamento: AcompanhamentoEmConflitoException e nada é gravado')]
    public function testDuplicadoAberto(): void
    {
        $this->repo->method('buscarAbertoDaPessoa')->willReturn($this->aberto());

        try {
            $this->criar()->executar($this->pedido());
            self::fail('Deveria recusar o duplicado.');
        } catch (AcompanhamentoEmConflitoException) {
            self::assertSame([], $this->persistidos);
        }
    }

    #[TestDox('Quem não é Master nem primário ativo recebe AcompanhamentoNaoPermitidoException')]
    public function testSecundarioNaoPode(): void
    {
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn(null);

        $this->expectException(AcompanhamentoNaoPermitidoException::class);
        $this->criar()->executar($this->pedido(7, 7, $this->usuario(2)));
    }

    #[TestDox('Pessoa de outro escritório ou inexistente: ColaboradorForaDoEscritorioException (vira 404)')]
    public function testForaDoEscritorio(): void
    {
        $this->expectException(ColaboradorForaDoEscritorioException::class);

        $this->criar()->executar($this->pedido(999));
    }

    #[TestDox('O próprio gestor não se coloca em acompanhamento')]
    public function testAlvoEOGestor(): void
    {
        $this->expectException(AcompanhamentoInvalidoException::class);

        $this->criar()->executar($this->pedido(1));
    }

    #[TestDox('A conta do Master não entra em acompanhamento')]
    public function testAlvoEOMaster(): void
    {
        $this->ana->setRoles(['ROLE_SUPER_ADMIN']);

        $this->expectException(AcompanhamentoInvalidoException::class);
        $this->criar()->executar($this->pedido(7));
    }

    #[TestDox('Renovar muda a data na mesma linha, audita acomp_renov e não cria outra linha')]
    public function testRenova(): void
    {
        $existente = $this->aberto();
        $this->repo->method('buscarAbertoDaPessoa')->willReturn($existente);

        $out = (new RenovarAcompanhamentoDaPessoaUseCase($this->guarda(), $this->repo, $this->auditoria(), $this->em))->executar($this->pedido(7, 2));

        self::assertSame('2026-10-29', $existente->getRevisarEm()->format('Y-m-d'));
        self::assertSame('2026-10-29', $out->revisarEm?->format('Y-m-d'));
        self::assertCount(1, $this->persistidos, 'só a auditoria; nenhuma linha nova de acompanhamento');
        self::assertSame('acomp_renov', $this->logs()[0]->getAction());
    }

    #[TestDox('Renovar e encerrar sem acompanhamento aberto: AcompanhamentoEmConflitoException')]
    public function testSemAberto(): void
    {
        $this->repo->method('buscarAbertoDaPessoa')->willReturn(null);

        $renovar = new RenovarAcompanhamentoDaPessoaUseCase($this->guarda(), $this->repo, $this->auditoria(), $this->em);
        $encerrar = new EncerrarAcompanhamentoDaPessoaUseCase($this->guarda(), $this->repo, $this->auditoria(), $this->em);
        $contagem = 0;
        foreach ([fn () => $renovar->executar($this->pedido()), fn () => $encerrar->executar(new EncerrarAcompanhamentoInput($this->tenant, $this->gestor, 7, $this->agora))] as $acao) {
            try {
                $acao();
            } catch (AcompanhamentoEmConflitoException) {
                ++$contagem;
            }
        }

        self::assertSame(2, $contagem);
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('Encerrar grava quem e quando, audita acomp_fim e devolve "sem acompanhamento"')]
    public function testEncerra(): void
    {
        $existente = $this->aberto();
        $this->repo->method('buscarAbertoDaPessoa')->willReturn($existente);

        $out = (new EncerrarAcompanhamentoDaPessoaUseCase($this->guarda(), $this->repo, $this->auditoria(), $this->em))
            ->executar(new EncerrarAcompanhamentoInput($this->tenant, $this->gestor, 7, $this->agora));

        self::assertFalse($existente->estaAberto());
        self::assertSame($this->gestor, $existente->getEncerradoPor());
        self::assertFalse($out->aberto);
        self::assertSame('acomp_fim', $this->logs()[0]->getAction());
    }
}
