<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os comandos e relatórios do drawer (desenho 02 - EXPEDIENTES - VS 18.10.26, `CMDS` L5894-5905 e
 * `MOD` L5906-5913; spec inteligencia-drawer-da-pasta F1/F2): rótulos, ícones, agentes e pedidos do
 * desenho, com os ajustes das regras (conteúdo de documento não é lido; nada de dado pessoal).
 */
#[CoversClass(Comando::class)]
final class ComandoTest extends TestCase
{
    /** @return iterable<string, array{Comando}> */
    public static function todos(): iterable
    {
        foreach (Comando::cases() as $comando) {
            yield $comando->value => [$comando];
        }
    }

    #[TestDox('10 comandos e 5 relatórios, na ordem do desenho; o "Relatório do cliente" (D12) fica de fora')]
    public function testListas(): void
    {
        self::assertSame([
            'Analisar esta pasta',
            'Identificar riscos',
            'Verificar prazos',
            'Processo parado?',
            'Próximas providências',
            'Briefing para o advogado',
            'Linha do tempo',
            'Analisar histórico',
            'Raio-X jurídico',
            'Análise jurídica profunda',
        ], array_map(static fn (Comando $c): string => $c->rotulo(), Comando::comandos()));

        self::assertSame([
            'Relatório da pasta',
            'Resumo para o cliente',
            'Relatório de prazos',
            'Relatório de risco',
            'Linha do tempo',
        ], array_map(static fn (Comando $c): string => $c->rotulo(), Comando::relatorios()));

        foreach (Comando::cases() as $comando) {
            self::assertStringNotContainsString('Relatório do cliente', $comando->rotulo());
        }
    }

    #[TestDox('valor snake_case de até 30 caracteres (coluna `comando` VARCHAR(30) e segmento da rota)')]
    #[DataProvider('todos')]
    public function testValor(Comando $comando): void
    {
        self::assertMatchesRegularExpression('/^[a-z_]{1,30}$/', $comando->value);
        self::assertSame($comando, Comando::deRota(' ' . strtoupper($comando->value) . ' '));
    }

    #[TestDox('comando inexistente na rota é nulo')]
    public function testDeRotaInexistente(): void
    {
        self::assertNull(Comando::deRota('relatorio_cliente'));
        self::assertNull(Comando::deRota('gestor'));
        self::assertNull(Comando::deRota(''));
    }

    #[TestDox('os agentes do desenho')]
    public function testAgentes(): void
    {
        $esperado = [
            'analisar_pasta' => Agente::Gestor,
            'identificar_riscos' => Agente::Gestor,
            'verificar_prazos' => Agente::Prazos,
            'processo_parado' => Agente::Processual,
            'proximas_providencias' => Agente::Gestor,
            'briefing' => Agente::Relatorios,
            'linha_do_tempo' => Agente::Processual,
            'analisar_historico' => Agente::Cliente,
            'raio_x' => Agente::Processual,
            'analise_juridica' => Agente::Juridico,
            'relatorio_pasta' => Agente::Relatorios,
            'resumo_cliente' => Agente::Relatorios,
            'relatorio_prazos' => Agente::Prazos,
            'relatorio_risco' => Agente::Gestor,
            'relatorio_linha_do_tempo' => Agente::Processual,
        ];

        foreach (Comando::cases() as $comando) {
            self::assertSame($esperado[$comando->value], $comando->agente(), $comando->value);
        }
    }

    #[TestDox('os ícones do desenho')]
    public function testIcones(): void
    {
        self::assertSame(
            ['bi-clipboard-data', 'bi-shield-exclamation', 'bi-alarm', 'bi-hourglass-split', 'bi-signpost-2', 'bi-person-video3', 'bi-clock-history', 'bi-journal-text', 'bi-upc-scan', 'bi-bank'],
            array_map(static fn (Comando $c): string => $c->icone(), Comando::comandos()),
        );
        self::assertSame(
            ['bi-journal-text', 'bi-chat-square-text', 'bi-alarm', 'bi-shield-exclamation', 'bi-clock-history'],
            array_map(static fn (Comando $c): string => $c->icone(), Comando::relatorios()),
        );
    }

    #[TestDox('todo comando tem agente, rótulo e pedido; só o relatório tem descrição')]
    #[DataProvider('todos')]
    public function testTodosTemOEssencial(Comando $comando): void
    {
        self::assertInstanceOf(Agente::class, $comando->agente());
        self::assertNotSame('', $comando->rotulo());
        self::assertNotSame('', trim($comando->pedido()));
        self::assertSame($comando->ehRelatorio(), $comando->descricao() !== null);
    }

    #[TestDox('pedidos dos comandos literais do desenho')]
    public function testPedidosLiterais(): void
    {
        self::assertSame('Analise esta pasta por completo: resumo executivo, cliente, responsável, processo, fase, última movimentação, prazos, tarefas pendentes, riscos, pendências documentais e prioridades.', Comando::AnalisarPasta->pedido());
        self::assertSame('Identifique os riscos operacionais e jurídicos desta pasta e explique por que cada um foi destacado.', Comando::IdentificarRiscos->pedido());
        self::assertSame('Verifique os prazos desta pasta, prazos vencidos e risco de perda de prazo.', Comando::VerificarPrazos->pedido());
        self::assertSame('Avalie se o processo está parado considerando a fase, os prazos e as providências pendentes, sem usar apenas o número de dias.', Comando::ProcessoParado->pedido());
        self::assertSame('Liste as próximas providências em ordem de prioridade, explicando o motivo de cada prioridade.', Comando::ProximasProvidencias->pedido());
        self::assertSame('Prepare um briefing curto para o advogado responsável conversar com o cliente.', Comando::Briefing->pedido());
        self::assertSame('Monte a linha do tempo do caso com as datas disponíveis.', Comando::LinhaDoTempo->pedido());
        self::assertSame('Analise o histórico de registros e atendimento desta pasta.', Comando::AnalisarHistorico->pedido());
        self::assertStringStartsWith('Faça o raio-X jurídico: verifique documentos, prazos, decisões, manifestações, movimentações, tarefas, cadastro e histórico, classificando cada item como OK, ATENÇÃO, PENDÊNCIA, INCONSISTÊNCIA ou URGENTE.', Comando::RaioX->pedido());
        self::assertStringStartsWith('Faça a análise jurídica profunda: 1 contexto, 2 fatos relevantes,', Comando::AnaliseJuridica->pedido());
    }

    #[TestDox('relatórios: Gere o "<nome>" (<descrição>); o Resumo para o cliente pede linguagem leiga')]
    public function testPedidosDosRelatorios(): void
    {
        self::assertSame('Gere o "Relatório da pasta" (Situação, processo, prazos e providências).', Comando::RelatorioPasta->pedido());
        self::assertSame('Gere o "Resumo para o cliente" (Linguagem simples, pronto para enviar). Escreva para o cliente leigo, cordial, em até 8 frases, sem termos técnicos.', Comando::ResumoCliente->pedido());
        self::assertSame('Gere o "Relatório de prazos" (O que vence e quem é responsável).', Comando::RelatorioPrazos->pedido());
        self::assertSame('Gere o "Relatório de risco" (Pontos críticos e prioridades).', Comando::RelatorioRisco->pedido());
        self::assertSame('Gere o "Linha do tempo" (Fatos e registros em ordem).', Comando::RelatorioLinhaDoTempo->pedido());
    }

    #[TestDox('botão do relatório: "Gerar " + nome em minúsculas (dc L5970)')]
    public function testRotuloDoBotao(): void
    {
        self::assertSame('Gerar relatório da pasta', Comando::RelatorioPasta->rotuloDoBotao());
        self::assertSame('Gerar resumo para o cliente', Comando::ResumoCliente->rotuloDoBotao());
        self::assertSame('Gerar linha do tempo', Comando::RelatorioLinhaDoTempo->rotuloDoBotao());
    }

    #[TestDox('nenhum pedido afirma ler o conteúdo de documento; quem fala de documentos diz que não foram lidos')]
    #[DataProvider('todos')]
    public function testPedidoNaoAfirmaLerDocumento(Comando $comando): void
    {
        $pedido = $comando->pedido();

        self::assertDoesNotMatchRegularExpression('/\bleia\b|\bleitura\b|\bOCR\b|ENCONTRADO NO DOCUMENTO|lê os arquivos/iu', $pedido);
        if (preg_match('/verifique documentos|documentos relevantes/u', $pedido) === 1) {
            self::assertStringContainsString('O conteúdo dos arquivos não foi lido', $pedido);
        }
    }

    #[TestDox('a Análise jurídica separa FATO ENCONTRADO NOS DADOS (como o Agente Jurídico de hoje)')]
    public function testJuridicaFatoNosDados(): void
    {
        self::assertStringContainsString('Separe FATO ENCONTRADO NOS DADOS, INTERPRETAÇÃO DA IA, ARGUMENTO JURÍDICO e SUGESTÃO.', Comando::AnaliseJuridica->pedido());
        self::assertStringContainsString('O conteúdo dos arquivos não foi lido', Comando::RaioX->pedido());
    }

    #[TestDox('nenhum pedido carrega dado da pasta nem dado pessoal (só texto fixo, sem dígitos de documento ou e-mail)')]
    #[DataProvider('todos')]
    public function testPedidoSemDadoPessoal(Comando $comando): void
    {
        $pedido = $comando->pedido();

        self::assertDoesNotMatchRegularExpression('/\d{3}\.?\d{3}\.?\d{3}-?\d{2}|@|CPF|CNPJ|telefone|endereço/iu', $pedido);
        self::assertStringNotContainsString('—', $pedido);
        self::assertStringNotContainsString('–', $pedido);
        self::assertStringNotContainsString('—', $comando->rotulo() . (string) $comando->descricao());
    }

    #[TestDox('doAgenteLegado: cada agente antigo cai num comando, e agentesLegados é o inverso')]
    public function testDoAgenteLegado(): void
    {
        self::assertSame(Comando::AnalisarPasta, Comando::doAgenteLegado(Agente::Gestor));
        self::assertSame(Comando::ProcessoParado, Comando::doAgenteLegado(Agente::Processual));
        self::assertSame(Comando::RaioX, Comando::doAgenteLegado(Agente::Documental));
        self::assertSame(Comando::VerificarPrazos, Comando::doAgenteLegado(Agente::Prazos));
        self::assertSame(Comando::RelatorioPasta, Comando::doAgenteLegado(Agente::Relatorios));
        self::assertSame(Comando::AnalisarHistorico, Comando::doAgenteLegado(Agente::Cliente));
        self::assertSame(Comando::AnaliseJuridica, Comando::doAgenteLegado(Agente::Juridico));

        foreach (Agente::cases() as $agente) {
            self::assertContains($agente, Comando::doAgenteLegado($agente)->agentesLegados());
        }
        self::assertSame([], Comando::IdentificarRiscos->agentesLegados());
        self::assertSame([Agente::Gestor], Comando::AnalisarPasta->agentesLegados());
    }
}
