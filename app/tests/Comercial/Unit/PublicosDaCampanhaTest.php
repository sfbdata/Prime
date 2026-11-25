<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\AtributosDosDestinatarios;
use App\Comercial\Campanha\IntervaloDosConvites;
use App\Comercial\Campanha\PoliticaDeDestinatarios;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Repository\CadastradosRepository;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Master\Repository\ConfiguracaoGlobalRepository;
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
#[CoversClass(IntervaloDosConvites::class)]
final class PublicosDaCampanhaTest extends TestCase
{
    /** @var list<string> */
    private array $descadastrados = [];

    /** @var array<string, list<string>> os descadastrados de uma lista só (lista => e-mails) */
    private array $descadastradosDaLista = [];

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
        self::assertSame(['leads' => 1, 'contatos' => 0, 'prim' => 1, 'sec' => 1, 'todos' => 2, 'csv' => 0, 'indiv' => 0], $publicos->tamanhos('leads', []));
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
        self::assertSame(['leads' => 1, 'contatos' => 0, 'prim' => 0, 'sec' => 0, 'todos' => 0, 'csv' => 3, 'indiv' => 0], $publicos->tamanhos('csv', $informados));
    }

    #[TestDox('A lista informada não alcança os primários: o CSV só traz o que veio nele')]
    public function testCsvNaoMisturaFontes(): void
    {
        $publicos = $this->publicos(primarios: [['email' => 'ana@exemplo.com', 'nome' => 'Ana']], ativos: ['ana@exemplo.com']);

        self::assertSame([], $publicos->resolver('csv', [])->destinatarios);
    }

    #[TestDox('Modelo vagas (§11): Base de contatos = comercial_campanhas e depois vagas_alerta, cada um com a sua lista')]
    public function testVagasUneAsDuasListas(): void
    {
        $publicos = $this->publicos(confirmados: ['lead@exemplo.com', 'dupla@exemplo.com'], vagas: ['dupla@exemplo.com', 'vaga@exemplo.com']);

        $resolvido = $publicos->resolver('leads', modelo: PublicosDaCampanha::MODELO_DE_VAGAS);

        self::assertSame(
            [['lead@exemplo.com', 'comercial_campanhas'], ['dupla@exemplo.com', 'comercial_campanhas'], ['vaga@exemplo.com', 'vagas_alerta']],
            array_map(static fn (DestinatarioDaCampanha $d): array => [$d->email, $d->lista], $resolvido->destinatarios),
            'quem está nas duas entra uma vez, pela comercial_campanhas',
        );
        self::assertSame(1, $resolvido->repetidos);
        self::assertSame(3, $publicos->tamanhos('leads', [], 'vagas')['leads']);
    }

    #[TestDox('Modelo vagas: quem saiu de qualquer uma das duas listas fica de fora')]
    public function testVagasDescadastradoDeQualquerLista(): void
    {
        $this->descadastradosDaLista = ['vagas_alerta' => ['lead@exemplo.com'], 'comercial_campanhas' => ['vaga@exemplo.com']];
        $publicos = $this->publicos(confirmados: ['lead@exemplo.com', 'fica@exemplo.com'], vagas: ['vaga@exemplo.com', 'outra@exemplo.com']);

        $resolvido = $publicos->resolver('leads', modelo: 'vagas');

        self::assertSame(['fica@exemplo.com', 'outra@exemplo.com'], self::emails($resolvido->destinatarios));
        self::assertSame(2, $resolvido->descadastrados);
    }

    #[TestDox('Outro modelo e as cópias cu<id> não trazem vagas_alerta; o descadastro só de vagas não pesa')]
    public function testOutroModeloNaoTrazVagas(): void
    {
        $this->descadastradosDaLista = ['vagas_alerta' => ['lead@exemplo.com']];
        $publicos = $this->publicos(confirmados: ['lead@exemplo.com'], vagas: ['vaga@exemplo.com']);

        foreach (['', 'convite', 'cu12', 'news'] as $modelo) {
            $resolvido = $publicos->resolver('leads', modelo: $modelo);
            self::assertSame(['lead@exemplo.com'], self::emails($resolvido->destinatarios), $modelo);
            self::assertSame(['comercial_campanhas'], array_map(static fn (DestinatarioDaCampanha $d): string => $d->lista, $resolvido->destinatarios));
            self::assertSame(1, $publicos->tamanhos('leads', [], $modelo)['leads'], $modelo);
        }
        self::assertNotContains('vagas_alerta', array_column($this->consultas, 2));
    }

    #[TestDox('Modelo vagas nos outros públicos: a fonte não muda, a lista é comercial_campanhas')]
    public function testVagasNosCadastrados(): void
    {
        $publicos = $this->publicos(primarios: [['email' => 'ana@exemplo.com', 'nome' => 'Ana']], vagas: ['vaga@exemplo.com']);

        $resolvido = $publicos->resolver('prim', modelo: 'vagas');

        self::assertSame([['ana@exemplo.com', 'comercial_campanhas']], array_map(static fn (DestinatarioDaCampanha $d): array => [$d->email, $d->lista], $resolvido->destinatarios));
    }

    #[TestDox('Contatos salvos: sem o B1 (convite profissional liberado), mas descadastrado sai; CSV e Individual seguem barrados')]
    public function testContatosSalvosForaDoB1(): void
    {
        $this->descadastrados = ['saiu@exemplo.com'];
        $publicos = $this->publicos(contatos: [
            ['email' => 'estranho@exemplo.com', 'nome' => 'Estranho', 'ultimoConviteEm' => null],
            ['email' => 'saiu@exemplo.com', 'nome' => 'Saiu', 'ultimoConviteEm' => null],
        ]);
        $informados = [new DestinatarioDaCampanha('estranho@exemplo.com')];

        $contatos = $publicos->resolver('contatos');

        self::assertSame(['estranho@exemplo.com'], self::emails($contatos->destinatarios), 'quem nunca se cadastrou nem confirmou recebe');
        self::assertSame([1, 0, 0], [$contatos->descadastrados, $contatos->semAutorizacao, $contatos->puladosPeloIntervalo]);
        self::assertSame(['comercial_campanhas'], array_map(static fn (DestinatarioDaCampanha $d): string => $d->lista, $contatos->destinatarios), 'a lista do descadastro e do link');
        self::assertSame([], $publicos->resolver('csv', $informados)->destinatarios, 'o CSV avulso continua com o B1');
        self::assertSame([], $publicos->resolver('indiv', $informados)->destinatarios, 'o Individual continua com o B1');
        self::assertSame(1, $publicos->resolver('indiv', $informados)->semAutorizacao);
        self::assertSame(2, $publicos->tamanhos('contatos', [])['contatos'], 'a grade conta a base inteira');
    }

    #[TestDox('Intervalo de 1 dia: convidado hoje sai, ontem entra (nunca o mesmo endereço duas vezes no mesmo dia)')]
    public function testIntervaloDeUmDia(): void
    {
        $publicos = $this->publicos(intervalo: 1, contatos: [
            ['email' => 'hoje@exemplo.com', 'nome' => '', 'ultimoConviteEm' => new \DateTimeImmutable('2026-11-25 00:00:00')],
            ['email' => 'ontem@exemplo.com', 'nome' => '', 'ultimoConviteEm' => new \DateTimeImmutable('2026-11-24 23:59:59')],
            ['email' => 'nunca@exemplo.com', 'nome' => '', 'ultimoConviteEm' => null],
        ]);

        $resolvido = $publicos->resolver('contatos', hoje: new \DateTimeImmutable('2026-11-25 15:30:00'));

        self::assertSame(['ontem@exemplo.com', 'nunca@exemplo.com'], self::emails($resolvido->destinatarios));
        self::assertSame(1, $resolvido->puladosPeloIntervalo);
    }

    #[TestDox('Intervalo de N dias: convidado há N-1 dias ainda sai; há N dias volta (conta por dia do calendário)')]
    public function testIntervaloDeNDias(): void
    {
        $publicos = $this->publicos(intervalo: 7, contatos: [
            ['email' => 'seis@exemplo.com', 'nome' => '', 'ultimoConviteEm' => new \DateTimeImmutable('2026-11-19 00:00:00')],
            ['email' => 'sete@exemplo.com', 'nome' => '', 'ultimoConviteEm' => new \DateTimeImmutable('2026-11-18 23:59:59')],
            ['email' => 'saiu@exemplo.com', 'nome' => '', 'ultimoConviteEm' => new \DateTimeImmutable('2026-11-24 10:00:00')],
        ]);
        $this->descadastrados = ['saiu@exemplo.com'];

        $resolvido = $publicos->resolver('contatos', hoje: new \DateTimeImmutable('2026-11-25 08:00:00'));

        self::assertSame(['sete@exemplo.com'], self::emails($resolvido->destinatarios));
        self::assertSame(1, $resolvido->puladosPeloIntervalo);
        self::assertSame(1, $resolvido->descadastrados, 'o descadastrado conta como descadastrado, não como pulado');
    }

    #[TestDox('O intervalo vem do painel quando válido; fora da faixa ou sem valor, vale o padrão de 7 dias')]
    public function testIntervaloPadraoEFaixa(): void
    {
        foreach ([[null, 7, 'padrao'], [30, 30, 'painel'], [0, 7, 'padrao'], [366, 7, 'padrao'], [365, 365, 'painel']] as [$gravado, $dias, $origem]) {
            $configuracoes = $this->createStub(ConfiguracaoGlobalRepository::class);
            $configuracoes->method('intervaloDeConviteDias')->willReturn($gravado);
            $intervalo = new IntervaloDosConvites($configuracoes);

            self::assertSame([$dias, $origem], [$intervalo->dias(), $intervalo->origem()], (string) $gravado);
        }
        self::assertSame(7, (new IntervaloDosConvites())->dias());
        self::assertTrue((new PoliticaDeDestinatarios())->dispensarAutorizacaoNosContatosSalvos());
    }

    /**
     * @param list<array{email: string, nome: string}> $primarios
     * @param list<array{email: string, nome: string}> $secundarios
     * @param list<string>                             $confirmados
     * @param list<string>                             $ativos
     * @param list<string>                             $vagas       os confirmados de `vagas_alerta`
     * @param list<array{email: string, nome: string, ultimoConviteEm: ?\DateTimeImmutable}> $contatos os contatos salvos
     */
    private function publicos(array $primarios = [], array $secundarios = [], array $confirmados = [], array $ativos = [], array $vagas = [], array $contatos = [], int $intervalo = 7): PublicosDaCampanha
    {
        $repositorioDeContatos = $this->createStub(ContatoComercialRepository::class);
        $repositorioDeContatos->method('paraConvite')->willReturn($contatos);
        $repositorioDeContatos->method('contar')->willReturn(\count($contatos));
        $configuracoes = $this->createStub(ConfiguracaoGlobalRepository::class);
        $configuracoes->method('intervaloDeConviteDias')->willReturn($intervalo);
        $cadastrados = $this->createStub(CadastradosRepository::class);
        $cadastrados->method('primarios')->willReturn($primarios);
        $cadastrados->method('secundarios')->willReturn($secundarios);
        $cadastrados->method('ativosEntre')->willReturnCallback(static fn (array $emails): array => array_values(array_intersect($emails, $ativos)));
        $confirmacoes = $this->createStub(ConfirmacaoDeEnderecoRepository::class);
        $confirmacoes->method('confirmadosDaLista')->willReturnCallback(static fn (string $lista): array => $lista === PublicosDaCampanha::LISTA_DE_VAGAS ? $vagas : $confirmados);
        $descadastro = $this->createStub(DescadastroDeEmailInterface::class);
        $descadastro->method('estaDescadastrado')->willReturnCallback(function (?object $escopo, string $email, string $lista): bool {
            $this->consultas[] = [$escopo, $email, $lista];

            return \in_array($email, $this->descadastrados, true) || \in_array($email, $this->descadastradosDaLista[$lista] ?? [], true);
        });

        // Sem filtro, os atributos nunca são lidos: o SituacaoDoEspaco (final) entra sem construtor.
        $atributos = new AtributosDosDestinatarios(
            $cadastrados,
            $this->createStub(UserProfileRepository::class),
            (new \ReflectionClass(SituacaoDoEspaco::class))->newInstanceWithoutConstructor(),
        );

        return new PublicosDaCampanha($cadastrados, $confirmacoes, $descadastro, new PoliticaDeDestinatarios(), $atributos, new MockClock(), $repositorioDeContatos, new IntervaloDosConvites($configuracoes));
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
