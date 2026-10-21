<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Repository\PerguntaDaConversaRepository;
use App\Inteligencia\UseCase\ListarConversaDaPastaUseCase;
use App\Pasta\Entity\Pasta;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conversa da aba Perguntar (F3) sem banco: pede ao repositório a conversa DA PESSOA na pasta, com o
 * limite, e esconde a pergunta cuja resposta leu uma aba que hoje está oculta para quem lê.
 */
#[CoversClass(ListarConversaDaPastaUseCase::class)]
final class ListarConversaDaPastaUseCaseTest extends TestCase
{
    /** @param list<string> $abasOcultasNaSolicitacao */
    private static function pergunta(Tenant $tenant, User $autor, int $id, string $texto, array $abasOcultasNaSolicitacao): PerguntaDaConversa
    {
        $analise = new AnaliseDeInteligencia(
            $tenant,
            $autor,
            TipoDeAnalise::PerguntaLivre,
            AnaliseDeInteligencia::ALVO_PASTA,
            7,
            'pergunta-v1',
            'h',
            ['financeiro' => false, 'abasOcultas' => $abasOcultasNaSolicitacao],
            Agente::Gestor,
        );
        DefineId::em($analise, $id * 10);
        $pergunta = new PerguntaDaConversa($tenant, 7, $autor, $texto, $analise);
        DefineId::em($pergunta, $id);

        return $pergunta;
    }

    #[TestDox('pede a conversa da pessoa com o limite e esconde a pergunta cuja resposta leu uma aba hoje oculta')]
    public function testEscondeAPerguntaQueLeuAbaOculta(): void
    {
        $tenant = new Tenant();
        $user = new User();
        $pasta = new Pasta();
        DefineId::em($pasta, 7);
        $leuOPush = self::pergunta($tenant, $user, 1, 'Saiu publicação?', []);
        $semOPush = self::pergunta($tenant, $user, 2, 'E as metas?', ['push']);
        $repositorio = $this->createMock(PerguntaDaConversaRepository::class);
        $repositorio->expects($this->once())->method('ultimasDaConversa')
            ->with($tenant, 7, $user, ListarConversaDaPastaUseCase::LIMITE)
            ->willReturn([$leuOPush, $semOPush]);

        $conversa = (new ListarConversaDaPastaUseCase($repositorio))->executar($pasta, $user, $tenant, ['push']);

        self::assertSame(['E as metas?'], array_map(static fn ($m): string => $m->texto, $conversa->mensagens));
    }

    #[TestDox('sem aba oculta para quem lê, todas aparecem, na ordem do repositório')]
    public function testSemAbaOculta(): void
    {
        $tenant = new Tenant();
        $user = new User();
        $pasta = new Pasta();
        DefineId::em($pasta, 7);
        $repositorio = $this->createStub(PerguntaDaConversaRepository::class);
        $repositorio->method('ultimasDaConversa')->willReturn([
            self::pergunta($tenant, $user, 1, 'Primeira', []),
            self::pergunta($tenant, $user, 2, 'Segunda', ['push']),
        ]);

        $conversa = (new ListarConversaDaPastaUseCase($repositorio))->executar($pasta, $user, $tenant);

        self::assertSame(['Primeira', 'Segunda'], array_map(static fn ($m): string => $m->texto, $conversa->mensagens));
        self::assertTrue($conversa->temEmAndamento());
    }
}
