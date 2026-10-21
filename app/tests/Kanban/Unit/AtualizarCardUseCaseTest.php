<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\AtualizarCardInput;
use App\Kanban\DTO\CardDetalheOutput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Service\SanitizadorTextoKanban;
use App\Kanban\UseCase\AtualizarCardUseCase;
use App\Repository\UserRepository;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

#[CoversClass(AtualizarCardUseCase::class)]
#[CoversClass(CardDetalheOutput::class)]
final class AtualizarCardUseCaseTest extends TestCase
{
    #[TestDox('editar o cartão mantém a responsável suspensa que já estava e não aceita uma suspensa nova')]
    public function testPreservaResponsavelSuspensa(): void
    {
        $tenant = new Tenant();
        [$criador, $ana, $carla] = [$this->usuario(1, 'Criador'), $this->usuario(2, 'Ana'), $this->usuario(3, 'Carla')];
        $board = new KanbanBoard('Mural', $tenant, $criador);
        $card  = new KanbanCard('Cartão', new KanbanColuna('A fazer', KanbanColuna::TIPO_A_FAZER, 0, $board), $board, $criador);
        (new \ReflectionProperty(KanbanCard::class, 'id'))->setValue($card, 10);
        $card->adicionarResponsavel($carla);

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findColaboradorAtivoPorIdETenant')->willReturnCallback(static fn (int $id): ?User => $id === 2 ? $ana : null);
        $html = $this->createStub(HtmlSanitizerInterface::class);
        $html->method('sanitize')->willReturnArgument(0);

        $sut = new AtualizarCardUseCase($this->createStub(KanbanCardRepository::class), new ColaboradoresAtribuiveis($repo), new SanitizadorTextoKanban($html));
        $sut->executar($card, new AtualizarCardInput(titulo: 'Cartão', responsaveisIds: [2, 3, 4]), $criador, $tenant);

        $ids = array_map(static fn (User $u): ?int => $u->getId(), $card->getResponsaveis()->toArray());
        sort($ids);
        self::assertSame([2, 3], $ids);
    }

    #[TestDox('o modal do cartão oferece só quem pode ser responsável')]
    public function testModalFiltraOsDisponiveis(): void
    {
        $tenant = new Tenant();
        [$criador, $ana, $bruna] = [$this->usuario(1, 'Criador'), $this->usuario(2, 'Ana'), $this->usuario(4, 'Bruna')];
        $board = new KanbanBoard('Mural', $tenant, $criador);
        $board->adicionarParticipante($ana);
        $board->adicionarParticipante($bruna);
        $card = new KanbanCard('Cartão', new KanbanColuna('A fazer', KanbanColuna::TIPO_A_FAZER, 0, $board), $board, $criador);
        (new \ReflectionProperty(KanbanCard::class, 'id'))->setValue($card, 10);

        $saida = CardDetalheOutput::fromEntity($card, $criador, [], [1, 2]);

        self::assertSame(['Criador', 'Ana'], array_map(static fn ($p): string => $p->nome, $saida->participantesDisponiveis));
    }

    private function usuario(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        $u->setEmail(strtolower($nome) . '@teste.local');
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
