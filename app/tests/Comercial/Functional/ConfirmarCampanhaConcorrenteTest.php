<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\PedidoDeCampanha;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Service\MontadorDeEmail;
use App\Comercial\Service\SementeDoExpansor;
use App\Comercial\UseCase\ConfirmarCampanhaUseCase;
use App\Tests\Comercial\Support\CriaCampanhas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O caminho concorrente da idempotência (spec §8.1.1): dois pedidos com a mesma chave passam juntos pela conferência;
 * o segundo INSERT cai no `ON CONFLICT DO NOTHING` (null) e o caso de uso devolve a campanha que o primeiro gravou, sem
 * trilha nova. O repositório é o único dublê (a corrida não se reproduz com uma conexão só); o resto é o do container.
 */
#[CoversClass(ConfirmarCampanhaUseCase::class)]
final class ConfirmarCampanhaConcorrenteTest extends KernelTestCase
{
    use CriaCampanhas;

    #[TestDox('ON CONFLICT devolve null: vale a campanha que o outro pedido gravou, e a trilha não é gravada de novo')]
    public function testCorridaDaMesmaChave(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $email = (string) $this->cadastradoAtivo()->getEmail();
        $chave = bin2hex(random_bytes(16));
        $existente = ['id' => 4242, 'total' => 1, 'estado' => 'na_fila', 'agendada_para' => null];

        $repositorio = $this->createMock(CampanhaDeEmailRepository::class);
        $repositorio->method('porChave')->with($chave)->willReturnOnConsecutiveCalls(null, $existente);
        $repositorio->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());
        $repositorio->expects(self::once())->method('inserir')->willReturn(null);
        $repositorio->expects(self::never())->method('auditar');

        $casoDeUso = new ConfirmarCampanhaUseCase(
            new ChaveDasCampanhas(true),
            $repositorio,
            $c->get(PublicosDaCampanha::class),
            $c->get(ComposicaoDaCampanha::class),
            $c->get(SementeDoExpansor::class),
            $c->get(MontadorDeEmail::class),
            $c->get(ClockInterface::class),
        );
        $pedido = PedidoDeCampanha::deArray([
            'chave' => $chave, 'modelo' => (array) (new ModelosDeCampanha())->fixo('convite'),
            'publico' => 'indiv', 'individual' => $email, 'frequencia' => 'unica', 'agendar' => '',
        ]);

        $saida = $casoDeUso->executar($pedido, $this->usuario('master'));

        self::assertSame(4242, $saida->id);
        self::assertStringStartsWith('Campanha enfileirada: 1 e-mail(s).', $saida->mensagem);
    }
}
