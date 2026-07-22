<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarAnotacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AnotacaoNaoEditavelException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\EventoNaoEncontradoException;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\UseCase\EditarAnotacaoUseCase;
use App\Cobranca\UseCase\ExcluirAnotacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EditarAnotacaoUseCase::class)]
#[CoversClass(ExcluirAnotacaoUseCase::class)]
final class EditarExcluirAnotacaoUseCaseTest extends TestCase
{
    private const REGISTRO = '2026-07-22 10:00:00';

    private EventoHistoricoRepository&MockObject $eventoRepository;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->tenant = new Tenant();
        $this->autor = new User();
        // Sem id real (entidade nova), `podeSerEditadaPor` compara getId() — ambos null seriam iguais.
        // Damos identidade explícita para o teste de "outro autor" ter valor.
        $this->definirId($this->autor, 7);
    }

    #[Test]
    #[TestDox('Autor corrige o texto dentro da janela e a anotação passa a exibir "(editado)"')]
    public function autorEditaDentroDaJanela(): void
    {
        $evento = $this->anotacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->once())->method('salvar')->with($evento, true);

        $sut = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-23 09:00:00'));

        $input = new EditarAnotacaoInput();
        $input->eventoId = 5;
        $input->texto = '  Sindico confirmou a venda em 2023, nao 2024.  ';

        $sut->executar($input, $this->tenant, $this->autor);

        self::assertSame('Sindico confirmou a venda em 2023, nao 2024.', $evento->getDescricao());
        self::assertNotNull($evento->getEditadoEm(), 'a edição precisa deixar marca — quem lê tem de saber');
    }

    #[Test]
    #[TestDox('Editar não renova a janela: ela continua contando do registro original')]
    public function edicaoNaoRenovaAJanela(): void
    {
        $evento = $this->anotacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);

        // Primeira edição às 47h — dentro.
        $sut = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-24 09:00:00'));
        $input = new EditarAnotacaoInput();
        $input->eventoId = 5;
        $input->texto = 'primeira correcao';
        $sut->executar($input, $this->tenant, $this->autor);

        // Segunda tentativa às 49h da ORIGEM: se a edição tivesse renovado o prazo, passaria.
        $sut2 = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-24 11:00:00'));
        $input2 = new EditarAnotacaoInput();
        $input2->eventoId = 5;
        $input2->texto = 'segunda correcao';

        $this->expectException(AnotacaoNaoEditavelException::class);
        $sut2->executar($input2, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Passadas as 48h a anotação fica firme')]
    public function foraDaJanelaNaoEdita(): void
    {
        $evento = $this->anotacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $sut = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-24 10:00:01'));

        $input = new EditarAnotacaoInput();
        $input->eventoId = 5;
        $input->texto = 'tarde demais';

        $this->expectException(AnotacaoNaoEditavelException::class);
        $sut->executar($input, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Exatamente 48h ainda é dentro (limite fechado)')]
    public function limiteExatoAindaEdita(): void
    {
        $evento = $this->anotacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $sut = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-24 10:00:00'));

        $input = new EditarAnotacaoInput();
        $input->eventoId = 5;
        $input->texto = 'no limite';

        $sut->executar($input, $this->tenant, $this->autor);

        self::assertSame('no limite', $evento->getDescricao());
    }

    #[Test]
    #[TestDox('Anotação de outra pessoa não pode ser mexida, mesmo dentro da janela')]
    public function outroUsuarioNaoEdita(): void
    {
        $evento = $this->anotacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $outro = new User();
        $this->definirId($outro, 99);

        $sut = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-22 11:00:00'));

        $input = new EditarAnotacaoInput();
        $input->eventoId = 5;
        $input->texto = 'mexendo no alheio';

        $this->expectException(AnotacaoNaoEditavelException::class);
        $sut->executar($input, $this->tenant, $outro);
    }

    #[Test]
    #[TestDox('Evento automático (contato, acordo, pagamento) NUNCA é editável')]
    public function eventoAutomaticoNaoEditavel(): void
    {
        $evento = $this->anotacao(TipoEventoHistorico::ContatoRealizado);
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $sut = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-22 11:00:00'));

        $input = new EditarAnotacaoInput();
        $input->eventoId = 5;
        $input->texto = 'reescrevendo um contato';

        $this->expectException(AnotacaoNaoEditavelException::class);
        $sut->executar($input, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Evento de outro escritório não existe para quem pede')]
    public function eventoDeOutroTenant(): void
    {
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $sut = new EditarAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-22 11:00:00'));

        $input = new EditarAnotacaoInput();
        $input->eventoId = 999;
        $input->texto = 'cross-tenant';

        $this->expectException(EventoNaoEncontradoException::class);
        $sut->executar($input, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Caso encerrado não aceita nem edição nem exclusão')]
    public function casoEncerradoBloqueia(): void
    {
        $evento = $this->anotacao();
        $evento->getCaso()?->setStatus(StatusCaso::Encerrado);
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('remover');

        $sut = new ExcluirAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-22 11:00:00'));

        $this->expectException(CasoEncerradoException::class);
        $sut->executar(5, $this->tenant, $this->autor);
    }

    #[Test]
    #[TestDox('Autor apaga a própria anotação dentro da janela e recebe o caso de volta')]
    public function autorExcluiDentroDaJanela(): void
    {
        $evento = $this->anotacao();
        $this->definirId($evento->getCaso(), 42);
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->once())->method('remover')->with($evento, true);

        $sut = new ExcluirAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-23 09:00:00'));

        self::assertSame(42, $sut->executar(5, $this->tenant, $this->autor));
    }

    #[Test]
    #[TestDox('Fora da janela, a exclusão é recusada')]
    public function foraDaJanelaNaoExclui(): void
    {
        $evento = $this->anotacao();
        $this->eventoRepository->method('findOneByIdDoTenant')->willReturn($evento);
        $this->eventoRepository->expects($this->never())->method('remover');

        $sut = new ExcluirAnotacaoUseCase($this->eventoRepository, $this->relogio('2026-07-25 10:00:00'));

        $this->expectException(AnotacaoNaoEditavelException::class);
        $sut->executar(5, $this->tenant, $this->autor);
    }

    private function anotacao(TipoEventoHistorico $tipo = TipoEventoHistorico::Anotacao): EventoHistorico
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $evento = new EventoHistorico();
        $evento->setTenant($this->tenant)
            ->setCaso($caso)
            ->setTipo($tipo)
            ->setUsuario($this->autor)
            ->setDescricao('texto original')
            ->setOcorridoEm(new \DateTimeImmutable(self::REGISTRO));

        return $evento;
    }

    private function relogio(string $agora): ClockInterface
    {
        return new MockClock(new \DateTimeImmutable($agora));
    }

    /** Entidades novas não têm id; o teste precisa de identidade para comparar autoria. */
    private function definirId(object $entidade, int $id): void
    {
        $ref = new \ReflectionProperty($entidade, 'id');
        $ref->setValue($entidade, $id);
    }
}
