<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\DivulgacaoDaAssembleiaOutput;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\DTO\ResultadoDaOpcao;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\ImagemDoResumoDoResultado;
use App\Voto\Service\MontadorDoResumoDoResultado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Regras do resumo para divulgar (GerarResumoDoResultadoUseCase monta por aqui): só pauta encerrada e apurada (C12),
 * nenhum dado pessoal e hash SHA-256 que acompanha o conteúdo.
 */
#[CoversClass(MontadorDoResumoDoResultado::class)]
#[CoversClass(ImagemDoResumoDoResultado::class)]
final class MontadorDoResumoDoResultadoTest extends TestCase
{
    private const EMITIDO = '2026-11-16 10:00:00';

    #[TestDox('Só entra pauta encerrada e apurada: aguardando, aberta e cancelada ficam de fora')]
    public function testSoEncerradasEApuradas(): void
    {
        $resumo = $this->montador()->montar(
            self::assembleia([
                self::pauta(1, 'Aguardando', EstadoDaPauta::Aguardando),
                self::pauta(2, 'Aberta', EstadoDaPauta::Aberta),
                self::pauta(3, 'Cancelada', EstadoDaPauta::Encerrada, cancelada: true),
                self::pauta(4, 'Contas aprovadas', EstadoDaPauta::Encerrada, self::resultado('1', 'Sim', 8, 2)),
            ]),
            self::divulgacao(),
            new \DateTimeImmutable(self::EMITIDO),
        );

        self::assertSame(['Contas aprovadas'], array_map(static fn ($p): string => $p->titulo, $resumo->pautas));
        $pauta = $resumo->pautas[0];
        self::assertSame('Aprovada', $pauta->resultado);
        self::assertSame('10 de 12 aptos (83%)', $pauta->participacao);
        self::assertSame('Quórum atingido', $pauta->quorum);
        self::assertSame([['rotulo' => '1  Sim', 'votos' => 8], ['rotulo' => '2  Não', 'votos' => 2], ['rotulo' => 'Abstenções', 'votos' => 0], ['rotulo' => 'Nulos', 'votos' => 0]], $pauta->linhas);
        self::assertSame('ABCD-WXYZ', $resumo->codigoDeConferencia);
    }

    #[TestDox('Sem pauta encerrada e apurada: recusa com a mensagem para o gestor')]
    public function testSemApuradaRecusa(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Ainda não há votação apurada para divulgar.');
        $this->montador()->montar(self::assembleia([self::pauta(1, 'Aberta', EstadoDaPauta::Aberta)]), self::divulgacao(), new \DateTimeImmutable(self::EMITIDO));
    }

    #[TestDox('Nenhum dado pessoal: nem nome, unidade, e-mail ou responsável aparecem no resumo nem na imagem')]
    public function testSemDadoPessoal(): void
    {
        $resumo = $this->montador()->montar(
            self::assembleia([self::pauta(1, 'Contas', EstadoDaPauta::Encerrada, self::resultado('1', 'Sim', 8, 2), encerradaPor: 'Síndica Maria Souza')]),
            self::divulgacao(),
            new \DateTimeImmutable(self::EMITIDO),
        );

        $texto = json_encode($resumo, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Maria', (string) $texto, 'quem encerrou também é nome de pessoa');
        self::assertStringNotContainsString('@', (string) $texto);
        $propriedades = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass($resumo->pautas[0]))->getProperties());
        self::assertSame(['titulo', 'participacao', 'apuradaEm', 'quorum', 'resultado', 'linhas'], $propriedades);

        $png = (new ImagemDoResumoDoResultado($this->tradutor()))->gerar($resumo);
        self::assertStringStartsWith("\x89PNG", $png);
        self::assertNotFalse(imagecreatefromstring($png));
    }

    #[TestDox('Imagem alta demais (muitas votações) é recusada com aviso para usar o PDF')]
    public function testImagemComMuitasPautasRecusa(): void
    {
        $pautas = array_map(static fn (int $i): PautaNoPainel => self::pauta($i, 'Pauta ' . $i, EstadoDaPauta::Encerrada, self::resultado('1', 'Sim', 8, 2)), range(1, 60));
        $resumo = $this->montador()->montar(self::assembleia($pautas), self::divulgacao(), new \DateTimeImmutable(self::EMITIDO));

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        (new ImagemDoResumoDoResultado($this->tradutor()))->gerar($resumo);
    }

    #[TestDox('O hash SHA-256 acompanha o conteúdo: muda com o resultado e não muda com a data de emissão')]
    public function testHashMudaComOConteudo(): void
    {
        $montar = fn (int $sim, string $emitido): string => $this->montador()->montar(
            self::assembleia([self::pauta(1, 'Contas', EstadoDaPauta::Encerrada, self::resultado('1', 'Sim', $sim, 2))]),
            self::divulgacao(),
            new \DateTimeImmutable($emitido),
        )->hash;

        $base = $montar(8, self::EMITIDO);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);
        self::assertSame($base, $montar(8, '2026-11-17 18:30:00'));
        self::assertNotSame($base, $montar(7, self::EMITIDO));
    }

    #[TestDox('Quórum não atingido vira "Não deliberada", sem vencedor')]
    public function testQuorumNaoAtingido(): void
    {
        $resultado = self::resultado('1', 'Sim', 1, 0, quorumOk: false);
        $resumo = $this->montador()->montar(self::assembleia([self::pauta(1, 'Contas', EstadoDaPauta::Encerrada, $resultado)]), self::divulgacao(), new \DateTimeImmutable(self::EMITIDO));

        self::assertSame('Não deliberada: quórum não atingido', $resumo->pautas[0]->resultado);
        self::assertSame('Quórum não atingido', $resumo->pautas[0]->quorum);
    }

    private function montador(): MontadorDoResumoDoResultado
    {
        return new MontadorDoResumoDoResultado($this->tradutor());
    }

    private function tradutor(): Translator
    {
        $tradutor = new Translator('pt_BR');
        $tradutor->addLoader('yaml', new YamlFileLoader());
        $tradutor->addResource('yaml', \dirname(__DIR__, 3) . '/translations/voto/messages+intl-icu.pt_BR.yaml', 'pt_BR', 'messages+intl-icu');

        return $tradutor;
    }

    private static function divulgacao(): DivulgacaoDaAssembleiaOutput
    {
        return new DivulgacaoDaAssembleiaOutput(7, 'AGO 2026', 'abcd1234wxyz', 'https://bluejus.com.br/voto/v/abcd1234wxyz');
    }

    /** @param list<PautaNoPainel> $pautas */
    private static function assembleia(array $pautas): AssembleiaNoPainel
    {
        return new AssembleiaNoPainel(7, 'AGO 2026', PesoDoVoto::Unidade, EstadoDaAssembleia::Publicada, 'Gestora', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-02'), false, false, 12, 0, '12.000000', $pautas);
    }

    private static function resultado(string $numero, string $rotulo, int $sim, int $nao, bool $quorumOk = true): ResultadoDaPauta
    {
        $opcoes = [new ResultadoDaOpcao((int) $numero, $rotulo, $sim, $sim . '.000000', '80.00'), new ResultadoDaOpcao(2, 'Não', $nao, $nao . '.000000', '20.00')];

        return new ResultadoDaPauta(
            situacao: $quorumOk ? ResultadoDaPauta::DECIDIDO : ResultadoDaPauta::QUORUM_NAO_ATINGIDO,
            vencedor: $opcoes[0],
            opcoes: $opcoes,
            empate: false,
            abstencoesUnidades: 0,
            abstencoesPeso: '0.000000',
            votantes: $sim + $nao,
            pesoValido: ($sim + $nao) . '.000000',
            pesoPresente: ($sim + $nao) . '.000000',
            pesoApto: '12.000000',
            unidadesAptas: 12,
            quorumPct: '83.33',
            quorumExigido: '50.00',
            quorumOk: $quorumOk,
            vencedores: [$opcoes[0]],
        );
    }

    private static function pauta(int $id, string $titulo, EstadoDaPauta $estado, ?ResultadoDaPauta $resultado = null, bool $cancelada = false, ?string $encerradaPor = null): PautaNoPainel
    {
        return new PautaNoPainel(
            id: $id,
            ordem: $id,
            titulo: $titulo,
            descricao: null,
            estado: $estado,
            assembleiaPublicada: true,
            convocacao: 1,
            quorumExigido: '50.00',
            abertaEm: null,
            abertaPorNome: null,
            prazoAte: null,
            encerradaEm: EstadoDaPauta::Encerrada === $estado ? new \DateTimeImmutable('2026-11-10 15:30') : null,
            encerradaPorNome: $encerradaPor,
            motivoEncerramento: null,
            opcoes: [['numero' => 1, 'rotulo' => 'Sim'], ['numero' => 2, 'rotulo' => 'Não']],
            votantes: 10,
            pesoVotante: '10.000000',
            unidadesAptas: 12,
            pesoApto: '12.000000',
            resultado: $resultado,
            canceladaEm: $cancelada ? new \DateTimeImmutable('2026-11-10 15:00') : null,
        );
    }
}
