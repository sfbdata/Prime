<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Support;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Entity\IndicacaoAlerta;
use App\Indicacao\Entity\IndicacaoConfig;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Repository\IndicacaoPausaRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\AvisoDeDisparoAlto;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Indicacao\Service\PortaoDaIndicacao;
use App\Indicacao\Service\RegistroDeIndicacao;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Monta o caminho comum dos envios com os repositórios do domínio em memória (dublês configuráveis): o que foi
 * gravado, as contagens das janelas, a pausa, os Masters e as notificações. A transação travada só executa o trabalho.
 * As contagens somam o que já foi gravado no teste, como o banco faria.
 *
 * Trait de TestCase (usa o createStub do PHPUnit); chame {@see montarCenario()} no setUp.
 */
trait CenarioDeIndicacao
{
    private const AGORA = '2026-10-19 15:00:00';

    public IndicacaoConfig $config;
    public bool $pausado = false;
    public int $em24h = 0;
    public int $em30d = 0;
    public bool $membro = false;
    /** @var list<Indicacao> */
    public array $gravadas = [];
    /** @var list<IndicacaoAlerta> */
    public array $alertas = [];
    /** @var list<array{User, string, string, ?string}> */
    public array $notificacoes = [];
    /** @var list<int> */
    public array $travas = [];
    /** @var list<User> */
    public array $masters = [];
    public int $consultasDeMembro = 0;

    public MockClock $relogio;
    public IndicacaoRepository $indicacoes;
    public IndicacaoConfigRepository $configs;
    public IndicacaoPausaRepository $pausas;
    public IndicacaoAlertaRepository $repositorioDeAlertas;
    public IndicacaoCodigoRepository $codigos;

    private function montarCenario(): void
    {
        $this->config = new IndicacaoConfig();
        $this->relogio = new MockClock(new \DateTimeImmutable(self::AGORA));
        $this->indicacoes = $this->repositorioDeIndicacoes();
        $this->configs = $this->createStub(IndicacaoConfigRepository::class);
        $this->configs->method('obter')->willReturnCallback(fn (): IndicacaoConfig => $this->config);
        $this->pausas = $this->createStub(IndicacaoPausaRepository::class);
        $this->pausas->method('estaPausado')->willReturnCallback(fn (): bool => $this->pausado);
        $this->repositorioDeAlertas = $this->createStub(IndicacaoAlertaRepository::class);
        $this->repositorioDeAlertas->method('salvar')->willReturnCallback(function (IndicacaoAlerta $a): void {
            $this->alertas[] = $a;
        });
        $this->codigos = $this->createStub(IndicacaoCodigoRepository::class);
        $this->codigos->method('codigoDoUsuario')->willReturn('ABCD2345');
    }

    private function portao(): PortaoDaIndicacao
    {
        return new PortaoDaIndicacao($this->configs, $this->pausas, $this->indicacoes, $this->relogio);
    }

    private function codigo(): CodigoDeIndicacao
    {
        return new CodigoDeIndicacao($this->codigos, self::urls());
    }

    private function registro(): RegistroDeIndicacao
    {
        return new RegistroDeIndicacao($this->indicacoes, $this->configs, $this->repositorioDeAlertas, $this->portao(), $this->aviso(), $this->relogio);
    }

    private static function urls(): UrlGeneratorInterface
    {
        return new class () implements UrlGeneratorInterface {
            private \Symfony\Component\Routing\RequestContext $contexto;

            public function __construct()
            {
                $this->contexto = new \Symfony\Component\Routing\RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return $name === CodigoDeIndicacao::ROTA ? 'https://bluejus.test/i/' . $parameters['codigo'] : '/master';
            }

            public function setContext(\Symfony\Component\Routing\RequestContext $context): void
            {
                $this->contexto = $context;
            }

            public function getContext(): \Symfony\Component\Routing\RequestContext
            {
                return $this->contexto;
            }
        };
    }

    private function aviso(): AvisoDeDisparoAlto
    {
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findComPapelGlobal')->willReturnCallback(fn (): array => $this->masters);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findActiveByUser')->willReturnCallback(static fn (User $u): array => [new UserTenant($u, Pessoas::escritorio(77, 'Conta do Master', $u))]);
        $notificacoes = $this->createStub(NotificacaoService::class);
        $notificacoes->method('criar')->willReturnCallback(function (User $u, $t, string $tipo, string $titulo, ?string $texto): Notificacao {
            $this->notificacoes[] = [$u, $tipo, $titulo, $texto];

            return new Notificacao();
        });

        return new AvisoDeDisparoAlto($usuarios, $vinculos, $notificacoes, self::urls());
    }

    private function repositorioDeIndicacoes(): IndicacaoRepository
    {
        $repo = $this->createStub(IndicacaoRepository::class);
        $agora = new \DateTimeImmutable(self::AGORA);
        $repo->method('emTransacaoTravada')->willReturnCallback(function (int $id, \Closure $trabalho): mixed {
            $this->travas[] = $id;

            return $trabalho();
        });
        $repo->method('contarDoUsuarioDesde')->willReturnCallback(fn (int $id, \DateTimeImmutable $desde): int => $desde->getTimestamp() === $agora->modify('-24 hours')->getTimestamp() ? $this->em24h + count($this->gravadas) : $this->em30d + count($this->gravadas));
        $repo->method('salvar')->willReturnCallback(function (Indicacao $i): void {
            $this->gravadas[] = $i;
        });
        $repo->method('emailEhMembroDoEscritorio')->willReturnCallback(function (): bool {
            ++$this->consultasDeMembro;

            return $this->membro;
        });

        return $repo;
    }
}
