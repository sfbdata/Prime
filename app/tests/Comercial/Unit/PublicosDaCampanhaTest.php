<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\AtributosDosDestinatarios;
use App\Comercial\Campanha\PoliticaDeDestinatarios;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Repository\CadastradosRepository;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Profile\Repository\UserProfileRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Os públicos (spec §8.5): sem repetidos, sem os descadastrados da lista `comercial_campanhas` (consulta da 0d, escopo
 * plataforma), B1 restritivo só no CSV e no Individual, e os números da grade como o dc (os da fonte).
 */
#[CoversClass(PublicosDaCampanha::class)]
#[CoversClass(PoliticaDeDestinatarios::class)]
final class PublicosDaCampanhaTest extends TestCase
{
    /** @var list<string> */
    private array $descadastrados = [];

    /** @var list<array{0: ?object, 1: string, 2: string}> */
    private array $consultas = [];

    #[TestDox('Primários: repetidos e descadastrados saem; a consulta é da plataforma na lista comercial_campanhas; sem B1')]
    public function testPrimariosSemRepetidosNemDescadastrados(): void
    {
        $this->descadastrados = ['bia@exemplo.com'];
        $publicos = $this->publicos(primarios: [
            ['email' => 'ana@exemplo.com', 'nome' => 'Ana Lima'],
            ['email' => 'ANA@exemplo.com', 'nome' => 'Ana Outra'],
            ['email' => 'bia@exemplo.com', 'nome' => 'Bia'],
        ]);

        $resolvido = $publicos->resolver('prim');

        self::assertSame([['email' => 'ana@exemplo.com', 'nome' => 'Ana Lima']], array_map(static fn (DestinatarioDaCampanha $d): array => $d->paraArray(), $resolvido->destinatarios));
        self::assertSame([1, 0], [$resolvido->descadastrados, $resolvido->semAutorizacao]);
        self::assertSame([null, 'comercial_campanhas'], [$this->consultas[0][0], $this->consultas[0][2]]);
    }

    #[TestDox('Todos = primários + secundários; Base de contatos = os confirmados em comercial_campanhas')]
    public function testTodosELeads(): void
    {
        $publicos = $this->publicos(
            primarios: [['email' => 'ana@exemplo.com', 'nome' => 'Ana']],
            secundarios: [['email' => 'caio@exemplo.com', 'nome' => 'Caio']],
            confirmados: ['lead@exemplo.com'],
        );

        self::assertSame(['ana@exemplo.com', 'caio@exemplo.com'], self::emails($publicos->resolver('todos')->destinatarios));
        self::assertSame(['lead@exemplo.com'], self::emails($publicos->resolver('leads')->destinatarios));
        self::assertSame(['leads' => 1, 'prim' => 1, 'sec' => 1, 'todos' => 2, 'csv' => 0, 'indiv' => 0], $publicos->tamanhos('leads', []));
    }

    #[TestDox('B1 no Individual e no CSV: só usuário ativo ou confirmado; os outros contam em "sem autorização" e não voltam')]
    public function testB1(): void
    {
        $publicos = $this->publicos(ativos: ['ana@exemplo.com'], confirmados: ['lead@exemplo.com']);
        $informados = [new DestinatarioDaCampanha('ana@exemplo.com'), new DestinatarioDaCampanha('estranho@exemplo.com'), new DestinatarioDaCampanha('lead@exemplo.com', 'Lead')];

        $indiv = $publicos->resolver('indiv', $informados);
        $csv = $publicos->resolver('csv', $informados);

        self::assertSame(['ana@exemplo.com', 'lead@exemplo.com'], self::emails($indiv->destinatarios));
        self::assertSame(1, $indiv->semAutorizacao);
        self::assertSame(['ana@exemplo.com', 'lead@exemplo.com'], self::emails($csv->destinatarios));
        self::assertSame(['leads' => 1, 'prim' => 0, 'sec' => 0, 'todos' => 0, 'csv' => 3, 'indiv' => 0], $publicos->tamanhos('csv', $informados));
    }

    #[TestDox('A lista informada não alcança os primários: o CSV só traz o que veio nele')]
    public function testCsvNaoMisturaFontes(): void
    {
        $publicos = $this->publicos(primarios: [['email' => 'ana@exemplo.com', 'nome' => 'Ana']], ativos: ['ana@exemplo.com']);

        self::assertSame([], $publicos->resolver('csv', [])->destinatarios);
    }

    /**
     * @param list<array{email: string, nome: string}> $primarios
     * @param list<array{email: string, nome: string}> $secundarios
     * @param list<string>                             $confirmados
     * @param list<string>                             $ativos
     */
    private function publicos(array $primarios = [], array $secundarios = [], array $confirmados = [], array $ativos = []): PublicosDaCampanha
    {
        $cadastrados = $this->createStub(CadastradosRepository::class);
        $cadastrados->method('primarios')->willReturn($primarios);
        $cadastrados->method('secundarios')->willReturn($secundarios);
        $cadastrados->method('ativosEntre')->willReturnCallback(static fn (array $emails): array => array_values(array_intersect($emails, $ativos)));
        $confirmacoes = $this->createStub(ConfirmacaoDeEnderecoRepository::class);
        $confirmacoes->method('confirmadosDaLista')->willReturn($confirmados);
        $descadastro = $this->createStub(DescadastroDeEmailInterface::class);
        $descadastro->method('estaDescadastrado')->willReturnCallback(function (?object $escopo, string $email, string $lista): bool {
            $this->consultas[] = [$escopo, $email, $lista];

            return \in_array($email, $this->descadastrados, true);
        });

        // Sem filtro, os atributos nunca são lidos: o SituacaoDoEspaco (final) entra sem construtor.
        $atributos = new AtributosDosDestinatarios(
            $cadastrados,
            $this->createStub(UserProfileRepository::class),
            (new \ReflectionClass(SituacaoDoEspaco::class))->newInstanceWithoutConstructor(),
        );

        return new PublicosDaCampanha($cadastrados, $confirmacoes, $descadastro, new PoliticaDeDestinatarios(), $atributos, new MockClock());
    }

    /**
     * @param list<DestinatarioDaCampanha> $lista
     *
     * @return list<string>
     */
    private static function emails(array $lista): array
    {
        return array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $lista);
    }
}
