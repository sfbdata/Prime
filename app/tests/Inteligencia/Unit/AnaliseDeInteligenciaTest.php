<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnaliseDeInteligencia::class)]
final class AnaliseDeInteligenciaTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    private function nova(): AnaliseDeInteligencia
    {
        return new AnaliseDeInteligencia(
            tenant: $this->tenant,
            solicitante: $this->user,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: 7,
            versaoDoPrompt: 'push-v1',
            contextoHash: str_repeat('a', 64),
            contextoResumo: ['chaves' => ['pub:1', 'pub:2'], 'total' => 2, 'novas' => 2],
        );
    }

    private function concluir(AnaliseDeInteligencia $a): void
    {
        $a->concluir('Resumo.', [['tipo' => 'ok', 'texto' => 'tudo certo']], 'Equipe', '{"resumo":"Resumo."}', 'falso', 'falso-1', 10, 5, 3);
    }

    #[TestDox('nasce pendente, em andamento, com tentativas 0 e interna do escritório')]
    public function testEstadoInicial(): void
    {
        $a = $this->nova();

        self::assertSame(StatusDaAnalise::Pendente, $a->getStatus());
        self::assertTrue($a->estaEmAndamento());
        self::assertSame(0, $a->getTentativas());
        self::assertTrue($a->isInternaDoEscritorio());
        self::assertFalse($a->foiLida());
        self::assertFalse($a->estaExcluida());
        self::assertSame(['pub:1', 'pub:2'], $a->getChavesAnalisadas());
    }

    #[TestDox('pendente → processando conta a tentativa e marca o início')]
    public function testIniciarProcessamento(): void
    {
        $a = $this->nova();

        $a->iniciarProcessamento();

        self::assertSame(StatusDaAnalise::Processando, $a->getStatus());
        self::assertSame(1, $a->getTentativas());
        self::assertNotNull($a->getIniciadaEm());
    }

    #[TestDox('processando → concluída guarda resultado, provedor, tokens e o texto bruto')]
    public function testConcluir(): void
    {
        $a = $this->nova();
        $a->iniciarProcessamento();

        $this->concluir($a);

        self::assertSame(StatusDaAnalise::Concluida, $a->getStatus());
        self::assertTrue($a->estaConcluida());
        self::assertSame('Resumo.', $a->getResumo());
        self::assertSame([['tipo' => 'ok', 'texto' => 'tudo certo']], $a->getPontos());
        self::assertSame('Equipe', $a->getQuemAge());
        self::assertSame('falso', $a->getProvedor());
        self::assertSame('falso-1', $a->getModelo());
        self::assertSame(10, $a->getTokensEntrada());
        self::assertSame(5, $a->getTokensSaida());
        self::assertSame(3, $a->getDuracaoMs());
        self::assertSame('{"resumo":"Resumo."}', $a->getTextoBruto());
        self::assertNotNull($a->getConcluidaEm());
        self::assertNull($a->getErroMotivo());
    }

    #[TestDox('concluir sem ter iniciado é transição inválida')]
    public function testConcluirDePendenteEhInvalido(): void
    {
        $a = $this->nova();

        $this->expectException(\DomainException::class);
        $this->concluir($a);
    }

    #[TestDox('falhou pode voltar a processando (retry) e conta nova tentativa; o motivo anterior some')]
    public function testFalharERetentar(): void
    {
        $a = $this->nova();
        $a->iniciarProcessamento();
        $a->falhar('timeout');

        self::assertSame(StatusDaAnalise::Falhou, $a->getStatus());
        self::assertSame('timeout', $a->getErroMotivo());
        self::assertTrue($a->getStatus()->terminal());

        $a->iniciarProcessamento();

        self::assertSame(StatusDaAnalise::Processando, $a->getStatus());
        self::assertSame(2, $a->getTentativas());
        self::assertNull($a->getErroMotivo());
    }

    #[TestDox('falhar guarda o texto bruto quando houve resposta (JSON inválido)')]
    public function testFalharComTextoBruto(): void
    {
        $a = $this->nova();
        $a->iniciarProcessamento();

        $a->falhar('resposta inválida', 'não sei');

        self::assertSame('não sei', $a->getTextoBruto());
    }

    #[TestDox('concluída não falha nem é reprocessada')]
    public function testConcluidaEhTerminal(): void
    {
        $a = $this->nova();
        $a->iniciarProcessamento();
        $this->concluir($a);

        try {
            $a->falhar('x');
            self::fail('concluída não pode falhar');
        } catch (\DomainException $e) {
            self::assertStringContainsString('concluida', $e->getMessage());
        }

        try {
            $a->iniciarProcessamento();
            self::fail('concluída não pode ser reprocessada');
        } catch (\DomainException $e) {
            self::assertStringContainsString('concluida', $e->getMessage());
        }

        self::assertSame(StatusDaAnalise::Concluida, $a->getStatus(), 'nada mudou');
        self::assertSame(1, $a->getTentativas());
    }

    #[TestDox('indisponível é terminal: não volta a processando')]
    public function testIndisponivelEhTerminal(): void
    {
        $a = $this->nova();
        $a->iniciarProcessamento();
        $a->marcarIndisponivel('sem provedor');

        self::assertSame(StatusDaAnalise::Indisponivel, $a->getStatus());
        self::assertSame('sem provedor', $a->getErroMotivo());

        $this->expectException(\DomainException::class);
        $a->iniciarProcessamento();
    }

    #[TestDox('pendente também pode ir direto a falhou (dispatch que falhou) e a indisponível')]
    public function testPendenteParaFalhouEIndisponivel(): void
    {
        $a = $this->nova();
        $a->falhar('falha ao enfileirar');
        self::assertSame(StatusDaAnalise::Falhou, $a->getStatus());

        $b = $this->nova();
        $b->marcarIndisponivel('x');
        self::assertSame(StatusDaAnalise::Indisponivel, $b->getStatus());
    }

    #[TestDox('marcarLida é idempotente: quem leu primeiro fica')]
    public function testMarcarLida(): void
    {
        $a = $this->nova();
        $outro = new User();

        $a->marcarLida($this->user);
        $primeiraVez = $a->getLidaEm();
        $a->marcarLida($outro);

        self::assertTrue($a->foiLida());
        self::assertSame($this->user, $a->getLidaPor());
        self::assertSame($primeiraVez, $a->getLidaEm());
    }

    #[TestDox('excluir é soft: a análise continua existindo, com quem e quando')]
    public function testExcluir(): void
    {
        $a = $this->nova();

        $a->excluir($this->user);

        self::assertTrue($a->estaExcluida());
        self::assertSame($this->user, $a->getExcluidaPor());
        self::assertNotNull($a->getExcluidaEm());
        self::assertSame(StatusDaAnalise::Pendente, $a->getStatus(), 'excluir não mexe no status');
    }

    #[TestDox('alternarInterna alterna o cadeado e devolve o estado novo')]
    public function testAlternarInterna(): void
    {
        $a = $this->nova();

        self::assertFalse($a->alternarInterna());
        self::assertFalse($a->isInternaDoEscritorio());
        self::assertTrue($a->alternarInterna());
        self::assertTrue($a->isInternaDoEscritorio());
    }

    #[TestDox('registrarContexto troca hash e resumo pelo que o worker efetivamente usou')]
    public function testRegistrarContexto(): void
    {
        $a = $this->nova();

        $a->registrarContexto(str_repeat('b', 64), ['chaves' => ['pub:9'], 'total' => 1, 'novas' => 1]);

        self::assertSame(str_repeat('b', 64), $a->getContextoHash());
        self::assertSame(['pub:9'], $a->getChavesAnalisadas());
    }
}
