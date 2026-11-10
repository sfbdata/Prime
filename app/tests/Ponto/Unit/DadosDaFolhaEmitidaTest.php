<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os dados que o HMAC da folha emitida sela (E16, decisões 2, 3 e 17): código, competência, pessoa, escritório e CNPJ,
 * emitida em e por quem, a impressão dos registros, o resumo (como quem emite montou) e o aceite impresso. A forma
 * canônica é estável (ida e volta iguais) e o leitor recusa o que não é o formato 1. Nunca CPF nem e-mail.
 */
#[Group('ponto')]
#[CoversClass(DadosDaFolhaEmitida::class)]
final class DadosDaFolhaEmitidaTest extends TestCase
{
    private const CODIGO = 'BJF-ABCD-EFGH-JK23';

    private Tenant $escritorio;
    private User $pessoa;
    private User $gestor;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->escritorio->setName('Escritório Modelo');
        $this->escritorio->setCnpj('12345678000190');
        $this->pessoa = $this->usuario(11, 'Maria da Silva Pereira', 'maria@teste.com');
        $this->gestor = $this->usuario(12, 'Gestor Souza', 'gestor@teste.com');
    }

    #[TestDox('Da emissão: a forma canônica com as chaves em ordem, o resumo como veio e o aceite nulo; sem CPF nem e-mail')]
    public function testCanonicoDaEmissao(): void
    {
        $canonico = $this->dados()->canonico();

        self::assertSame(
            '{"formato":1,"codigo":"BJF-ABCD-EFGH-JK23","competencia":"2026-10","pessoa_id":11,"pessoa":"Maria da Silva Pereira",'
            . '"escritorio":"Escritório Modelo","cnpj":"12345678000190","emitida_em":"2026-11-03T09:00:00-03:00",'
            . '"emitida_por_id":12,"emitida_por":"Gestor Souza","impressao_registros":"' . $this->impressao() . '",'
            . '"resumo":{"dias":[{"data":"2026-10-01","batidas":["08:00","12:00"],"trabalhado":480,"incompleto":false}],'
            . '"totais":{"trabalhado":480,"extra":0,"saldo":-15}},"aceite":null}',
            $canonico,
        );
        self::assertStringNotContainsStringIgnoringCase('cpf', $canonico);
        self::assertStringNotContainsString('@teste.com', $canonico, 'nunca o e-mail');
    }

    #[TestDox('Ida e volta iguais, com e sem aceite impresso; o texto da validação é o do desenho (L803)')]
    public function testIdaEVolta(): void
    {
        $semAceite = $this->dados();
        $comAceite = $this->dados($this->aceite(SituacaoDoAceiteDaFolha::Ressalva, 'Faltou a batida do dia 3.'));

        foreach ([$semAceite, $comAceite] as $dados) {
            $volta = DadosDaFolhaEmitida::deCanonico($dados->canonico());
            self::assertSame($dados->canonico(), $volta->canonico());
        }
        $volta = DadosDaFolhaEmitida::deCanonico($comAceite->canonico());
        self::assertSame(SituacaoDoAceiteDaFolha::Ressalva, $volta->aceiteSituacao);
        self::assertSame('Faltou a batida do dia 3.', $volta->aceiteRessalva);
        self::assertSame('2026-11-02T18:30:00-03:00', $volta->aceiteEm?->format(\DATE_ATOM));
        self::assertSame(['dias', 'totais'], array_keys($volta->resumo));
        self::assertSame('10/2026', $volta->competenciaFormatada());
        self::assertSame('Folha de Ponto 10/2026 de Maria da Silva Pereira em 03/11/2026, 09:00:00.', $volta->textoDaValidacao());
        self::assertStringNotContainsString('Faltou', $volta->textoDaValidacao(), 'a ressalva fica fora da página aberta');
    }

    #[TestDox('Aceite impresso "concordo": situação e data, ressalva nula')]
    public function testAceiteConcordo(): void
    {
        $canonico = $this->dados($this->aceite(SituacaoDoAceiteDaFolha::Concordo, null))->canonico();

        self::assertStringEndsWith('"aceite":{"situacao":"concordo","em":"2026-11-02T18:30:00-03:00","ressalva":null}}', $canonico);
    }

    #[TestDox('Pessoa e emissor sem nome: vazio, nunca o e-mail; sem emissor (usuário que saiu), id e nome nulos')]
    public function testSemNomeESemEmissor(): void
    {
        $semNome = $this->usuario(13, null, 'semnome@teste.com');
        $dados = DadosDaFolhaEmitida::daEmissao(self::CODIGO, $this->escritorio, $semNome, 2026, 10, $this->emitidaEm(), null, $this->impressao(), []);

        self::assertSame('', $dados->pessoa);
        self::assertNull($dados->emitidaPorId);
        self::assertNull($dados->emitidaPor);
        self::assertStringNotContainsString('semnome@teste.com', $dados->canonico());
        self::assertStringContainsString('"emitida_por_id":null,"emitida_por":null', $dados->canonico());
        self::assertSame($dados->canonico(), DadosDaFolhaEmitida::deCanonico($dados->canonico())->canonico());
    }

    #[TestDox('Aceite de outra pessoa ou de outra competência não entra na folha; pessoa sem id não emite')]
    public function testAceiteDeOutraFolhaRecusado(): void
    {
        $outraPessoa = $this->usuario(14, 'Outra Pessoa', 'outra@teste.com');
        $casos = [
            'outra pessoa'      => fn () => DadosDaFolhaEmitida::daEmissao(self::CODIGO, $this->escritorio, $outraPessoa, 2026, 10, $this->emitidaEm(), null, $this->impressao(), [], $this->aceite(SituacaoDoAceiteDaFolha::Concordo, null)),
            'outro mês'         => fn () => DadosDaFolhaEmitida::daEmissao(self::CODIGO, $this->escritorio, $this->pessoa, 2026, 9, $this->emitidaEm(), null, $this->impressao(), [], $this->aceite(SituacaoDoAceiteDaFolha::Concordo, null)),
            'outro escritório'  => fn () => DadosDaFolhaEmitida::daEmissao(self::CODIGO, new Tenant(), $this->pessoa, 2026, 10, $this->emitidaEm(), null, $this->impressao(), [], $this->aceite(SituacaoDoAceiteDaFolha::Concordo, null)),
            'pessoa sem id'     => fn () => DadosDaFolhaEmitida::daEmissao(self::CODIGO, $this->escritorio, new User(), 2026, 10, $this->emitidaEm(), null, $this->impressao(), []),
        ];
        foreach ($casos as $caso => $emitir) {
            try {
                $emitir();
                self::fail('deveria recusar: ' . $caso);
            } catch (\LogicException) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('Aceite impresso pela metade (situação sem data, ressalva sem situação) é recusado no construtor')]
    public function testAceitePelaMetade(): void
    {
        foreach ([[SituacaoDoAceiteDaFolha::Concordo, null, null], [null, $this->emitidaEm(), null], [null, null, 'texto']] as [$situacao, $em, $ressalva]) {
            try {
                new DadosDaFolhaEmitida(self::CODIGO, 2026, 10, 11, 'Maria', 'Escritório', null, $this->emitidaEm(), 12, 'Gestor', $this->impressao(), [], $situacao, $em, $ressalva);
                self::fail('deveria recusar o aceite pela metade');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('Texto fora da forma canônica (chaves, ordem, formato, competência, data, tipos, impressão, aceite) é recusado')]
    public function testRecusaForaDoFormato(): void
    {
        $dados = json_decode($this->dados($this->aceite(SituacaoDoAceiteDaFolha::Concordo, null))->canonico(), true);
        $trocar = static fn (array $troca): string => (string) json_encode(array_replace($dados, $troca), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $variantes = [
            'não é JSON'             => 'xyz',
            'lista'                  => '[1,2,3]',
            'chave a mais'           => json_encode($dados + ['cpf' => '123']),
            'faltando'               => json_encode(array_diff_key($dados, ['resumo' => 0])),
            'fora de ordem'          => json_encode(['codigo' => $dados['codigo']] + $dados),
            'outro formato'          => $trocar(['formato' => 2]),
            'formato em texto'       => $trocar(['formato' => '1']),
            'competência mês 13'     => $trocar(['competencia' => '2026-13']),
            'competência sem traço'  => $trocar(['competencia' => '202610']),
            'data inválida'          => $trocar(['emitida_em' => '03/11/2026 09:00']),
            'pessoa_id em texto'     => $trocar(['pessoa_id' => '11']),
            'impressão inválida'     => $trocar(['impressao_registros' => 'abc']),
            'resumo em texto'        => $trocar(['resumo' => 'dias']),
            'situação desconhecida'  => $trocar(['aceite' => ['situacao' => 'talvez', 'em' => '2026-11-02T18:30:00-03:00', 'ressalva' => null]]),
            'aceite com chave a mais' => $trocar(['aceite' => ['situacao' => 'concordo', 'em' => '2026-11-02T18:30:00-03:00', 'ressalva' => null, 'ip' => '1.1.1.1']]),
            'aceite em texto'        => $trocar(['aceite' => 'concordo']),
            'aceite sem data'        => $trocar(['aceite' => ['situacao' => 'concordo', 'em' => null, 'ressalva' => null]]),
        ];
        foreach ($variantes as $caso => $texto) {
            try {
                DadosDaFolhaEmitida::deCanonico((string) $texto);
                self::fail('deveria recusar: ' . $caso);
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    private function dados(?AceiteDaFolhaDoPonto $aceite = null): DadosDaFolhaEmitida
    {
        $resumo = [
            'dias'   => [['data' => '2026-10-01', 'batidas' => ['08:00', '12:00'], 'trabalhado' => 480, 'incompleto' => false]],
            'totais' => ['trabalhado' => 480, 'extra' => 0, 'saldo' => -15],
        ];

        return DadosDaFolhaEmitida::daEmissao(self::CODIGO, $this->escritorio, $this->pessoa, 2026, 10, $this->emitidaEm(), $this->gestor, $this->impressao(), $resumo, $aceite);
    }

    private function aceite(SituacaoDoAceiteDaFolha $situacao, ?string $ressalva): AceiteDaFolhaDoPonto
    {
        $vista = new FolhaEmitidaDoPonto($this->escritorio, $this->pessoa, $this->pessoa, 2026, 10, 'BJF-ZZZZ-ZZZZ-ZZZZ', str_repeat('f', 64), 1, new \DateTimeImmutable('2026-11-02 18:29:00'), $this->impressao(), '{}');

        return new AceiteDaFolhaDoPonto($vista, 1, $situacao, $ressalva, new \DateTimeImmutable('2026-11-02 18:30:00', new \DateTimeZone('America/Sao_Paulo')), '189.6.10.2', 'Mozilla/5.0', str_repeat('e', 64), 1);
    }

    private function emitidaEm(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-03 09:00:00', new \DateTimeZone('America/Sao_Paulo'));
    }

    private function impressao(): string
    {
        return hash('sha256', 'dias e totais');
    }

    private function usuario(int $id, ?string $nome, string $email): User
    {
        $usuario = new User();
        $usuario->setEmail($email);
        if ($nome !== null) {
            $usuario->setFullName($nome);
        }
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }
}
