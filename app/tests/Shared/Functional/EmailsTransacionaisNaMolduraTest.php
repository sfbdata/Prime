<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Os e-mails transacionais (senha, cadastro, convites, acesso novo, Push, Trabalho Externo, Voto, Assinatura) usam
 * a moldura comum `email/_moldura.html.twig` (M5.4): uma tabela de moldura, um cabeçalho e nada do visual antigo.
 */
#[CoversNothing]
final class EmailsTransacionaisNaMolduraTest extends KernelTestCase
{
    /** @return array<string, mixed> */
    private static function contexto(): array
    {
        $convite = new class () {
            public ?string $fullName = 'Ana Souza';
            public ?object $tenant = null;
            public ?object $createdBy = null;
            public \DateTimeImmutable $expiresAt;

            public function __construct()
            {
                $this->expiresAt = new \DateTimeImmutable('2030-01-01 10:00');
            }
        };
        $conviteExterno = new class () {
            public string $nome = 'Ana Souza';
            public ?string $funcao = 'Correspondente';
            public \DateTimeImmutable $validade;

            public function __construct()
            {
                $this->validade = new \DateTimeImmutable('2030-01-01');
            }
        };
        $cadastro = new class () {
            public string $nomeCompleto = 'Ana Souza';
            public string $nomeEscritorio = 'Silva Advogados';
        };
        $aviso = new class () {
            public string $mensagemDoEmail = 'Chegou movimentação';
            public array $movimentacoesDoEmail = [];
            public int $restantesForaDoEmail = 0;
        };
        $comprovante = new class () {
            public string $assembleia = 'Assembleia geral';
            public string $pauta = 'Pauta 1';
            public string $identificacao = 'Unidade';
            public string $unidade = '101';
            public \DateTimeImmutable $registradaEm;
            public string $codigo = 'ABC123';

            public function __construct()
            {
                $this->registradaEm = new \DateTimeImmutable('2030-01-01 10:00:00');
            }
        };

        return [
            'nome' => 'Ana Souza',
            'quando' => new \DateTimeImmutable('2030-01-01 10:00'),
            'navegador' => 'Firefox',
            'sistema' => 'Linux',
            'link' => 'https://app.bluejus.test/x/abc',
            'linkDaPasta' => 'https://app.bluejus.test/pasta/1',
            'linkDescadastro' => 'https://app.bluejus.test/descadastro/abc',
            'codigo' => '123456',
            'escritorio' => 'Silva Advogados',
            'titulo' => 'Aviso do escritório',
            'corpoHtml' => '<p>Corpo da mensagem</p>',
            'invitation' => $convite,
            'convite' => $conviteExterno,
            'servico' => 'Diligência',
            'local' => 'Fórum central',
            'cadastro' => $cadastro,
            'aviso' => $aviso,
            'comprovante' => $comprovante,
            'assembleia' => 'Assembleia geral',
            'unidade' => '101',
            'responsavel' => 'Ana Souza',
            'documento' => 'Contrato',
            'mensagem' => 'Leia e assine.',
            'motivo' => 'Valor',
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach ([
            'acesso_novo', 'cadastro_confirmacao', 'codigo_exclusao_conta', 'codigo_senha', 'convite_escritorio',
            'convite_plataforma', 'push_aviso_equipe', 'push_cliente', 'redefinicao_senha', 'teto_codigo_senha',
            'trabalho_externo_convite', 'voto_assembleia_codigo', 'voto_assembleia_link', 'voto_comprovante',
            'assinatura/convite',
        ] as $nome) {
            yield $nome => ['email/' . $nome . '.html.twig'];
        }
    }

    private static function renderizar(string $template, array $contexto): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->render($template, $contexto);
    }

    #[DataProvider('templates')]
    #[TestDox('$template: uma moldura, um cabeçalho e nada do visual antigo')]
    public function testNaMoldura(string $template): void
    {
        $html = self::renderizar($template, self::contexto());
        $dom = new Crawler($html);

        self::assertCount(1, $dom->filter('table[data-moldura]'));
        self::assertCount(1, $dom->filter('td[data-cabecalho]'));
        self::assertStringNotContainsString('#1a56db', $html);
        self::assertStringNotContainsString('background-color:#f4f4f4', $html);
    }

    #[TestDox('push_cliente com escritório: o cabeçalho é o nome dele, sem logo')]
    public function testPushClienteComEscritorio(): void
    {
        $dom = new Crawler(self::renderizar('email/push_cliente.html.twig', self::contexto()));

        self::assertSame('Silva Advogados', trim($dom->filter('[data-escritorio]')->text()));
        self::assertCount(0, $dom->filter('img'));
    }

    #[TestDox('push_cliente sem escritório: o cabeçalho é o logo do BlueJus, com endereço absoluto')]
    public function testPushClienteSemEscritorio(): void
    {
        $dom = new Crawler(self::renderizar('email/push_cliente.html.twig', ['escritorio' => '', 'titulo' => null, 'corpoHtml' => '<p>x</p>', 'linkDescadastro' => 'https://app.bluejus.test/d']));

        self::assertCount(0, $dom->filter('[data-escritorio]'));
        $src = (string) $dom->filter('img[alt="BlueJus"]')->attr('src');
        self::assertStringStartsWith('http', $src);
        self::assertStringEndsWith('/images/logo/bluejus-logo-email.png', $src);
    }

    #[TestDox('Faixa do topo: azul por padrão; vermelho de alerta no código de exclusão da conta')]
    public function testCorDaFaixa(): void
    {
        $azul = new Crawler(self::renderizar('email/codigo_senha.html.twig', self::contexto()));
        $vermelho = new Crawler(self::renderizar('email/codigo_exclusao_conta.html.twig', self::contexto()));

        self::assertSame('#0a6fa0', $azul->filter('td[data-faixa]')->attr('bgcolor'));
        self::assertSame('#a3232b', $vermelho->filter('td[data-faixa]')->attr('bgcolor'));
    }
}
