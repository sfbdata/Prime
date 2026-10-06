<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Cliente\Entity\ClientePF;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\ContextoDaPasta;
use App\Inteligencia\DTO\SecaoDeContexto;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\SecaoDoContexto;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPagamento;
use App\Processo\Entity\Processo;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * O que sai do escritório quando um agente é acionado — e o que NÃO sai: documentos/contatos do
 * cliente, financeiro sem visibilidade, PII, conteúdo de arquivo, processo sigiloso. O corte por
 * limite é declarado, nunca silencioso.
 */
#[CoversClass(MontadorDeContextoDaPasta::class)]
#[CoversClass(ContextoDaPasta::class)]
#[CoversClass(SecaoDeContexto::class)]
final class MontadorDeContextoDaPastaTest extends TestCase
{
    private const CPF = '123.456.789-09';

    private Tenant $tenant;
    private User $user;
    private Pasta $pasta;
    private Processo $processo;
    private FonteDeMovimentacoesFalsa $movimentacoes;
    private FonteDeDadosDaPastaFalsa $dados;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private ConfiguracaoDeInteligencia $configuracao;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->user = new User();
        $this->user->setFullName('Dra. Ana');
        DefineId::em($this->user, 9);

        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso('07011345720258070007');
        $this->processo->setClasseProcessual('Procedimento Comum');
        $this->processo->setSiglaTribunal('TJDFT');
        DefineId::em($this->processo, 3);

        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('1234');
        $this->pasta->setNomeAcao('Cobrança');
        $this->pasta->setResponsavel($this->user);
        $this->pasta->vincularProcesso($this->processo);
        DefineId::em($this->pasta, 7);

        $this->movimentacoes = new FonteDeMovimentacoesFalsa();
        $this->movimentacoes->publicacoes = [$this->publicacao(11, 'Intime-se o autor, CPF ' . self::CPF . ', para réplica.')];
        $this->movimentacoes->equipe = ['Dra. Ana', 'Dr. Bruno'];

        $this->dados = new FonteDeDadosDaPastaFalsa();
        $this->dados->clientes = [$this->cliente('João da Silva')];
        $this->dados->tarefas = [$this->tarefa('Protocolar réplica', '+3 days', 'Ligar para (61) 99999-1234 antes.')];
        $this->dados->anotacoes = [$this->anotacao('<p>Cliente ligou, informou CPF ' . self::CPF . '.</p>')];
        $this->dados->observacoes = [$this->observacao('Atendimento presencial em 01/10.')];
        $this->dados->documentos = [$this->documento('Procuração', 'PROCURACAO', 'proc.pdf')];
        $this->dados->checklist = [$this->checklist('Procuração assinada', true), $this->checklist('Comprovante de residência', false)];
        $this->dados->pagamentos = [$this->pagamento('Honorários iniciais', '1500.00')];
        $this->dados->observacoesFinanceiras = [$this->observacaoFinanceira('Parcelado em 3x.')];

        $this->configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $this->configuracao->atualizar(true, 50, 500, true, $this->user);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->configuracoes->method('findDoTenant')->willReturnCallback(fn (): ConfiguracaoDeInteligencia => $this->configuracao);
    }

    // -----------------------------------------------------------------------------------------
    // Fixtures em memória
    // -----------------------------------------------------------------------------------------

    private function publicacao(int $id, string $texto): PublicacaoDjen
    {
        $p = new PublicacaoDjen();
        $p->setTenant($this->tenant);
        $p->setDjenId((string) $id);
        $p->setNumeroProcesso('07011345720258070007');
        $p->setSiglaTribunal('TJDFT');
        $p->setTipoComunicacao('Intimação');
        $p->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-01'));
        $p->setTexto($texto);
        DefineId::em($p, $id);

        return $p;
    }

    private function cliente(string $nome): ClientePF
    {
        $c = new ClientePF();
        $c->setTenant($this->tenant);
        $c->setNomeCompleto($nome);
        $c->setCpf(self::CPF);
        $c->setRg('12.345.678-9');
        $c->setRgOrgaoExpedidor('SSP');
        $c->setEmail('joao@exemplo.com');
        $c->setTelefoneCelular('(61) 98888-7777');
        $c->setCep('70000-000');
        $c->setEndereco('Rua A, 1');
        $c->setCidade('Brasília');
        $c->setEstado('DF');
        DefineId::em($c, 5);
        $this->pasta->addCliente($c);

        return $c;
    }

    private function tarefa(string $titulo, string $prazo, string $descricao = '', string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $t = new Tarefa();
        $t->setTenant($this->tenant);
        $t->setPasta($this->pasta);
        $t->setTitulo($titulo);
        $t->setDescricao($descricao);
        $t->setPrazo(new \DateTimeImmutable($prazo));
        $t->setStatus($status);
        $t->addResponsavel($this->user);

        return $t;
    }

    private function anotacao(string $conteudo): PastaMensagem
    {
        $m = new PastaMensagem();
        $m->setTenant($this->tenant);
        $m->setPasta($this->pasta);
        $m->setAutor($this->user);
        $m->setConteudo($conteudo);

        return $m;
    }

    private function observacao(string $conteudo): PastaObservacaoDetalhes
    {
        $o = new PastaObservacaoDetalhes();
        $o->setTenant($this->tenant);
        $o->setPasta($this->pasta);
        $o->setAutor($this->user);
        $o->setConteudo($conteudo);

        return $o;
    }

    private function observacaoFinanceira(string $conteudo): PastaObservacaoFinanceira
    {
        $o = new PastaObservacaoFinanceira();
        $o->setTenant($this->tenant);
        $o->setPasta($this->pasta);
        $o->setAutor($this->user);
        $o->setConteudo($conteudo);

        return $o;
    }

    private function documento(string $titulo, string $categoria, string $nomeOriginal): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setTenant($this->tenant);
        $d->setPasta($this->pasta);
        $d->setTitulo($titulo);
        $d->setCategoria($categoria);
        $d->setCaminhoArquivo('x/' . $nomeOriginal);
        $d->setNomeOriginal($nomeOriginal);
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(10);

        return $d;
    }

    private function checklist(string $titulo, bool $concluido): PastaChecklistItem
    {
        $i = new PastaChecklistItem();
        $i->setTenant($this->tenant);
        $i->setPasta($this->pasta);
        $i->setTitulo($titulo);
        $i->setConcluido($concluido);

        return $i;
    }

    private function pagamento(string $descricao, string $valor): PastaPagamento
    {
        $p = new PastaPagamento();
        $p->setTenant($this->tenant);
        $p->setPasta($this->pasta);
        $p->setDescricao($descricao);
        $p->setValor($valor);
        $p->setVencimento(new \DateTimeImmutable('2026-10-20'));

        return $p;
    }

    /** Relógio fixo em "hoje" (os prazos das fixtures são relativos ao dia real). */
    private function sut(?ClockInterface $relogio = null): MontadorDeContextoDaPasta
    {
        $mascarador = new MascaradorDeDadosPessoais();

        return new MontadorDeContextoDaPasta(
            $this->dados,
            $this->movimentacoes,
            new MontadorDeContextoDoPush($this->movimentacoes, $this->configuracoes, $mascarador),
            $this->configuracoes,
            $mascarador,
            $relogio ?? new MockClock(new \DateTimeImmutable('today')),
        );
    }

    /** @return list<string> */
    private static function chaves(ContextoDaPasta $contexto): array
    {
        return array_map(static fn (SecaoDeContexto $s): string => $s->secao->value, $contexto->secoes);
    }

    private static function secao(ContextoDaPasta $contexto, SecaoDoContexto $qual): SecaoDeContexto
    {
        foreach ($contexto->secoes as $secao) {
            if ($secao->secao === $qual) {
                return $secao;
            }
        }
        self::fail('seção ' . $qual->value . ' não montada');
    }

    private static function textoDe(ContextoDaPasta $contexto): string
    {
        $linhas = array_merge(array_values($contexto->cabecalho), $contexto->processos);
        foreach ($contexto->secoes as $secao) {
            $linhas = array_merge($linhas, $secao->linhas);
        }

        return implode("\n", $linhas);
    }

    // -----------------------------------------------------------------------------------------

    #[TestDox('o Gestor com financeiro liberado recebe as oito seções, na ordem, mais cabeçalho e processos')]
    public function testGestorLeTudo(): void
    {
        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);

        self::assertSame(
            ['clientes', 'movimentacoes', 'metas', 'anotacoes', 'observacoes', 'documentos', 'checklist', 'financeiro'],
            self::chaves($contexto),
        );
        self::assertSame('1234', $contexto->cabecalho['pasta']);
        self::assertSame('ativa', $contexto->cabecalho['situacao']);
        self::assertSame('Dra. Ana', $contexto->cabecalho['responsavel']);
        self::assertSame('Dra. Ana, Dr. Bruno', $contexto->cabecalho['equipe']);
        // Setters gravam em maiúsculas: compara com o getter.
        self::assertSame($this->pasta->getNomeAcao(), $contexto->cabecalho['acao']);
        self::assertCount(1, $contexto->processos);
        self::assertStringContainsString('07011345720258070007', $contexto->processos[0]);
        self::assertStringContainsString($this->processo->getClasseProcessual(), $contexto->processos[0]);
        self::assertSame(['07011345720258070007'], $contexto->numerosDosProcessos);
        self::assertTrue($contexto->incluiFinanceiro);
        self::assertFalse($contexto->vazio());
        self::assertSame(Agente::Gestor, $contexto->agente);
    }

    #[TestDox('Processual e Documental recebem só as suas seções — o Documental não lê movimentação nenhuma')]
    public function testCadaAgenteSoAsSuasSecoes(): void
    {
        $processual = $this->sut()->para($this->tenant, $this->pasta, Agente::Processual, true);
        self::assertSame(['movimentacoes', 'metas'], self::chaves($processual));

        $documental = $this->sut()->para($this->tenant, $this->pasta, Agente::Documental, true);
        self::assertSame(['documentos', 'checklist'], self::chaves($documental));
        $linha = self::secao($documental, SecaoDoContexto::Documentos)->linhas[0];
        self::assertStringContainsString($this->dados->documentos[0]->getTitulo(), $linha);
        self::assertStringContainsString('PROCURACAO', $linha);
        self::assertStringContainsString('proc.pdf', $linha);
        self::assertStringContainsString('conteúdo não lido', $linha);
        self::assertStringNotContainsString('réplica', self::textoDe($documental), 'movimentação não entra no Documental');
        self::assertFalse($documental->incluiFinanceiro, 'o Documental nem lê financeiro');
    }

    #[TestDox('financeiro NÃO permitido: a seção não é montada, nada financeiro sai e o resumo registra a decisão')]
    public function testFinanceiroSemVisibilidadeNaoEntra(): void
    {
        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, false);

        self::assertNotContains('financeiro', self::chaves($contexto));
        self::assertFalse($contexto->incluiFinanceiro);
        self::assertFalse($contexto->resumo()['financeiro']);
        $texto = self::textoDe($contexto);
        self::assertStringNotContainsString('Honorários', $texto);
        self::assertStringNotContainsString('1500', $texto);
        self::assertStringNotContainsString('Parcelado', $texto);
    }

    #[TestDox('financeiro permitido: contrato, valor da causa, pagamento e observação financeira entram')]
    public function testFinanceiroComVisibilidadeEntra(): void
    {
        $this->pasta->setValorCausa('25000.00');
        $this->pasta->setSituacaoContrato('pendente');

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);
        $linhas = self::secao($contexto, SecaoDoContexto::Financeiro)->linhas;

        self::assertStringContainsString('situação do contrato: pendente', $linhas[0]);
        self::assertStringContainsString('valor da causa: R$ 25000.00', $linhas[0]);
        self::assertStringContainsString('pró-bono: não', $linhas[0]);
        self::assertStringContainsString('Honorários iniciais', $linhas[1]);
        self::assertStringContainsString('R$ 1500.00', $linhas[1]);
        self::assertStringContainsString('vencimento 20/10/2026', $linhas[1]);
        self::assertStringContainsString('EM ABERTO', $linhas[1]);
        self::assertStringContainsString('Parcelado em 3x.', $linhas[2]);
    }

    #[TestDox('cliente: sai o nome e a natureza, NUNCA CPF, e-mail, telefone ou endereço')]
    public function testClienteSemDocumentoNemContato(): void
    {
        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Cliente, true);
        $linha = self::secao($contexto, SecaoDoContexto::Clientes)->linhas[0];

        // O setter pode normalizar o nome: compara com o getter.
        self::assertStringContainsString($this->dados->clientes[0]->getNomeExibicao(), $linha);
        self::assertStringContainsString('pessoa física', $linha);
        self::assertStringContainsString('cliente principal', $linha);
        $texto = self::textoDe($contexto);
        self::assertStringNotContainsString(self::CPF, $texto);
        self::assertStringNotContainsString('joao@exemplo.com', $texto);
        self::assertStringNotContainsString('98888-7777', $texto);
        self::assertStringNotContainsString('Rua A', $texto);
    }

    #[TestDox('PII mascarada em todo texto livre (anotação, meta, movimentação) e HTML removido')]
    public function testPiiMascaradaEHtmlRemovido(): void
    {
        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);
        $texto = self::textoDe($contexto);

        self::assertStringNotContainsString(self::CPF, $texto);
        self::assertStringNotContainsString('99999-1234', $texto);
        self::assertStringContainsString('[CPF]', $texto);
        self::assertStringContainsString('[TEL]', $texto);
        self::assertStringNotContainsString('<p>', $texto);
        $anotacao = self::secao($contexto, SecaoDoContexto::Anotacoes)->linhas[0];
        self::assertStringContainsString('Dra. Ana: Cliente ligou, informou CPF [CPF].', $anotacao);
        self::assertStringContainsString('[nível 9', $anotacao);
    }

    #[TestDox('escritório com mascaramento desligado: o CPF da anotação sai como está')]
    public function testMascaramentoDesligadoRespeitaAConfiguracao(): void
    {
        $this->configuracao->atualizar(true, 50, 500, false, $this->user);

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);

        self::assertStringContainsString(self::CPF, self::secao($contexto, SecaoDoContexto::Anotacoes)->linhas[0]);
    }

    #[TestDox('metas: prazo, responsável, situação com "vence em N dia(s)" / "ATRASADA"')]
    public function testMetas(): void
    {
        $this->dados->tarefas[] = $this->tarefa('Juntar procuração', '-2 days');
        $this->dados->tarefas[] = $this->tarefa('Feita', '-10 days', '', Tarefa::STATUS_CONCLUIDA);

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Prazos, true);
        $linhas = self::secao($contexto, SecaoDoContexto::Metas)->linhas;

        self::assertCount(3, $linhas);
        self::assertStringContainsString('"Protocolar réplica"', $linhas[0]);
        self::assertStringContainsString('responsáveis: Dra. Ana', $linhas[0]);
        self::assertStringContainsString('Pendente, vence em 3 dia(s)', $linhas[0]);
        self::assertStringContainsString('Pendente, ATRASADA 2 dia(s)', $linhas[1]);
        self::assertStringContainsString('Concluída', $linhas[2]);
        self::assertStringNotContainsString('ATRASADA', $linhas[2], 'concluída não atrasa');
    }

    #[TestDox('checklist: [x] concluído / [ ] pendente')]
    public function testChecklist(): void
    {
        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Documental, true);
        $linhas = self::secao($contexto, SecaoDoContexto::Checklist)->linhas;

        self::assertStringStartsWith('[x] concluído: ' . $this->dados->checklist[0]->getTitulo(), $linhas[0]);
        self::assertStringStartsWith('[ ] pendente: ' . $this->dados->checklist[1]->getTitulo(), $linhas[1]);
    }

    #[TestDox('processo em segredo de justiça bloqueia ANTES de ler qualquer seção — até para quem não lê movimentações')]
    public function testSigiloBloqueiaMesmoSemMovimentacoes(): void
    {
        $this->processo->setNivelSigilo(1);

        $this->expectException(ContextoBloqueadoException::class);

        $this->sut()->para($this->tenant, $this->pasta, Agente::Documental, true);
    }

    #[TestDox('processo de OUTRO escritório vinculado à pasta é ignorado em silêncio')]
    public function testProcessoDeOutroTenantNaoEntra(): void
    {
        $outroTenant = new Tenant();
        DefineId::em($outroTenant, 99);
        $alheio = new Processo();
        $alheio->setTenant($outroTenant);
        $alheio->setNumeroProcesso('07099999999999999999');
        $alheio->setNivelSigilo(1); // se fosse lido, bloquearia
        DefineId::em($alheio, 4);
        $this->pasta->vincularProcesso($alheio);

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);

        self::assertSame(['07011345720258070007'], $contexto->numerosDosProcessos);
        self::assertStringNotContainsString('07099999999999999999', self::textoDe($contexto));
    }

    #[TestDox('acima do limite da seção o corte é DECLARADO: contagem de omitidos na seção e no resumo')]
    public function testCorteDeclaradoPorSecao(): void
    {
        $this->dados->documentos = [];
        for ($i = 1; $i <= MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS + 3; ++$i) {
            $this->dados->documentos[] = $this->documento('Doc ' . $i, 'DEMAIS', 'doc' . $i . '.pdf');
        }

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Documental, true);
        $secao = self::secao($contexto, SecaoDoContexto::Documentos);

        self::assertSame(MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS, $secao->total());
        // A fonte é consultada com limite + 1: o montador sabe que sobrou, não quantos exatamente.
        self::assertSame(1, $secao->omitidas);
        self::assertSame(['documentos' => 1], $contexto->resumo()['omitidas']);
        self::assertSame(MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS + 2, $contexto->resumo()['total']);
    }

    #[TestDox('o orçamento total corta as seções mais ao fim e declara cada linha omitida')]
    public function testOrcamentoTotalDeclaraOCorte(): void
    {
        $this->movimentacoes->publicacoes = [];
        for ($i = 1; $i <= MontadorDeContextoDaPasta::LIMITE_MOVIMENTACOES; ++$i) {
            $this->movimentacoes->publicacoes[] = $this->publicacao(100 + $i, str_repeat('x', MontadorDeContextoDoPush::TAMANHO_MAXIMO_DO_ITEM));
        }

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Processual, true);
        $movs = self::secao($contexto, SecaoDoContexto::Movimentacoes);

        $gasto = 0;
        foreach ($contexto->secoes as $secao) {
            foreach ($secao->linhas as $linha) {
                $gasto += mb_strlen($linha);
            }
        }
        self::assertLessThanOrEqual(MontadorDeContextoDaPasta::ORCAMENTO_TOTAL + MontadorDeContextoDoPush::TAMANHO_MAXIMO_DO_ITEM, $gasto);
        self::assertLessThan(MontadorDeContextoDaPasta::LIMITE_MOVIMENTACOES, $movs->total());
        self::assertGreaterThan(0, $movs->omitidas);
        self::assertArrayHasKey('movimentacoes', $contexto->resumo()['omitidas']);
    }

    #[TestDox('hash: igual para o mesmo contexto, diferente quando uma anotação muda ou o agente muda')]
    public function testHash(): void
    {
        $a = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);
        $b = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);
        self::assertSame($a->hash, $b->hash);

        $outroAgente = $this->sut()->para($this->tenant, $this->pasta, Agente::Processual, true);
        self::assertNotSame($a->hash, $outroAgente->hash);

        $this->dados->anotacoes[] = $this->anotacao('Nova anotação.');
        $c = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);
        self::assertNotSame($a->hash, $c->hash);
    }

    #[TestDox('pasta sem nada nas seções do agente → contexto vazio (o cabeçalho sozinho não é análise)')]
    public function testVazio(): void
    {
        $this->dados->documentos = [];
        $this->dados->checklist = [];

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Documental, true);

        self::assertTrue($contexto->vazio());
        self::assertSame(0, $contexto->totalDeItens());
        self::assertNotSame('', $contexto->cabecalho['pasta'], 'o cabeçalho existe mesmo assim');
    }

    /** @return iterable<string, array{Agente}> */
    public static function agentesQueLeemFinanceiro(): iterable
    {
        yield 'gestor' => [Agente::Gestor];
        yield 'relatorios' => [Agente::Relatorios];
        yield 'cliente' => [Agente::Cliente];
    }

    #[DataProvider('agentesQueLeemFinanceiro')]
    #[TestDox('M1: pasta vazia (contrato PENDENTE padrão, sem valor da causa, sem pagamento) é contexto VAZIO para $_dataName, mesmo com financeiro liberado')]
    public function testPastaVaziaEhVaziaMesmoComFinanceiro(Agente $agente): void
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        $pasta->setNup('77');
        DefineId::em($pasta, 77);
        $this->movimentacoes->publicacoes = [];
        $this->dados = new FonteDeDadosDaPastaFalsa();

        $contexto = $this->sut()->para($this->tenant, $pasta, $agente, true);

        self::assertTrue($contexto->vazio(), 'o cadastro padrão do financeiro não é dado');
        self::assertSame([], self::secao($contexto, SecaoDoContexto::Financeiro)->linhas);
        self::assertSame(0, $contexto->resumo()['total']);
    }

    #[TestDox('valor da causa (ou pró-bono, ou pagamento) informado → a linha de cadastro do financeiro existe')]
    public function testCadastroDoFinanceiroEntraQuandoHaDado(): void
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        $pasta->setNup('78');
        DefineId::em($pasta, 78);
        $this->movimentacoes->publicacoes = [];
        $this->dados = new FonteDeDadosDaPastaFalsa();

        $pasta->setValorCausa('100.00');
        $comValor = $this->sut()->para($this->tenant, $pasta, Agente::Gestor, true);
        self::assertFalse($comValor->vazio());
        self::assertStringContainsString('valor da causa: R$ 100.00', self::secao($comValor, SecaoDoContexto::Financeiro)->linhas[0]);

        $pasta->setValorCausa(null);
        $pasta->setProBono(true);
        $proBono = $this->sut()->para($this->tenant, $pasta, Agente::Gestor, true);
        self::assertStringContainsString('pró-bono: sim', self::secao($proBono, SecaoDoContexto::Financeiro)->linhas[0]);
    }

    #[TestDox('M2: o MESMO dado em dois dias diferentes dá o MESMO hash — só o texto relativo do prompt muda')]
    public function testHashEstavelEntreDias(): void
    {
        $this->dados->tarefas = [$this->tarefa('Protocolar réplica', '2026-10-20'), $this->tarefa('Antiga', '2026-09-01')];

        $dia1 = $this->sut(new MockClock(new \DateTimeImmutable('2026-10-05')))->para($this->tenant, $this->pasta, Agente::Gestor, true);
        $dia2 = $this->sut(new MockClock(new \DateTimeImmutable('2026-10-12')))->para($this->tenant, $this->pasta, Agente::Gestor, true);

        $metas1 = self::secao($dia1, SecaoDoContexto::Metas)->linhas;
        $metas2 = self::secao($dia2, SecaoDoContexto::Metas)->linhas;
        self::assertStringContainsString('vence em 15 dia(s)', $metas1[0]);
        self::assertStringContainsString('vence em 8 dia(s)', $metas2[0]);
        self::assertStringContainsString('ATRASADA 34 dia(s)', $metas1[1]);
        self::assertStringContainsString('ATRASADA 41 dia(s)', $metas2[1]);
        self::assertNotSame($metas1, $metas2, 'o prompt muda com o dia…');
        self::assertSame($dia1->hash, $dia2->hash, '…o hash não');
        self::assertSame(self::secao($dia1, SecaoDoContexto::Metas)->assinaturas, self::secao($dia2, SecaoDoContexto::Metas)->assinaturas);
        self::assertStringContainsString(':2026-10-20:', self::secao($dia1, SecaoDoContexto::Metas)->assinaturas[0], 'a assinatura leva a data absoluta');
    }

    #[TestDox('M2: a equipe do escritório mudar não muda o hash; o responsável da pasta mudar, muda')]
    public function testHashIgnoraEquipeMasNaoOResponsavel(): void
    {
        $antes = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);

        $this->movimentacoes->equipe = ['Dra. Ana', 'Dr. Bruno', 'Dra. Carla'];
        $comEquipeNova = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);
        self::assertNotSame($antes->cabecalho['equipe'], $comEquipeNova->cabecalho['equipe']);
        self::assertSame($antes->hash, $comEquipeNova->hash);

        $this->pasta->setResponsavel(null);
        $semResponsavel = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);
        self::assertNotSame($antes->hash, $semResponsavel->hash);
    }

    #[TestDox('M2: toda linha tem a sua assinatura (uma por linha, inclusive depois do corte de orçamento)')]
    public function testAssinaturasUmaPorLinha(): void
    {
        $this->movimentacoes->publicacoes = [];
        for ($i = 1; $i <= MontadorDeContextoDaPasta::LIMITE_MOVIMENTACOES; ++$i) {
            $this->movimentacoes->publicacoes[] = $this->publicacao(100 + $i, str_repeat('x', MontadorDeContextoDoPush::TAMANHO_MAXIMO_DO_ITEM));
        }

        $contexto = $this->sut()->para($this->tenant, $this->pasta, Agente::Gestor, true);

        foreach ($contexto->secoes as $secao) {
            self::assertCount(count($secao->linhas), $secao->assinaturas, $secao->secao->value);
        }
        self::assertMatchesRegularExpression('/^pub:\d+:x/', self::secao($contexto, SecaoDoContexto::Movimentacoes)->assinaturas[0]);
        self::assertStringStartsWith('cliente:5:', self::secao($contexto, SecaoDoContexto::Clientes)->assinaturas[0]);
    }

    #[TestDox('sem responsável e sem processo o cabeçalho diz isso em vez de inventar')]
    public function testCabecalhoHonestoSemResponsavelNemProcesso(): void
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        $pasta->setNup('9');
        DefineId::em($pasta, 8);

        $contexto = $this->sut()->para($this->tenant, $pasta, Agente::Gestor, true);

        self::assertSame('SEM RESPONSÁVEL', $contexto->cabecalho['responsavel']);
        self::assertSame('não informada', $contexto->cabecalho['acao']);
        self::assertSame([], $contexto->processos);
        self::assertSame([], $contexto->numerosDosProcessos);
    }

    #[TestDox('o resumo gravado tem só contagens: agente, seções, omitidas, total, processos, financeiro')]
    public function testResumoSoContagens(): void
    {
        $resumo = $this->sut()->para($this->tenant, $this->pasta, Agente::Relatorios, true)->resumo();

        self::assertSame(['agente', 'secoes', 'omitidas', 'total', 'processos', 'financeiro'], array_keys($resumo));
        self::assertSame('relatorios', $resumo['agente']);
        self::assertSame(['clientes' => 1, 'movimentacoes' => 1, 'metas' => 1, 'observacoes' => 1, 'financeiro' => 3], $resumo['secoes']);
        self::assertSame(7, $resumo['total']);
        self::assertStringNotContainsString('réplica', (string) json_encode($resumo), 'nenhum texto no resumo (D5)');
    }
}
