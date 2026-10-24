<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AlterarIaDoEscritorioInput;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Exception\EscritorioNaoEncontradoException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\UseCase\AlterarIaDoEscritorioUseCase;
use App\Repository\TenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validation;

#[CoversClass(AlterarIaDoEscritorioUseCase::class)]
#[CoversClass(AlterarIaDoEscritorioInput::class)]
final class AlterarIaDoEscritorioUseCaseTest extends TestCase
{
    private TenantRepository&MockObject $tenants;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private Tenant $tenant;
    private User $master;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->tenants = $this->createMock(TenantRepository::class);
        $this->tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => $id === 7 ? $this->tenant : null);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    private function sut(): AlterarIaDoEscritorioUseCase
    {
        return new AlterarIaDoEscritorioUseCase($this->tenants, $this->configuracoes);
    }

    #[TestDox('escritório sem linha: a configuração nasce aqui, liberada, com o modo e o orçamento, e a IA segue desligada no escritório')]
    public function testCriaALinha(): void
    {
        $this->configuracoes->method('findDoTenantParaOMaster')->willReturn(null);
        $salva = null;
        $this->configuracoes->expects($this->once())->method('salvar')->willReturnCallback(function (ConfiguracaoDeInteligencia $c, bool $flush) use (&$salva): void {
            self::assertTrue($flush);
            $salva = $c;
        });

        $this->sut()->executar(new AlterarIaDoEscritorioInput(7, true, 'limite_gratis', 2550), $this->master);

        self::assertInstanceOf(ConfiguracaoDeInteligencia::class, $salva);
        self::assertSame($this->tenant, $salva->getTenant());
        self::assertTrue($salva->isLiberadaPeloMaster());
        self::assertFalse($salva->isHabilitada(), 'liberar não liga: o admin ainda precisa do aceite');
        self::assertSame(ModoDeCobrancaDaIa::LimiteGratis, $salva->getModoDeCobranca());
        self::assertSame(2550, $salva->getOrcamentoMensalCentavos());
    }

    #[TestDox('linha existente: bloquear mantém o aceite do escritório; orçamento nulo = sem orçamento')]
    public function testBloqueiaALinhaExistente(): void
    {
        $existente = new ConfiguracaoDeInteligencia($this->tenant);
        $existente->liberarPeloMaster();
        $existente->atualizar(true, 50, 500, true, new User());
        $existente->definirCobranca(ModoDeCobrancaDaIa::Adicional, 900);
        $this->configuracoes->method('findDoTenantParaOMaster')->with($this->tenant)->willReturn($existente);
        $this->configuracoes->expects($this->once())->method('salvar')->with($existente, true);

        $this->sut()->executar(new AlterarIaDoEscritorioInput(7, false, 'incluso', null), $this->master);

        self::assertFalse($existente->isLiberadaPeloMaster());
        self::assertTrue($existente->isHabilitada());
        self::assertNotNull($existente->getConsentimentoEnvioExternoEm());
        self::assertSame(ModoDeCobrancaDaIa::Incluso, $existente->getModoDeCobranca());
        self::assertNull($existente->getOrcamentoMensalCentavos());
    }

    #[TestDox('escritório inexistente, suspenso ou excluído: EscritorioNaoEncontrado e nada é gravado')]
    public function testEscritorioInvalido(): void
    {
        $this->configuracoes->expects($this->never())->method('salvar');
        foreach ([fn () => null, fn () => $this->tenant->setIsActive(false), fn () => $this->tenant->setIsActive(true)->setExcluidoEm(new \DateTimeImmutable())] as $i => $preparar) {
            $preparar();
            $id = $i === 0 ? 99 : 7;
            try {
                $this->sut()->executar(new AlterarIaDoEscritorioInput($id, true, 'incluso', null), $this->master);
                self::fail('deveria recusar');
            } catch (EscritorioNaoEncontradoException $e) {
                self::assertSame($id, $e->tenantId);
            }
        }
    }

    #[TestDox('modo fora do enum escapando da validação: InvalidArgument, nada gravado')]
    public function testModoInvalido(): void
    {
        $this->configuracoes->method('findDoTenantParaOMaster')->willReturn(null);
        $this->configuracoes->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);
        $this->sut()->executar(new AlterarIaDoEscritorioInput(7, true, 'gratis_para_sempre', null), $this->master);
    }

    #[TestDox('o DTO recusa modo fora da lista, orçamento negativo e acima do teto')]
    public function testValidacaoDoInput(): void
    {
        $validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validador->validate(new AlterarIaDoEscritorioInput(7, true, 'adicional', 0)));
        self::assertSame('Escolha um modo de cobrança da lista.', (string) $validador->validate(new AlterarIaDoEscritorioInput(7, true, 'outro', null))->get(0)->getMessage());
        self::assertSame('O orçamento mensal precisa ficar entre US$ 0,00 e US$ 100.000,00.', (string) $validador->validate(new AlterarIaDoEscritorioInput(7, true, 'incluso', -100))->get(0)->getMessage());
        self::assertCount(1, $validador->validate(new AlterarIaDoEscritorioInput(7, true, 'incluso', 10_000_001)));
    }

    #[TestDox('quem não é Master: AccessDenied antes de procurar o escritório')]
    public function testNaoMaster(): void
    {
        $this->tenants->expects($this->never())->method('find');

        $this->expectException(AccessDeniedException::class);
        $this->sut()->executar(new AlterarIaDoEscritorioInput(7, true, 'incluso', null), new User());
    }
}
