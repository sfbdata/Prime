<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Apoio;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Event\EspacoFoiPedido;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\UseCase\PedirEspacoUseCase;
use App\Contribua\UseCase\RegistrarApoioUseCase;
use App\Contribua\UseCase\RegistrarMotivoDeNaoApoiarUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(RegistrarApoioUseCase::class)]
#[CoversClass(RegistrarMotivoDeNaoApoiarUseCase::class)]
#[CoversClass(PedirEspacoUseCase::class)]
#[CoversClass(Apoio::class)]
final class AcoesDoContribuaUseCaseTest extends TestCase
{
    #[TestDox('Apoio: grava e responde com a conta; acima de R$ 1 milhão é recusado')]
    public function testApoio(): void
    {
        $apoios = $this->createMock(ApoioRepository::class);
        $apoios->method('apoiouDesde')->willReturn(false);
        $apoios->expects(self::once())->method('salvar');
        $useCase = new RegistrarApoioUseCase($apoios, $this->aviso(), $this->urls());

        self::assertSame('Obrigado pelo apoio! Ele foi registrado para a conta Nº 000007.', $useCase->executar($this->quem(primario: false), 5000));

        $this->expectExceptionMessage('Informe um valor válido.');
        new Apoio(new Tenant(), null, Apoio::VALOR_MAXIMO_CENTAVOS + 1);
    }

    #[TestDox('Motivo: o segundo em 30 dias é ignorado, sem gravar')]
    public function testMotivoUmPorMes(): void
    {
        $motivos = $this->createMock(MotivoDeNaoApoiarRepository::class);
        $motivos->method('respondeuDesde')->willReturn(true);
        $motivos->expects(self::never())->method('salvar');

        (new RegistrarMotivoDeNaoApoiarUseCase($motivos, $this->aviso(), $this->urls()))->executar($this->quem(primario: false), 'Sem orçamento agora');
    }

    #[TestDox('Pedir espaço: secundário é recusado; Free é recusado; plano maior vai para a lista de espera')]
    public function testPedirEspaco(): void
    {
        $despachados = [];
        $despachante = $this->createStub(EventDispatcherInterface::class);
        $despachante->method('dispatch')->willReturnCallback(static function (object $e) use (&$despachados): object {
            $despachados[] = $e;

            return $e;
        });
        $eventos = new EventosDoEspacoAposConfirmar($despachante);
        $useCase = new PedirEspacoUseCase($this->planos(), $this->situacao(), $this->aviso(), $this->urls(), $eventos);

        self::assertSame(PedirEspacoUseCase::PEDIDO_REGISTRADO, $useCase->executar($this->quem(primario: true), 'essencial'));
        $eventos->aoResponder(new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response()));
        self::assertCount(1, $despachados);
        self::assertInstanceOf(EspacoFoiPedido::class, $despachados[0]);
        self::assertSame([7, 'essencial'], [$despachados[0]->tenantId, $despachados[0]->codigoDoPlano]);
        try {
            $useCase->executar($this->quem(primario: false), 'essencial');
            self::fail('o secundário não pede espaço');
        } catch (\DomainException $e) {
            self::assertSame('Quem amplia o espaço é o usuário primário do escritório.', $e->getMessage());
        }
        $this->expectExceptionMessage('Escolha um plano maior que o espaço atual.');
        $useCase->executar($this->quem(primario: true), 'free');
    }

    private function quem(bool $primario): Solicitante
    {
        $escritorio = (new Tenant())->setName('Escritório');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, 7);

        return new Solicitante(new User(), $escritorio, false, $primario);
    }

    private function planos(): PlanoRepository
    {
        $free = new Plano('free', 'Free', 10, 0, 'Free', 1, 3);
        $essencial = new Plano('essencial', 'Essencial', 20, 1990, 'Essencial', 2, 8);
        $planos = $this->createStub(PlanoRepository::class);
        $planos->method('free')->willReturn($free);
        $planos->method('findOneBy')->willReturnCallback(static fn (array $c): ?Plano => ['free' => $free, 'essencial' => $essencial][$c['codigo']] ?? null);

        return $planos;
    }

    private function situacao(): SituacaoDoEspaco
    {
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn(RetratoDoEspaco::BYTES_POR_GB);
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());

        return new SituacaoDoEspaco($consumo, $this->createStub(EspacoDoEscritorioRepository::class), $this->planos(), $configuracoes);
    }

    private function aviso(): AvisoAoMaster
    {
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findComPapelGlobal')->willReturn([]);

        return new AvisoAoMaster($usuarios, $this->createStub(UserTenantRepository::class), $this->createStub(NotificacaoService::class), new NullLogger());
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/contribua');

        return $urls;
    }
}
