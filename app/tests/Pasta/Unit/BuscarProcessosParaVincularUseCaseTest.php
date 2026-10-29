<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ProcessoParaVincularOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\TipoDeApenso;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\BuscarProcessosParaVincularUseCase;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuscarProcessosParaVincularUseCase::class)]
#[CoversClass(ProcessoParaVincularOutput::class)]
final class BuscarProcessosParaVincularUseCaseTest extends TestCase
{
    private ProcessoRepository&MockObject $processos;
    private PastaRepository&MockObject $pastas;
    private PermissionChecker&MockObject $permissoes;
    private User $usuario;
    private BuscarProcessosParaVincularUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->processos = $this->createMock(ProcessoRepository::class);
        $this->pastas    = $this->createMock(PastaRepository::class);
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->useCase   = new BuscarProcessosParaVincularUseCase($this->processos, $this->pastas, $this->permissoes);
        $this->usuario   = new User();
        $this->tenant    = new Tenant();
    }

    #[TestDox('Busca no tenant com limite 8, exclui os já vinculados à pasta e repassa o termo aparado')]
    public function testBuscaComLimiteEExcluindoVinculados(): void
    {
        $pasta = $this->pasta('0100');
        $vinculado = $this->processo(7, '11111111111111111111');
        $pasta->vincularProcesso($vinculado);

        $this->processos->expects(self::once())->method('buscarPorTermoDoTenant')
            ->with('execução', $this->tenant, [7], BuscarProcessosParaVincularUseCase::LIMITE)
            ->willReturn([]);
        $this->pastas->expects(self::once())->method('vinculosDosProcessos')->with([], $this->tenant)->willReturn([]);

        self::assertSame([], $this->useCase->executar($pasta, '  execução ', $this->tenant, $this->usuario));
    }

    #[TestDox('Origem: "pasta" quando é processo de uma pasta, "apensado" quando só é apensado, "push" fora de pasta')]
    public function testOrigemDeCadaProcesso(): void
    {
        $pasta = $this->pasta('0100');
        $daPasta = $this->processo(1, '00000010120248070001', 'Execução Fiscal', 'Dívida ativa', 'TJDFT');
        $soApensado = $this->processo(2, '00000020120248070001');
        $doPush = $this->processo(3, '00000030120248070001');
        $emAmbas = $this->processo(4, '00000040120248070001');

        $outra = $this->pasta('0200');
        $terceira = $this->pasta('');
        $vinculos = [
            new PastaProcesso($outra, $daPasta),
            $this->apensado($outra, $soApensado),
            $this->apensado($outra, $emAmbas),
            new PastaProcesso($terceira, $emAmbas),
        ];

        $this->processos->method('buscarPorTermoDoTenant')->willReturn([$daPasta, $soApensado, $doPush, $emAmbas]);
        $this->pastas->method('vinculosDosProcessos')->with([1, 2, 3, 4], $this->tenant)->willReturn($vinculos);
        // Uma consulta por pasta distinta (0200 e a sem número), não por item.
        $this->permissoes->expects(self::exactly(2))->method('canAccessResource')->willReturn(true);

        $itens = array_map(static fn (ProcessoParaVincularOutput $i): array => $i->paraJson(), $this->useCase->executar($pasta, '', $this->tenant, $this->usuario));

        self::assertSame(['tipo' => 'pasta', 'pasta' => '0200'], $itens[0]['origem']);
        self::assertSame(['tipo' => 'apensado', 'pasta' => '0200'], $itens[1]['origem']);
        self::assertSame(['tipo' => 'push', 'pasta' => null], $itens[2]['origem']);
        // Apensado numa pasta e processo de outra: vale a pasta em que ele é o processo (sem número: "#id").
        self::assertSame(['tipo' => 'pasta', 'pasta' => '#' . $terceira->getId()], $itens[3]['origem']);

        self::assertSame(1, $itens[0]['id']);
        self::assertSame('00000010120248070001', $itens[0]['numero']);
        self::assertSame($daPasta->getClasseProcessual(), $itens[0]['classe']);
        self::assertSame($daPasta->getAssuntoProcessual(), $itens[0]['acao']);
        self::assertNotSame('', $itens[0]['classe']);
        self::assertSame('TJDFT', $itens[0]['tribunal']);
    }

    #[TestDox('Pasta de outro escritório: recusa sem consultar nada')]
    public function testPastaDeOutroEscritorio(): void
    {
        $pasta = $this->pasta('0100');
        $pasta->setTenant(new Tenant());

        $this->processos->expects(self::never())->method('buscarPorTermoDoTenant');
        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase->executar($pasta, '', $this->tenant, $this->usuario);
    }

    #[TestDox('Sem permissão de VER a pasta de origem, o número não vai: "Outra pasta" / "Apensado · outra pasta"')]
    public function testOrigemSemPermissaoEscondeONumero(): void
    {
        $pasta = $this->pasta('0100');
        $daOutra = $this->processo(1, '00000010120248070001');
        $apensado = $this->processo(2, '00000020120248070001');
        $outra = $this->pasta('0300');

        $this->processos->method('buscarPorTermoDoTenant')->willReturn([$daOutra, $apensado]);
        $this->pastas->method('vinculosDosProcessos')->willReturn([new PastaProcesso($outra, $daOutra), $this->apensado($outra, $apensado)]);
        $this->permissoes->expects(self::once())->method('canAccessResource')
            ->with($this->usuario, $this->tenant, 'pasta', $outra->getId(), 'view')
            ->willReturn(false);

        $itens = array_map(static fn (ProcessoParaVincularOutput $i): array => $i->paraJson(), $this->useCase->executar($pasta, '', $this->tenant, $this->usuario));

        self::assertSame(['tipo' => 'pasta', 'pasta' => null], $itens[0]['origem']);
        self::assertSame(['tipo' => 'apensado', 'pasta' => null], $itens[1]['origem']);
    }

    private function pasta(string $nup): Pasta
    {
        static $id = 500;
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        if ($nup !== '') {
            $pasta->setNup($nup);
        }
        $this->definirId($pasta, ++$id);

        return $pasta;
    }

    private function processo(int $id, string $numero, string $classe = '', string $assunto = '', string $tribunal = ''): Processo
    {
        $processo = new Processo();
        $processo->setTenant($this->tenant);
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual($classe);
        $processo->setAssuntoProcessual($assunto);
        $processo->setSiglaTribunal($tribunal);
        $this->definirId($processo, $id);

        return $processo;
    }

    private function apensado(Pasta $pasta, Processo $processo): PastaProcesso
    {
        return (new PastaProcesso($pasta, $processo))->setTipoApenso(TipoDeApenso::Embargos);
    }

    private function definirId(object $entidade, int $id): void
    {
        $prop = new \ReflectionProperty($entidade, 'id');
        $prop->setValue($entidade, $id);
    }

    #[TestDox('Processo em duas pastas, a principal invisível e a outra visível: a origem traz o número da visível')]
    public function testPrefereAPastaQueOUsuarioVe(): void
    {
        $pasta = $this->pasta('0100');
        $processo = $this->processo(1, '00000010120248070001');
        $invisivel = $this->pasta('0400');
        $visivel = $this->pasta('0500');
        $principal = (new PastaProcesso($invisivel, $processo))->setPrincipal(true);

        $this->processos->method('buscarPorTermoDoTenant')->willReturn([$processo]);
        $this->pastas->method('vinculosDosProcessos')->willReturn([$principal, new PastaProcesso($visivel, $processo)]);
        $this->permissoes->method('canAccessResource')->willReturnCallback(
            static fn (User $u, Tenant $t, string $tipo, int $id): bool => $id === $visivel->getId(),
        );

        $item = $this->useCase->executar($pasta, '', $this->tenant, $this->usuario)[0]->paraJson();

        self::assertSame(['tipo' => 'pasta', 'pasta' => '0500'], $item['origem']);
    }
}
