<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Inteligencia\UseCase\AlterarLiberacaoDaIaParaTodosUseCase;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * BlueJus IA (M5.9): o Master libera ou bloqueia a IA de todos os escritórios ativos de uma vez. Só mexe na liberação:
 * cobrança e orçamento de cada escritório ficam como estão.
 */
#[CoversClass(AlterarLiberacaoDaIaParaTodosUseCase::class)]
#[CoversClass(GuardaDoMasterDaIa::class)]
final class AlterarLiberacaoDaIaParaTodosUseCaseTest extends TestCase
{
    private TenantRepository&MockObject $tenants;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private User $master;

    protected function setUp(): void
    {
        $this->tenants = $this->createMock(TenantRepository::class);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    private function sut(): AlterarLiberacaoDaIaParaTodosUseCase
    {
        return new AlterarLiberacaoDaIaParaTodosUseCase($this->tenants, $this->configuracoes, $this->escopo);
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    /** @param list<array{int, bool}> $linhas */
    private function tabela(array $linhas): void
    {
        $this->configuracoes->method('listarEscritoriosParaOMaster')->willReturn(array_map(
            static fn (array $l): array => [
                'tenant_id' => $l[0], 'nome' => 'E' . $l[0], 'liberada' => $l[1], 'habilitada' => false,
                'aceite_em' => null, 'modo' => null, 'orcamento' => null,
            ],
            $linhas,
        ));
    }

    #[TestDox('liberar todos: quem estava bloqueado ou sem linha muda (cobrança e orçamento intactos), cada um no próprio escopo de auditoria; quem já estava liberado não é regravado')]
    public function testLiberaTodos(): void
    {
        $um = $this->tenant(1);
        $dois = $this->tenant(2);
        $tres = $this->tenant(3);
        $this->tabela([[1, false], [2, false], [3, true]]);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => [1 => $um, 2 => $dois, 3 => $tres][$id] ?? null);
        $existente = new ConfiguracaoDeInteligencia($um);
        $existente->definirCobranca(ModoDeCobrancaDaIa::Adicional, 900);
        $this->configuracoes->method('findDoTenantParaOMaster')->willReturnCallback(fn (Tenant $t): ?ConfiguracaoDeInteligencia => $t === $um ? $existente : null);
        $gravadas = [];
        $this->configuracoes->expects($this->exactly(2))->method('salvar')->willReturnCallback(function (ConfiguracaoDeInteligencia $c, bool $flush) use (&$gravadas): void {
            self::assertTrue($flush);
            self::assertTrue($c->isLiberadaPeloMaster());
            self::assertFalse($c->isHabilitada(), 'liberar não liga a IA no escritório');
            $gravadas[(int) $c->getTenant()->getId()] = $this->escopo->estaLigado() ? $this->escopo->contaAlvo() : null;
        });

        $alterados = $this->sut()->executar(true, $this->master);

        self::assertSame(2, $alterados);
        self::assertSame([1 => 1, 2 => 2], $gravadas);
        self::assertSame(ModoDeCobrancaDaIa::Adicional, $existente->getModoDeCobranca());
        self::assertSame(900, $existente->getOrcamentoMensalCentavos());
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('bloquear todos: só quem estava liberado muda, e o aceite do escritório fica')]
    public function testBloqueiaTodos(): void
    {
        $um = $this->tenant(1);
        $this->tabela([[1, true], [2, false]]);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => 1 === $id ? $um : null);
        $existente = new ConfiguracaoDeInteligencia($um);
        $existente->liberarPeloMaster();
        $existente->atualizar(true, 50, 500, true, new User());
        $this->configuracoes->method('findDoTenantParaOMaster')->with($um)->willReturn($existente);
        $this->configuracoes->expects($this->once())->method('salvar')->with($existente, true);

        self::assertSame(1, $this->sut()->executar(false, $this->master));
        self::assertFalse($existente->isLiberadaPeloMaster());
        self::assertTrue($existente->isHabilitada());
        self::assertNotNull($existente->getConsentimentoEnvioExternoEm());
    }

    #[TestDox('todos já no estado pedido: devolve 0 e não grava nada')]
    public function testNinguemMuda(): void
    {
        $this->tabela([[1, false], [2, false]]);
        $this->tenants->expects($this->never())->method('find');
        $this->configuracoes->expects($this->never())->method('salvar');

        self::assertSame(0, $this->sut()->executar(false, $this->master));
    }

    #[TestDox('escritório que ficou suspenso, excluído ou sumiu entre a lista e a gravação não é tocado')]
    public function testInativoOuExcluidoNaoEntra(): void
    {
        $suspenso = $this->tenant(1)->setIsActive(false);
        $excluido = $this->tenant(2)->setIsActive(true)->setExcluidoEm(new \DateTimeImmutable());
        $this->tabela([[1, false], [2, false], [3, false]]);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => [1 => $suspenso, 2 => $excluido][$id] ?? null);
        $this->configuracoes->expects($this->never())->method('salvar');

        self::assertSame(0, $this->sut()->executar(true, $this->master));
    }

    #[TestDox('quem não é Master: AccessDenied antes de listar qualquer escritório')]
    public function testNaoMaster(): void
    {
        $this->configuracoes->expects($this->never())->method('listarEscritoriosParaOMaster');
        $this->configuracoes->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);
        $this->sut()->executar(true, (new User())->setRoles(['ROLE_USER', 'ROLE_MASTER_PRIMARIO']));
    }
}
