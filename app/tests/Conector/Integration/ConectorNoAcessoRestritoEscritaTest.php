<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarPastaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\DocumentosEPecasFerramenta;
use App\Conector\Ferramenta\FerramentaDoConector;
use App\Conector\Ferramenta\FinanceiroDaPastaFerramenta;
use App\Conector\Ferramenta\LerClientesFerramenta;
use App\Conector\Ferramenta\LerDocumentosEModelosFerramenta;
use App\Conector\Ferramenta\GravarNaAgendaFerramenta;
use App\Conector\Ferramenta\LerPastaFerramenta;
use App\Conector\Ferramenta\LerProcessosFerramenta;
use App\Conector\Ferramenta\ProcessosDaPastaFerramenta;
use App\Conector\Ferramenta\MudarAndamentoFerramenta;
use App\Conector\Ferramenta\PrazosEMetasDaPastaFerramenta;
use App\Conector\Ferramenta\RegistrarNaPastaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaSecao;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Acesso restrito (spec acesso-restrito-e-compartilhar, fatia 3) nas ferramentas novas do conector: leitura de
 * clientes, escrita na pasta, documentos, financeiro e a confirmação. Pasta fora do escopo (nem responsável nem
 * compartilhada) não existe para o restrito: "não encontrado", a mesma resposta de inexistente; id secundário que
 * aponte para ela também; e o restrito não troca o responsável.
 */
#[CoversClass(GuardaDaAcaoDoConector::class)]
final class ConectorNoAcessoRestritoEscritaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const PERMISSOES = [
        'modules.expediente.view', 'modules.pastas.view', 'modules.processos.view', 'modules.clientes.view',
        'resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view', 'resources.processo.edit', 'resources.cliente.view',
        'modules.agenda.view', 'modules.agenda.edit', 'modules.tarefas.view',
    ];

    private Tenant $escritorio;
    private User $restrito;
    private User $comum;
    private Pasta $minha;
    private Pasta $alheia;
    private ClienteDoConector $app;

    /** @var array<int, int> o id do consentimento de cada pessoa */
    private array $consentimentos = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->restrito = $this->pessoa();
        $this->vinculo($this->restrito, $this->escritorio, permissoes: self::PERMISSOES)->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $this->comum = $this->pessoa();
        $this->vinculo($this->comum, $this->escritorio, permissoes: self::PERMISSOES);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->ligar($this->restrito, new \DateTimeImmutable());
        $doConector->ligar($this->comum, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $this->app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($this->app);
        foreach ([$this->restrito, $this->comum] as $pessoa) {
            $consentimento = new ConsentimentoDoConector($pessoa, $this->escritorio, $this->app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
            $this->em()->persist($consentimento);
            $this->em()->flush();
            $this->consentimentos[(int) $pessoa->getId()] = (int) $consentimento->getId();
        }
        $this->minha = $this->pasta($this->restrito);
        $this->alheia = $this->pasta($this->comum);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('ler_clientes: cliente só de pasta alheia ou sem pasta não existe para o restrito; o misto só mostra a pasta dele')]
    public function testLerClientes(): void
    {
        $meu = $this->cliente($this->minha);
        $alheio = $this->cliente($this->alheia);
        $semPasta = $this->cliente(null);
        $misto = $this->cliente($this->minha);
        $this->g($this->alheia)->addCliente($misto);
        $this->em()->flush();

        self::assertSame($meu->getId(), $this->ler($meu)->dados['cliente']['id']);
        $this->assertNaoEncontrado(LerClientesFerramenta::class, ['acao' => 'ler', 'cliente_id' => $alheio->getId()]);
        $this->assertNaoEncontrado(LerClientesFerramenta::class, ['acao' => 'ler', 'cliente_id' => $semPasta->getId()]);
        $this->assertNaoEncontrado(LerClientesFerramenta::class, ['acao' => 'documentos_de_identidade', 'cliente_id' => $alheio->getId()]);
        $resposta = $this->ler($misto);
        self::assertSame([$this->minha->getId()], array_column($resposta->dados['pastas'], 'pasta_id'));
        self::assertSame([$this->minha->getId()], $resposta->ids['pasta']);
        self::assertStringNotContainsString((string) $this->alheia->getNup(), json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        self::assertSame($alheio->getId(), $this->chamar(LerClientesFerramenta::class, ['acao' => 'ler', 'cliente_id' => $alheio->getId()], $this->comum)->dados['cliente']['id'], 'o comum vê');
    }

    #[TestDox('ler_clientes conferir_documento: o CPF de cliente alheio não confirma que o cadastro existe')]
    public function testConferirDocumento(): void
    {
        $alheio = $this->cliente($this->alheia)->setCpf('529.982.247-25');
        $this->em()->flush();

        $resposta = $this->chamar(LerClientesFerramenta::class, ['acao' => 'conferir_documento', 'documento' => '52998224725'], $this->restrito);

        self::assertStringNotContainsString((string) $alheio->getId(), json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('MARIA', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('mudar_andamento, atualizar_pasta, prazos e registrar: pasta alheia é não encontrado, igual à inexistente')]
    public function testEscritaNaPastaAlheia(): void
    {
        $id = $this->alheia->getId();
        $pedidos = [
            [MudarAndamentoFerramenta::class, ['acao' => 'mudar_fase', 'pasta_id' => $id, 'fase' => 'Contrato']],
            [MudarAndamentoFerramenta::class, ['acao' => 'nomear_etapa', 'pasta_id' => $id, 'fase_de_inicio' => 1, 'texto' => 'Etapa']],
            [AtualizarPastaFerramenta::class, ['acao' => 'situacao', 'pasta_id' => $id, 'situacao' => Pasta::SITUACAO_ARQUIVADA]],
            [AtualizarPastaFerramenta::class, ['acao' => 'prioridade', 'pasta_id' => $id, 'prioridade' => 'urgente']],
            [PrazosEMetasDaPastaFerramenta::class, ['acao' => 'salvar_prazo', 'pasta_id' => $id, 'tipo' => 'prazo', 'texto' => 'X', 'data' => '2026-12-04']],
            [RegistrarNaPastaFerramenta::class, ['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $id, 'texto' => 'Oi']],
            [RegistrarNaPastaFerramenta::class, ['lugar' => 'relatorio_financeiro', 'acao' => 'escrever', 'pasta_id' => $id, 'texto' => 'Oi']],
        ];

        foreach ($pedidos as [$ferramenta, $argumentos]) {
            $this->assertNaoEncontrado($ferramenta, $argumentos, $ferramenta . ' ' . ($argumentos['acao'] ?? ''));
        }
    }

    #[TestDox('metas pelo conector (módulo Tarefas, travado): criar e analisar meta recusam por permissão, na pasta alheia e na própria')]
    public function testMetasFicamTravadas(): void
    {
        foreach ([$this->alheia, $this->minha] as $pasta) {
            $meta = ['acao' => 'criar_meta', 'pasta_id' => $pasta->getId(), 'titulo' => 'X', 'descricao' => 'Y', 'prazo' => '2026-12-04', 'etapa' => 'em_andamento', 'responsaveis' => [$this->restrito->getId()]];
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa(PrazosEMetasDaPastaFerramenta::class, $meta)->motivo);
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa(PrazosEMetasDaPastaFerramenta::class, ['acao' => 'analisar_prazo_da_meta', 'pasta_id' => $pasta->getId(), 'titulo' => 'Contestar']) ->motivo);
        }
    }

    #[TestDox('prazo, registro e subpasta de pasta alheia não valem usados numa pasta do escopo')]
    public function testIdSecundarioDeFora(): void
    {
        $prazoAlheio = $this->prazo($this->alheia, $this->comum);
        $registroAlheio = $this->registro($this->alheia, $this->comum);
        $subpastaAlheia = $this->subpasta($this->alheia);
        $minha = $this->minha->getId();

        $this->assertNaoEncontrado(PrazosEMetasDaPastaFerramenta::class, ['acao' => 'cumprir_prazo', 'pasta_id' => $minha, 'prazo_id' => $prazoAlheio->getId()]);
        $this->assertNaoEncontrado(PrazosEMetasDaPastaFerramenta::class, ['acao' => 'salvar_prazo', 'pasta_id' => $minha, 'prazo_id' => $prazoAlheio->getId(), 'texto' => 'Novo']);
        foreach (['responder' => ['texto' => 'X'], 'editar' => ['texto' => 'X'], 'excluir' => [], 'destacar' => []] as $acao => $extra) {
            $this->assertNaoEncontrado(RegistrarNaPastaFerramenta::class, ['lugar' => 'registro', 'acao' => $acao, 'pasta_id' => $minha, 'registro_id' => $registroAlheio->getId()] + $extra, $acao);
        }
        $this->assertNaoEncontrado(DocumentosEPecasFerramenta::class, ['acao' => 'mover', 'operacao' => 'mover', 'pasta_id' => $minha, 'subpastas' => [$subpastaAlheia->getId()]]);
        $this->assertNaoEncontrado(DocumentosEPecasFerramenta::class, ['acao' => 'copiar', 'operacao' => 'copiar', 'pasta_id' => $minha, 'documentos' => [1], 'destino_id' => $subpastaAlheia->getId()]);
    }

    #[TestDox('atualizar_pasta responsavel: o restrito não troca o responsável, nem da pasta dele, e a pasta fica com ele')]
    public function testRestritoNaoTrocaOResponsavel(): void
    {
        foreach ([$this->comum->getId(), null] as $novo) {
            $e = $this->recusa(AtualizarPastaFerramenta::class, ['acao' => 'responsavel', 'pasta_id' => $this->minha->getId(), 'responsavel_id' => $novo]);
            self::assertSame(ErroDaFerramenta::MOTIVO_RECUSADA, $e->motivo);
            self::assertSame(AtualizarPastaFerramenta::RESPONSAVEL_FIXO_NO_RESTRITO, $e->getMessage());
        }
        $this->assertNaoEncontrado(AtualizarPastaFerramenta::class, ['acao' => 'responsavel', 'pasta_id' => $this->alheia->getId(), 'responsavel_id' => $this->restrito->getId()]);
        $this->em()->clear();
        self::assertSame($this->restrito->getId(), $this->em()->find(Pasta::class, $this->minha->getId())->getResponsavel()?->getId());
        self::assertSame($this->comum->getId(), $this->em()->find(Pasta::class, $this->alheia->getId())->getResponsavel()?->getId());
    }

    #[TestDox('documentos_e_pecas, ler_documentos_e_modelos e financeiro_da_pasta: pasta alheia não encontrada, inclusive modelos e financeiro')]
    public function testDocumentosEFinanceiro(): void
    {
        $id = $this->alheia->getId();
        $pedidos = [
            [DocumentosEPecasFerramenta::class, ['acao' => 'usar_modelo', 'operacao' => 'usar', 'pasta_id' => $id, 'modelo_id' => 1]],
            [DocumentosEPecasFerramenta::class, ['acao' => 'checklist_modelo', 'operacao' => 'aplicar', 'pasta_id' => $id, 'modelo_checklist_id' => 1]],
            [DocumentosEPecasFerramenta::class, ['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $id, 'nome' => 'X']],
            [LerDocumentosEModelosFerramenta::class, ['acao' => 'listar', 'pasta_id' => $id]],
            [FinanceiroDaPastaFerramenta::class, ['acao' => 'valor_da_causa', 'pasta_id' => $id, 'valor' => '1000,00']],
            [FinanceiroDaPastaFerramenta::class, ['acao' => 'pro_bono', 'pasta_id' => $id, 'ligado' => true]],
        ];

        foreach ($pedidos as [$ferramenta, $argumentos]) {
            $this->assertNaoEncontrado($ferramenta, $argumentos, $ferramenta . ' ' . ($argumentos['acao'] ?? ''));
        }
    }

    #[TestDox('ler_pasta: detalhes e financeiro de pasta alheia não saem, e os ids das observações só vêm da pasta do escopo')]
    public function testLerPastaComObservacoes(): void
    {
        $this->registro($this->alheia, $this->comum);

        foreach (['detalhes', 'financeiro', 'registro', 'metas'] as $parte) {
            $this->assertNaoEncontrado(LerPastaFerramenta::class, ['pasta_id' => $this->alheia->getId(), 'partes' => [$parte]], $parte);
        }
        $json = json_encode($this->chamar(LerPastaFerramenta::class, ['pasta_id' => $this->minha->getId(), 'partes' => ['detalhes']], $this->restrito)->dados, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string) $this->alheia->getNup(), $json);
    }


    #[TestDox('processos_da_pasta: pasta alheia não encontrada; processo de fora do escopo não é ligado nem revelado, por id nem por número')]
    public function testProcessosDaPasta(): void
    {
        $deFora = $this->processo($this->alheia, 'Classe Alheia', 0);
        $semPasta = $this->processo(null, 'Sem pasta', 0);
        $meu = $this->processo($this->minha, 'Classe Minha', 0);
        $minha = $this->minha->getId();

        $this->assertNaoEncontrado(ProcessosDaPastaFerramenta::class, ['acao' => 'vincular', 'operacao' => 'cadastrado', 'pasta_id' => $this->alheia->getId(), 'processo_id' => $meu->getId()]);
        foreach ([$deFora, $semPasta] as $processo) {
            $this->assertNaoEncontrado(ProcessosDaPastaFerramenta::class, ['acao' => 'vincular', 'operacao' => 'cadastrado', 'pasta_id' => $minha, 'processo_id' => $processo->getId()]);
            $this->assertNaoEncontrado(ProcessosDaPastaFerramenta::class, ['acao' => 'vincular', 'operacao' => 'apensado', 'pasta_id' => $minha, 'processo_id' => $processo->getId(), 'tipo_apenso' => 'embargos']);
            $this->assertNaoEncontrado(ProcessosDaPastaFerramenta::class, ['acao' => 'principal', 'pasta_id' => $minha, 'processo_id' => $processo->getId()]);
            $this->assertNaoEncontrado(ProcessosDaPastaFerramenta::class, ['acao' => 'dados_do_cartao', 'pasta_id' => $minha, 'processo_id' => $processo->getId(), 'classe' => 'Nova']);
            $this->assertNaoEncontrado(ProcessosDaPastaFerramenta::class, ['acao' => 'nota_tecnica', 'operacao' => 'criar', 'pasta_id' => $minha, 'processo_id' => $processo->getId(), 'conteudo' => 'X']);
        }
        $this->assertNaoEncontrado(ProcessosDaPastaFerramenta::class, ['acao' => 'vincular', 'operacao' => 'novo', 'pasta_id' => $minha, 'numero_processo' => $deFora->getNumeroProcesso(), 'orgao_julgador' => 'X', 'classe_processual' => 'X', 'assunto_processual' => 'X']);
        self::assertSame(1, $this->em()->find(Pasta::class, $minha)->getPastaProcessos()->count());
    }

    #[TestDox('gravar_na_agenda: Agenda é módulo travado, todas as ações recusam por permissão')]
    public function testGravarNaAgenda(): void
    {
        $prazo = $this->prazo($this->minha, $this->restrito);
        $pedidos = [
            ['acao' => 'salvar', 'titulo' => 'Reunião', 'data' => '2026-12-10'],
            ['acao' => 'mover', 'compromisso_id' => 1, 'data' => '2026-12-11'],
            ['acao' => 'status', 'compromisso_id' => 1, 'status' => 'concluido'],
            ['acao' => 'avisar', 'compromisso_id' => 1, 'colegas' => [$this->comum->getId()]],
            ['acao' => 'avisar', 'prazo_id' => $prazo->getId(), 'colegas' => [$this->comum->getId()]],
        ];

        foreach ($pedidos as $pedido) {
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa(GravarNaAgendaFerramenta::class, $pedido)->motivo, $pedido['acao']);
        }
    }

    #[TestDox('ler_processos cnj: processo de fora do escopo em segredo não diz que existe, nem vaza o id')]
    public function testCnjDoProcessoDeFora(): void
    {
        $segredo = $this->processo($this->alheia, 'Segredo', 1);

        try {
            $resposta = $this->chamar(LerProcessosFerramenta::class, ['acao' => 'cnj', 'numero_processo' => $segredo->getNumeroProcesso(), 'pasta_id' => $this->minha->getId()], $this->restrito);
            self::assertStringNotContainsString((string) $segredo->getId(), json_encode($resposta->ids, \JSON_THROW_ON_ERROR) . json_encode($resposta->dados['processo_id'] ?? null, \JSON_THROW_ON_ERROR));
            self::assertNotTrue($resposta->dados['ja_no_escritorio'] ?? false);
        } catch (ErroDaFerramenta $e) {
            self::assertNotSame(ErroDaFerramenta::MOTIVO_EM_SEGREDO, $e->motivo, 'o segredo de fora não pode ser dito');
        }
    }

    #[TestDox('confirmar_acao: proposta feita antes de a pasta sair do escopo não executa depois')]
    public function testConfirmarDepoisDeSairDoEscopo(): void
    {
        $prazo = $this->prazo($this->minha, $this->restrito);
        $proposta = $this->chamar(PrazosEMetasDaPastaFerramenta::class, ['acao' => 'cumprir_prazo', 'pasta_id' => $this->minha->getId(), 'prazo_id' => $prazo->getId(), 'excluir' => true], $this->restrito);
        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);

        $this->em()->find(Pasta::class, $this->minha->getId())->setResponsavel($this->g($this->comum));
        $this->em()->flush();
        $argumentos = ['proposta_id' => $proposta->dados['proposta']['proposta_id'], 'resumo' => $proposta->dados['proposta']['resumo']];

        $this->assertNaoEncontrado(ConfirmarAcaoFerramenta::class, $argumentos);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_prazo WHERE id = ?', [$prazo->getId()]), 'o prazo não foi apagado');
    }

    /** @param array<string, mixed> $argumentos */
    private function assertNaoEncontrado(string $ferramenta, array $argumentos, string $rotulo = ''): void
    {
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $this->recusa($ferramenta, $argumentos)->motivo, $rotulo);
    }

    /** @param array<string, mixed> $argumentos */
    private function recusa(string $ferramenta, array $argumentos): ErroDaFerramenta
    {
        try {
            $this->chamar($ferramenta, $argumentos, $this->restrito);
        } catch (ErroDaFerramenta $e) {
            return $e;
        }
        self::fail('deveria recusar: ' . $ferramenta);
    }

    /**
     * @param class-string<FerramentaDoConector> $ferramenta
     * @param array<string, mixed>               $argumentos
     */
    private function chamar(string $ferramenta, array $argumentos, User $pessoa): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, (int) $this->consentimentoDe($pessoa)->getId(), 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get($ferramenta)->executar($argumentos, $chamada);
    }

    private function consentimentoDe(User $pessoa): ConsentimentoDoConector
    {
        return $this->em()->find(ConsentimentoDoConector::class, $this->consentimentos[(int) $pessoa->getId()]) ?? throw new \LogicException('consentimento sumiu');
    }

    /** A entidade de novo no EntityManager (o `services_resetter` solta as antigas a cada chamada). */
    private function g(object $entidade): object
    {
        return $this->em()->contains($entidade) ? $entidade : ($this->em()->find($entidade::class, $entidade->getId()) ?? throw new \LogicException('entidade sumiu'));
    }

    private function ler(Cliente $cliente): RespostaDaFerramenta
    {
        return $this->chamar(LerClientesFerramenta::class, ['acao' => 'ler', 'cliente_id' => $cliente->getId()], $this->restrito);
    }

    private function cliente(?Pasta $pasta): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto('Maria Alheia')->setCpf('111.444.777-35')->setRg('7654321')->setRgOrgaoExpedidor('')->setDataNascimento(new \DateTimeImmutable('1985-03-12'))->setEmail('maria@exemplo.com')->setTelefoneCelular('(61) 98765-4321')->setWhatsapp('61912345678')->setCep('70390-100')->setEndereco('Rua A')->setNumero('1')->setCidade('Brasília')->setEstado('DF')->setTenant($this->g($this->escritorio));
        $this->em()->persist($cliente);
        if ($pasta !== null) {
            $this->g($pasta)->addCliente($cliente);
        }
        $this->em()->flush();

        return $cliente;
    }

    private function pasta(User $responsavel): Pasta
    {
        $pasta = (new Pasta())->setNup('ESC-' . uniqid());
        $pasta->setTenant($this->g($this->escritorio));
        $pasta->setResponsavel($this->g($responsavel));
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function prazo(Pasta $pasta, User $autor): PastaPrazo
    {
        $prazo = new PastaPrazo($this->g($this->escritorio), $this->g($pasta), $this->g($autor));
        $prazo->definir('prazo', 'Protocolar a contestação', new \DateTimeImmutable('2026-12-20'), null, 'Pessoa do Conector');
        $this->em()->persist($prazo);
        $this->em()->flush();

        return $prazo;
    }

    private function registro(Pasta $pasta, User $autor): PastaMensagem
    {
        $registro = new PastaMensagem();
        $registro->setPasta($this->g($pasta));
        $registro->setAutor($this->g($autor));
        $registro->setTenant($this->g($this->escritorio));
        $registro->setConteudo('<p>Texto do registro</p>');
        $this->em()->persist($registro);
        $this->em()->flush();

        return $registro;
    }

    private function processo(?Pasta $pasta, string $classe, int $sigilo): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', random_int(1000, 9999)));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual($classe);
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->g($this->escritorio));
        $processo->setNivelSigilo($sigilo);
        $this->em()->persist($processo);
        if ($pasta !== null) {
            $vinculo = new PastaProcesso($this->g($pasta), $processo);
            $this->em()->persist($vinculo);
            $this->g($pasta)->getPastaProcessos()->add($vinculo);
        }
        $this->em()->flush();

        return $processo;
    }

    private function subpasta(Pasta $pasta): PastaSecao
    {
        $subpasta = new PastaSecao();
        $subpasta->setPasta($this->g($pasta));
        $subpasta->setTenant($this->g($this->escritorio));
        $subpasta->setNome('Subpasta alheia');
        $this->em()->persist($subpasta);
        $this->em()->flush();

        return $subpasta;
    }
}
