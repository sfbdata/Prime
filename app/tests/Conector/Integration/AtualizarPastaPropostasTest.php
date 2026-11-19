<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarPastaAvisos;
use App\Conector\Ferramenta\AtualizarPastaCadastro;
use App\Conector\Ferramenta\AtualizarPastaDados;
use App\Conector\Ferramenta\AtualizarPastaFerramenta;
use App\Conector\Ferramenta\AtualizarPastaMarcadores;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Repository\PastaReuRepository;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `atualizar_pasta`, as ações que preenchem direto e gravam por cima por proposta (spec mcp-remoto-oauth 6.6, abertura
 * e bloco da ferramenta, e 6.7): `editar_dados`, `marcadores_da_pasta`, `marcador`, `nome_do_cliente`,
 * `cadastro_da_pasta` e `reu`; e os avisos `pedir_acesso` e `enviar_relatorio_sem_processo`. Toda proposta só grava
 * depois da `confirmar_acao`, e a confirmação recusa quando o valor mudou desde a proposta.
 */
#[CoversClass(AtualizarPastaFerramenta::class)]
#[CoversClass(AtualizarPastaDados::class)]
#[CoversClass(AtualizarPastaMarcadores::class)]
#[CoversClass(AtualizarPastaCadastro::class)]
#[CoversClass(AtualizarPastaAvisos::class)]
final class AtualizarPastaPropostasTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const TUDO = ['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit'];

    /** CPF válido de teste (dígitos verificadores certos). */
    private const CPF = '52998224725';

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::TUDO);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable());
        $this->em()->persist($app);
        $this->consentimento = new ConsentimentoDoConector($this->pessoa, $this->escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($this->consentimento);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ editar_dados

    #[TestDox('editar_dados: preencher o identificador vazio grava na hora, em maiúsculas')]
    public function testEditarDadosPreenche(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'editar_dados', 'pasta_id' => $pasta->getId(), 'nome_cliente' => 'maria souza']);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertSame('MARIA SOUZA', $this->pastaAtual($pasta)->getNomeCliente());
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('editar_dados: trocar o número preenchido vira proposta; confirmar grava; pedir o mesmo valor recusa')]
    public function testEditarDadosPorCima(): void
    {
        $pasta = $this->pasta();
        $nupAntigo = (string) $pasta->getNup();

        $proposta = $this->executar(['acao' => 'editar_dados', 'pasta_id' => $pasta->getId(), 'nup' => 'novo-1']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('NOVO-1', $proposta->dados['proposta']['resumo']);
        self::assertSame($nupAntigo, $this->pastaAtual($pasta)->getNup(), 'propor não grava');

        $this->confirmar($proposta);

        self::assertSame('NOVO-1', $this->pastaAtual($pasta)->getNup());
        $this->assertRecusa(['acao' => 'editar_dados', 'pasta_id' => $pasta->getId(), 'nup' => 'NOVO-1'], AtualizarPastaFerramenta::NADA_A_MUDAR);
        $this->assertRecusa(['acao' => 'editar_dados', 'pasta_id' => $pasta->getId(), 'nup' => '  '], AtualizarPastaDados::NUP_VAZIO);
    }

    #[TestDox('editar_dados: o número mudou depois da proposta, a confirmação recusa e nada é gravado por cima')]
    public function testEditarDadosQueMudou(): void
    {
        $pasta = $this->pasta();
        $proposta = $this->executar(['acao' => 'editar_dados', 'pasta_id' => $pasta->getId(), 'nup' => 'NOVO-2']);
        $this->pastaAtual($pasta)->setNup('OUTRO-3');
        $this->em()->flush();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(AtualizarPastaFerramenta::MUDOU, $erro->getMessage());
        self::assertSame('OUTRO-3', $this->pastaAtual($pasta)->getNup());
    }

    // ------------------------------------------------------------------ marcadores da pasta

    #[TestDox('marcadores_da_pasta: só acrescentar grava na hora')]
    public function testMarcadoresAcrescenta(): void
    {
        $pasta = $this->pasta();
        $a = $this->marcador('Alfa');
        $b = $this->marcador('Beta');

        $resposta = $this->executar(['acao' => 'marcadores_da_pasta', 'pasta_id' => $pasta->getId(), 'marcadores' => [$a->getId(), $b->getId()]]);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertSame($this->ordenados([$a->getId(), $b->getId()]), $this->marcadoresDa($pasta));
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('marcadores_da_pasta: tirar um marcador vira uma proposta só, com o que sai e o que entra; confirmar grava a lista')]
    public function testMarcadoresTira(): void
    {
        $pasta = $this->pasta();
        $a = $this->marcador('Alfa');
        $b = $this->marcador('Beta');
        $c = $this->marcador('Gama');
        $this->executar(['acao' => 'marcadores_da_pasta', 'pasta_id' => $pasta->getId(), 'marcadores' => [$a->getId(), $b->getId()]]);

        $proposta = $this->executar(['acao' => 'marcadores_da_pasta', 'pasta_id' => $pasta->getId(), 'marcadores' => [$b->getId(), $c->getId()]]);

        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('tirar "Alfa"', $resumo);
        self::assertStringContainsString('acrescentar "Gama"', $resumo);
        self::assertSame($this->ordenados([$a->getId(), $b->getId()]), $this->marcadoresDa($pasta), 'propor não grava');

        $this->confirmar($proposta);

        self::assertSame($this->ordenados([$b->getId(), $c->getId()]), $this->marcadoresDa($pasta));
    }

    #[TestDox('marcadores_da_pasta: a lista da pasta mudou depois da proposta, a confirmação recusa')]
    public function testMarcadoresQueMudaram(): void
    {
        $pasta = $this->pasta();
        $a = $this->marcador('Alfa');
        $b = $this->marcador('Beta');
        $this->executar(['acao' => 'marcadores_da_pasta', 'pasta_id' => $pasta->getId(), 'marcadores' => [$a->getId()]]);
        $proposta = $this->executar(['acao' => 'marcadores_da_pasta', 'pasta_id' => $pasta->getId(), 'marcadores' => []]);
        $this->executar(['acao' => 'marcadores_da_pasta', 'pasta_id' => $pasta->getId(), 'marcadores' => [$a->getId(), $b->getId()]]);

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(AtualizarPastaFerramenta::MUDOU, $erro->getMessage());
        self::assertSame($this->ordenados([$a->getId(), $b->getId()]), $this->marcadoresDa($pasta));
    }

    #[TestDox('marcadores_da_pasta: marcador de outro escritório é "não encontrado", e nada é gravado')]
    public function testMarcadorDeOutroEscritorio(): void
    {
        $pasta = $this->pasta();
        $alheio = new Marcador('Alheio', $this->escritorio('Outro'), $this->pessoa());
        $this->em()->persist($alheio);
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'marcadores_da_pasta', 'pasta_id' => $pasta->getId(), 'marcadores' => [$alheio->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame([], $this->marcadoresDa($pasta));
    }

    // ------------------------------------------------------------------ marcador do escritório

    #[TestDox('marcador criar: grava na hora; nome repetido, cor fora da paleta e campo de outra operação recusam')]
    public function testCriarMarcador(): void
    {
        $resposta = $this->executar(['acao' => 'marcador', 'operacao' => 'criar', 'nome' => 'Prioridade alta', 'cor' => '#0a6fa0']);

        self::assertSame('Prioridade alta', $resposta->dados['marcador']['nome']);
        self::assertSame('#0a6fa0', $resposta->dados['marcador']['cor']);
        self::assertNotNull($this->em()->find(Marcador::class, $resposta->dados['marcador']['id']));

        $this->assertRecusa(['acao' => 'marcador', 'operacao' => 'criar', 'nome' => 'Prioridade alta'], AtualizarPastaMarcadores::NOME_REPETIDO);
        $this->assertRecusa(['acao' => 'marcador', 'operacao' => 'criar', 'nome' => 'Outro', 'cor' => '#123456'], AtualizarPastaMarcadores::COR_INVALIDA);
        $this->assertMotivo(['acao' => 'marcador', 'operacao' => 'criar', 'nome' => 'Outro', 'alvo_id' => 1], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('marcador editar: sempre proposta com o antes e o depois; confirmar troca; renomeado no meio, a confirmação recusa')]
    public function testEditarMarcador(): void
    {
        $marcador = $this->marcador('Antigo');

        $proposta = $this->executar(['acao' => 'marcador', 'operacao' => 'editar', 'marcador_id' => $marcador->getId(), 'nome' => 'Novo nome']);

        self::assertStringContainsString('Nome: de "Antigo" para "Novo nome".', $proposta->dados['proposta']['resumo']);
        $this->em()->clear();
        self::assertSame('Antigo', $this->em()->find(Marcador::class, $marcador->getId())?->getNome(), 'propor não grava');

        $this->confirmar($proposta);
        $this->em()->clear();
        self::assertSame('Novo nome', $this->em()->find(Marcador::class, $marcador->getId())?->getNome());

        $outra = $this->executar(['acao' => 'marcador', 'operacao' => 'editar', 'marcador_id' => $marcador->getId(), 'cor' => '#1f8a4c']);
        $this->gerenciado($marcador)->setNome('Mexido na tela');
        $this->em()->flush();

        self::assertSame(AtualizarPastaFerramenta::MUDOU, $this->recusaDaConfirmacao($outra)->getMessage());
    }

    #[TestDox('marcador reordenar: grava na hora; sem o módulo Expediente, sem permissão')]
    public function testReordenarESemExpediente(): void
    {
        $a = $this->marcador('Alfa');
        $b = $this->marcador('Beta');

        $resposta = $this->executar(['acao' => 'marcador', 'operacao' => 'reordenar', 'marcador_id' => $a->getId(), 'alvo_id' => $b->getId(), 'depois' => true]);
        self::assertSame('depois', $resposta->dados['posicao']);

        $this->trocarPessoa(['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit']);
        $this->assertMotivo(['acao' => 'marcador', 'operacao' => 'criar', 'nome' => 'Sem módulo'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        $this->assertMotivo(['acao' => 'marcador', 'operacao' => 'editar', 'marcador_id' => $a->getId(), 'nome' => 'X'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        self::assertSame(0, $this->contarPropostas());
    }

    // ------------------------------------------------------------------ nome do cliente

    #[TestDox('nome_do_cliente: pasta sem nome grava na hora; trocar o nome vira proposta; confirmar troca')]
    public function testNomeDoCliente(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'nome_do_cliente', 'pasta_id' => $pasta->getId(), 'nome' => 'joão lima']);
        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertSame('JOÃO LIMA', $this->pastaAtual($pasta)->getNomeCliente());

        $proposta = $this->executar(['acao' => 'nome_do_cliente', 'pasta_id' => $pasta->getId(), 'nome' => 'Ana Lima']);
        self::assertStringContainsString('de "JOÃO LIMA" para "ANA LIMA"', $proposta->dados['proposta']['resumo']);
        self::assertSame('JOÃO LIMA', $this->pastaAtual($pasta)->getNomeCliente(), 'propor não grava');

        $this->confirmar($proposta);
        self::assertSame('ANA LIMA', $this->pastaAtual($pasta)->getNomeCliente());

        $this->assertRecusa(['acao' => 'nome_do_cliente', 'pasta_id' => $pasta->getId(), 'nome' => '   '], AtualizarPastaCadastro::NOME_VAZIO);
    }

    // ------------------------------------------------------------------ cadastro da pasta

    #[TestDox('cadastro_da_pasta: preencher vazio grava na hora, e a resposta não traz o CPF')]
    public function testCadastroPreenche(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'cadastro_da_pasta', 'pasta_id' => $pasta->getId(), 'nome_do_reu' => 'Banco Alfa', 'cpf' => '529.982.247-25', 'contrato' => 'CT-10']);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertStringNotContainsString(self::CPF, json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        $reus = $this->reus($pasta);
        self::assertSame(self::CPF, $reus[0]->getDocumento());
        self::assertSame('CT-10', $this->pastaAtual($pasta)->getContrato());
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('cadastro_da_pasta: trocar campo preenchido vira proposta com o valor novo protegido e sem o antigo no resumo; confirmar grava; mudou no meio, recusa')]
    public function testCadastroPorCima(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'cadastro_da_pasta', 'pasta_id' => $pasta->getId(), 'nome_do_reu' => 'Banco Alfa', 'cpf' => self::CPF]);

        $proposta = $this->executar(['acao' => 'cadastro_da_pasta', 'pasta_id' => $pasta->getId(), 'cpf' => '', 'email' => 'reu@exemplo.com']);

        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('CPF ou CNPJ do réu: apagar.', $resumo);
        self::assertStringContainsString('E-mail do réu: preencher com "[EMAIL]".', $resumo);
        self::assertStringNotContainsString(self::CPF, $resumo);
        self::assertStringNotContainsString('52998224725', $resumo);
        self::assertStringNotContainsString('reu@exemplo.com', $resumo);
        self::assertSame(self::CPF, $this->reus($pasta)[0]->getDocumento(), 'propor não grava');

        $this->confirmar($proposta);
        $reu = $this->reus($pasta)[0];
        self::assertNull($reu->getDocumento());
        self::assertSame('reu@exemplo.com', $reu->getEmail());

        $outra = $this->executar(['acao' => 'cadastro_da_pasta', 'pasta_id' => $pasta->getId(), 'nome_do_reu' => 'Banco Beta', 'cpf' => '111.444.777-35']);
        $resumo = $outra->dados['proposta']['resumo'];
        self::assertStringContainsString('Nome do réu: trocar para "BANCO BETA".', $resumo);
        self::assertStringContainsString('CPF ou CNPJ do réu: preencher com "[CPF]".', $resumo);
        self::assertStringNotContainsString('BANCO ALFA', $resumo, 'o valor antigo não aparece');
        self::assertStringNotContainsString('11144477735', $resumo);
        $this->executar(['acao' => 'cadastro_da_pasta', 'pasta_id' => $pasta->getId(), 'contrato' => 'CT-99']);
        self::assertSame(AtualizarPastaFerramenta::MUDOU, $this->recusaDaConfirmacao($outra)->getMessage());
    }

    #[TestDox('cadastro_da_pasta: CPF, e-mail, data ou réu sem nome inválidos recusam antes de propor, com a frase fixa')]
    public function testCadastroInvalido(): void
    {
        $id = (int) $this->pasta()->getId();

        $this->assertRecusa(['acao' => 'cadastro_da_pasta', 'pasta_id' => $id, 'nome_do_reu' => 'Banco', 'cpf' => '111.111.111-12'], AtualizarPastaCadastro::DOCUMENTO_INVALIDO);
        $this->assertRecusa(['acao' => 'cadastro_da_pasta', 'pasta_id' => $id, 'nome_do_reu' => 'Banco', 'email' => 'sem-arroba'], AtualizarPastaCadastro::EMAIL_INVALIDO);
        $this->assertRecusa(['acao' => 'cadastro_da_pasta', 'pasta_id' => $id, 'prescricao' => '2026-02-30'], AtualizarPastaCadastro::PRESCRICAO_INVALIDA);
        $this->assertRecusa(['acao' => 'cadastro_da_pasta', 'pasta_id' => $id, 'cpf' => self::CPF], AtualizarPastaCadastro::REU_SEM_NOME);
        $this->assertRecusa(['acao' => 'cadastro_da_pasta', 'pasta_id' => $id, 'contrato' => '<b>x</b>'], AtualizarPastaCadastro::TEXTO_INVALIDO);
        self::assertSame(0, $this->contarPropostas());
    }

    // ------------------------------------------------------------------ réu

    #[TestDox('reu: adicionar e subir gravam na hora, sem o documento na resposta; subir o primeiro recusa')]
    public function testReu(): void
    {
        $pasta = $this->pasta();

        $this->executar(['acao' => 'reu', 'pasta_id' => $pasta->getId(), 'operacao' => 'adicionar', 'nome' => 'Banco Um']);
        $resposta = $this->executar(['acao' => 'reu', 'pasta_id' => $pasta->getId(), 'operacao' => 'adicionar', 'nome' => 'Banco Dois', 'documento' => self::CPF]);

        self::assertCount(2, $resposta->dados['reus']);
        self::assertTrue($resposta->dados['reus'][1]['tem_documento']);
        self::assertStringNotContainsString(self::CPF, json_encode($resposta->dados, \JSON_THROW_ON_ERROR));

        $segundo = (int) $resposta->dados['reus'][1]['id'];
        $this->executar(['acao' => 'reu', 'pasta_id' => $pasta->getId(), 'operacao' => 'subir', 'reu_id' => $segundo]);
        self::assertSame($segundo, $this->reus($pasta)[0]->getId());

        $this->assertRecusa(['acao' => 'reu', 'pasta_id' => $pasta->getId(), 'operacao' => 'subir', 'reu_id' => $segundo], AtualizarPastaFerramenta::NADA_A_MUDAR);
        $this->assertRecusa(['acao' => 'reu', 'pasta_id' => $pasta->getId(), 'operacao' => 'adicionar', 'nome' => 'Banco Um'], AtualizarPastaCadastro::RECUSA_REU);
        $this->assertMotivo(['acao' => 'reu', 'pasta_id' => $pasta->getId(), 'operacao' => 'subir', 'reu_id' => $segundo, 'nome' => 'X'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        self::assertSame(0, $this->contarPropostas());
    }

    // ------------------------------------------------------------------ avisos

    #[TestDox('pedir_acesso em pasta sem cadeado recusa com a frase fixa, sem gravar pedido')]
    public function testPedirAcessoSemCadeado(): void
    {
        $pasta = $this->pasta();

        $this->assertRecusa(['acao' => 'pedir_acesso', 'pasta_id' => $pasta->getId()], AtualizarPastaAvisos::SEM_CADEADO);
    }

    #[TestDox('pedir_acesso em pasta que o cadeado esconde da pessoa: não encontrado, sem gravar pedido (decisão 32; a tela aceita, o conector não revela a pasta)')]
    public function testPedirAcessoEmPastaTrancada(): void
    {
        $pasta = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->gerenciado($this->escritorio), $pasta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'pedir_acesso', 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_pedido_acesso'));
    }

    #[TestDox('enviar_relatorio_sem_processo: a partir de uma pasta que a pessoa edita, envia e avisa que o relatório é do escritório')]
    public function testEnviarRelatorio(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'enviar_relatorio_sem_processo', 'pasta_id' => $pasta->getId()]);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertArrayHasKey('avisados', $resposta->dados);
        self::assertStringContainsString('escritório inteiro', $resposta->dados['aviso']);
    }

    // ------------------------------------------------------------------ apoio

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();

        return static::getContainer()->get(AtualizarPastaFerramenta::class)->executar($argumentos, $this->chamada());
    }

    /** Confirma pela `confirmar_acao` de verdade, com o resumo exato da proposta. */
    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();
        $argumentos = ['proposta_id' => $proposta->dados['proposta']['proposta_id'], 'resumo' => $proposta->dados['proposta']['resumo']];

        return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar($argumentos, $this->chamada());
    }

    private function recusaDaConfirmacao(RespostaDaFerramenta $proposta): ErroDaFerramenta
    {
        try {
            $this->confirmar($proposta);
        } catch (ErroDaFerramenta $e) {
            return $e;
        }
        self::fail('a confirmação deveria recusar');
    }

    /** @param array<string, mixed> $argumentos */
    private function assertRecusa(array $argumentos, string $mensagem): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_RECUSADA, $e->motivo);
            self::assertSame($mensagem, $e->getMessage());

            return;
        }
        self::fail('deveria recusar: ' . $mensagem);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function chamada(): ChamadaDoConector
    {
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $this->consentimento->getId(), 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    /** @param list<string> $permissoes */
    private function trocarPessoa(array $permissoes): void
    {
        $outra = $this->pessoa();
        $this->vinculo($outra, $this->gerenciado($this->escritorio), permissoes: $permissoes);
        $consentimento = new ConsentimentoDoConector($outra, $this->gerenciado($this->escritorio), $this->gerenciado($this->consentimento)->getCliente(), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($consentimento);
        $this->em()->flush();
        $this->pessoa = $outra;
        $this->consentimento = $consentimento;
    }

    private function contarPropostas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta');
    }

    private function pasta(): Pasta
    {
        $pasta = (new Pasta())->setNup('PRO-' . uniqid());
        $pasta->setTenant($this->gerenciado($this->escritorio));
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaAtual(Pasta $pasta): Pasta
    {
        $this->em()->clear();

        return $this->em()->find(Pasta::class, $pasta->getId()) ?? throw new \LogicException('pasta sumiu');
    }

    private function marcador(string $nome): Marcador
    {
        $marcador = new Marcador($nome, $this->gerenciado($this->escritorio), $this->gerenciado($this->pessoa));
        $this->em()->persist($marcador);
        $this->em()->flush();

        return $marcador;
    }

    /** @return list<int> os ids dos marcadores da pasta, em ordem */
    private function marcadoresDa(Pasta $pasta): array
    {
        $ids = array_map(static fn (Marcador $m): int => (int) $m->getId(), $this->pastaAtual($pasta)->getMarcadores()->toArray());

        return $this->ordenados($ids);
    }

    /**
     * @param array<int|null> $ids
     *
     * @return list<int>
     */
    private function ordenados(array $ids): array
    {
        $ids = array_values(array_map('intval', $ids));
        sort($ids);

        return $ids;
    }

    /** @return list<PastaReu> */
    private function reus(Pasta $pasta): array
    {
        $this->em()->clear();

        return static::getContainer()->get(PastaReuRepository::class)->daPasta($this->gerenciado($pasta), $this->gerenciado($this->escritorio));
    }

    /**
     * A entidade presa ao EntityManager de agora (o `pastaAtual()` limpa, e o `services_resetter` pode trocá-lo).
     *
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function gerenciado(object $entidade): object
    {
        if ($this->em()->contains($entidade)) {
            return $entidade;
        }

        return $this->em()->find($entidade::class, $entidade->getId()) ?? throw new \LogicException('entidade sumiu');
    }
}
