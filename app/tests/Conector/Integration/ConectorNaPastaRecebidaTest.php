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
use App\Conector\Ferramenta\AtualizarPastaFerramenta;
use App\Conector\Ferramenta\BuscarPastasFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\DocumentosEPecasFerramenta;
use App\Conector\Ferramenta\FerramentaDoConector;
use App\Conector\Ferramenta\FinanceiroDaPastaFerramenta;
use App\Conector\Ferramenta\GravarNaAgendaFerramenta;
use App\Conector\Ferramenta\LerDocumentosEModelosFerramenta;
use App\Conector\Ferramenta\LerPastaFerramenta;
use App\Conector\Ferramenta\LerProcessosFerramenta;
use App\Conector\Ferramenta\MudarAndamentoFerramenta;
use App\Conector\Ferramenta\PrazosEMetasDaPastaFerramenta;
use App\Conector\Ferramenta\ProcessosDaPastaFerramenta;
use App\Conector\Ferramenta\RegistrarNaPastaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A pasta recebida de outra conta (spec acesso-restrito-e-compartilhar, §4.5.1) só se alcança pelas telas da recebida,
 * no escritório de quem recebeu. Pelo conector de I.A., a Bia (escritório B, sem vínculo em A) aceitou o convite da
 * pasta P do escritório A, com as três caixas marcadas, e mesmo assim nenhuma ferramenta chega a P: as listas não a
 * trazem e cada ação por id responde "não encontrado", sem gravar nada em A. O controle positivo de cada caso é a
 * mesma chamada pela Olga, de A, no escritório A: prova que os argumentos são válidos e que a foto de A enxerga a
 * escrita.
 *
 * A foto de A é tirada pela conexão (sem o filtro do Doctrine): cada tabela com `pasta_id` na linha de P, a própria
 * pasta, o processo dela e as propostas do conector.
 */
#[CoversClass(GuardaDaAcaoDoConector::class)]
final class ConectorNaPastaRecebidaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const PERMISSOES = [
        'modules.expediente.view', 'modules.pastas.view', 'modules.processos.view', 'modules.agenda.view',
        'modules.tarefas.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.pasta.delete',
        'resources.processo.view', 'resources.processo.edit', 'resources.agenda.ver_prazos',
    ];

    private const PDF = "%PDF-1.7\nconteudo do pdf";

    private Tenant $contaA;
    private Tenant $contaB;
    private User $olga;
    private User $bia;
    private Pasta $pasta;
    private Processo $processo;
    private PastaDocumento $documento;
    private string $classe;
    /** @var array<int, int> consentimento por pessoa */
    private array $consentimentos = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->contaA = $this->escritorio('Conta A');
        $this->contaB = $this->escritorio('Conta B');
        $dono = $this->pessoa();
        $this->vinculo($dono, $this->contaA, sistema: true, permissoes: self::PERMISSOES);
        $this->olga = $this->pessoa();
        $this->olga->setFullName('Olga Origem');
        $this->vinculo($this->olga, $this->contaA, permissoes: self::PERMISSOES);
        $this->bia = $this->pessoa();
        $this->bia->setFullName('Bia Parceira');
        $this->vinculo($this->bia, $this->contaB, permissoes: self::PERMISSOES);

        $this->ligarConector($this->contaA, $dono);
        $this->ligarConector($this->contaB, $this->bia);
        $app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($app);
        $this->consentir($this->olga, $this->contaA, $app);
        $this->consentir($this->bia, $this->contaB, $app);

        $this->classe = 'Classe Recebida ' . uniqid();
        $this->processo = new Processo();
        $this->processo->setNumeroProcesso(sprintf('0701134572025807%04d', random_int(1000, 9999)));
        $this->processo->setSiglaTribunal('TJDFT');
        $this->processo->setClasseProcessual($this->classe);
        $this->processo->setAssuntoProcessual('Assunto');
        $this->processo->setTenant($this->contaA);
        $this->em()->persist($this->processo);
        $this->pasta = (new Pasta())->setNup('REC-' . uniqid());
        $this->pasta->setTenant($this->contaA);
        $this->pasta->setResponsavel($dono);
        $this->em()->persist($this->pasta);
        $vinculo = new PastaProcesso($this->pasta, $this->processo);
        $this->em()->persist($vinculo);
        $this->pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        $this->documento = new PastaDocumento();
        $this->documento->setTenant($this->contaA);
        $this->documento->setPasta($this->pasta);
        $this->documento->setTitulo('peticao.pdf');
        $this->documento->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $this->documento->setCaminhoArquivo(uniqid('doc', true) . '.bin');
        $this->documento->setNomeOriginal('peticao.pdf');
        $this->documento->setMimeType('application/pdf');
        $this->documento->setTamanhoBytes(\strlen(self::PDF));
        $this->documento->setPaginas(1);
        $this->em()->persist($this->documento);
        $this->em()->flush();
        $disco->memoria->semear(ChavesDePasta::documento($this->documento), self::PDF);

        // O convite de outra conta, aceito, com as três caixas: o caso mais forte.
        $convite = PastaCompartilhamento::externo($this->pasta, $this->olga, $this->bia, new PermissoesDoCompartilhamento(true, true, true), null, null, new \DateTimeImmutable('-2 days'));
        $this->em()->persist($convite);
        $this->em()->flush();
        $convite->aceitar($this->bia, new \DateTimeImmutable('-1 day'));
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('o cenário: o convite de outra conta está aceito e a Bia não tem vínculo em A')]
    public function testCenario(): void
    {
        $c = $this->em()->getConnection();
        self::assertSame(1, (int) $c->fetchOne("SELECT COUNT(*) FROM pasta_compartilhamento WHERE pasta_id = :p AND usuario_destino_id = :b AND status = :aceita AND interno = false AND pode_movimentar AND pode_enviar_documentos AND pode_baixar_documentos", ['p' => $this->pasta->getId(), 'b' => $this->bia->getId(), 'aceita' => EstadoDoCompartilhamento::Aceita->value]));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM user_tenant WHERE user_id = :b AND tenant_id = :a', ['b' => $this->bia->getId(), 'a' => $this->contaA->getId()]));
    }

    /** @return iterable<string, array{0: class-string<FerramentaDoConector>, 1: string, 2: bool}> */
    public static function chamadasPelaPasta(): iterable
    {
        yield 'ler_pasta' => [LerPastaFerramenta::class, 'ler_pasta', false];
        yield 'ler_processos detalhar' => [LerProcessosFerramenta::class, 'detalhar', false];
        yield 'ler_processos para_vincular' => [LerProcessosFerramenta::class, 'para_vincular', false];
        yield 'processos_da_pasta dados_do_cartao' => [ProcessosDaPastaFerramenta::class, 'dados_do_cartao', true];
        yield 'ler_documentos_e_modelos listar' => [LerDocumentosEModelosFerramenta::class, 'listar', false];
        yield 'ler_documentos_e_modelos ler' => [LerDocumentosEModelosFerramenta::class, 'ler', false];
        yield 'documentos_e_pecas escrever_peca' => [DocumentosEPecasFerramenta::class, 'escrever_peca', true];
        yield 'financeiro_da_pasta pro_bono' => [FinanceiroDaPastaFerramenta::class, 'pro_bono', true];
        yield 'prazos_e_metas_da_pasta salvar_prazo' => [PrazosEMetasDaPastaFerramenta::class, 'salvar_prazo', true];
        yield 'mudar_andamento mudar_fase' => [MudarAndamentoFerramenta::class, 'mudar_fase', true];
        yield 'registrar_na_pasta escrever' => [RegistrarNaPastaFerramenta::class, 'escrever', true];
        yield 'atualizar_pasta prioridade' => [AtualizarPastaFerramenta::class, 'prioridade', true];
        yield 'gravar_na_agenda salvar' => [GravarNaAgendaFerramenta::class, 'agenda', true];
    }

    /** @param class-string<FerramentaDoConector> $ferramenta */
    #[DataProvider('chamadasPelaPasta')]
    #[TestDox('$_dataName: a Bia, em B, recebe "não encontrado" e nada muda em A; a Olga, em A, faz a mesma chamada')]
    public function testChamadaPelaPasta(string $ferramenta, string $caso, bool $escreve): void
    {
        $argumentos = $this->argumentos($caso);
        $antes = $this->foto();

        $recusa = $this->chamar($ferramenta, $argumentos, $this->bia, $this->contaB);

        self::assertInstanceOf(ErroDaFerramenta::class, $recusa, 'a Bia não pode alcançar a pasta de A');
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $recusa->motivo);
        self::assertSame($antes, $this->foto(), 'nada foi gravado em A');

        $controle = $this->chamar($ferramenta, $argumentos, $this->olga, $this->contaA);

        self::assertInstanceOf(RespostaDaFerramenta::class, $controle, 'controle: a mesma chamada passa para quem é de A');
        if ($escreve) {
            self::assertNotSame($antes, $this->foto(), 'controle: a escrita de A aparece na foto');
        }
    }

    #[TestDox('ler_processos cnj: a Bia recebe "não encontrado" antes de qualquer consulta ao CNJ')]
    public function testCnj(): void
    {
        $antes = $this->foto();

        $recusa = $this->chamar(LerProcessosFerramenta::class, ['acao' => 'cnj', 'pasta_id' => $this->pasta->getId(), 'numero_processo' => (string) $this->processo->getNumeroProcesso()], $this->bia, $this->contaB);

        self::assertInstanceOf(ErroDaFerramenta::class, $recusa);
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $recusa->motivo);
        self::assertSame($antes, $this->foto());
    }

    #[TestDox('buscar_pastas: a pasta recebida não sai para a Bia na lista, na busca pelo número, na contagem nem nas demandas; sai para a Olga')]
    public function testBuscarPastas(): void
    {
        $antes = $this->foto();
        $nup = (string) $this->pasta->getNup();
        $id = $this->pasta->getId();

        $lista = $this->resposta(BuscarPastasFerramenta::class, ['acao' => 'buscar'], $this->bia, $this->contaB);
        self::assertNotContains($id, array_column($lista->dados['pastas'], 'id'));
        $busca = $this->resposta(BuscarPastasFerramenta::class, ['acao' => 'buscar', 'busca' => $nup], $this->bia, $this->contaB);
        self::assertNotContains($id, array_column($busca->dados['pastas'], 'id'));
        self::assertStringNotContainsString($nup, $this->json($busca));
        $porNumero = $this->resposta(BuscarPastasFerramenta::class, ['acao' => 'numero', 'numero' => $nup], $this->bia, $this->contaB);
        self::assertStringNotContainsString($nup, $this->json($porNumero));
        self::assertSame(['total' => 0], $this->resposta(BuscarPastasFerramenta::class, ['acao' => 'contar'], $this->bia, $this->contaB)->dados);
        self::assertStringNotContainsString($nup, $this->json($this->resposta(BuscarPastasFerramenta::class, ['acao' => 'minhas_demandas'], $this->bia, $this->contaB)));
        self::assertSame($antes, $this->foto());

        self::assertContains($id, array_column($this->resposta(BuscarPastasFerramenta::class, ['acao' => 'buscar'], $this->olga, $this->contaA)->dados['pastas'], 'id'));
        self::assertContains($id, array_column($this->resposta(BuscarPastasFerramenta::class, ['acao' => 'numero', 'numero' => $nup], $this->olga, $this->contaA)->dados['resultados'], 'pasta_id'));
    }

    #[TestDox('ler_processos buscar: o processo da pasta recebida não sai para a Bia pelo termo; sai para a Olga')]
    public function testLerProcessosPeloTermo(): void
    {
        $antes = $this->foto();

        $daBia = $this->resposta(LerProcessosFerramenta::class, ['acao' => 'buscar', 'termo' => $this->classe], $this->bia, $this->contaB);

        self::assertNotContains($this->processo->getId(), array_column($daBia->dados['processos'], 'id'));
        self::assertStringNotContainsString((string) $this->processo->getNumeroProcesso(), $this->json($daBia));
        self::assertStringNotContainsString((string) $this->pasta->getNup(), $this->json($daBia));
        self::assertSame($antes, $this->foto());

        $daOlga = $this->resposta(LerProcessosFerramenta::class, ['acao' => 'buscar', 'termo' => $this->classe], $this->olga, $this->contaA);
        self::assertSame([$this->processo->getId()], array_column($daOlga->dados['processos'], 'id'));
    }

    /** @return iterable<string, array{0: class-string<FerramentaDoConector>, 1: array<string, mixed>, 2: array<string, mixed>}> */
    public static function propostas(): iterable
    {
        yield 'mudar_andamento voltar a fase' => [
            MudarAndamentoFerramenta::class,
            ['acao' => 'mudar_fase', 'fase' => 'protocolo', 'motivo' => 'Protocolado'],
            ['acao' => 'mudar_fase', 'fase' => 'Documentação', 'motivo' => 'Erro de clique'],
        ];
        yield 'financeiro_da_pasta trocar o valor da causa' => [
            FinanceiroDaPastaFerramenta::class,
            ['acao' => 'valor_da_causa', 'valor' => '12.860,00'],
            ['acao' => 'valor_da_causa', 'valor' => '20.000,00'],
        ];
    }

    /**
     * A proposta forjada é a da Olga, com os mesmos dados (pasta_id = P), mas nascida na conexão da Bia, em B: o hash
     * dos dados é o certo (a {@see PropostasDoConector} o calcula). Confirmar como a Bia não grava nada em A; a da
     * Olga confirma.
     *
     * @param class-string<FerramentaDoConector> $ferramenta
     * @param array<string, mixed>               $direta
     * @param array<string, mixed>               $proposta
     */
    #[DataProvider('propostas')]
    #[TestDox('confirmar_acao, $_dataName: a proposta da Bia que aponta a pasta de A recebe "não encontrado" e nada grava; a da Olga confirma')]
    public function testConfirmarAcao(string $ferramenta, array $direta, array $proposta): void
    {
        $p = ['pasta_id' => $this->pasta->getId()];
        self::assertInstanceOf(RespostaDaFerramenta::class, $this->chamar($ferramenta, $direta + $p, $this->olga, $this->contaA));
        $daOlga = $this->resposta($ferramenta, $proposta + $p, $this->olga, $this->contaA);
        self::assertSame('aguardando_confirmacao', $daOlga->dados['estado']);
        $guardada = $this->em()->getConnection()->fetchAssociative('SELECT ferramenta, acao, dados, resumo FROM conector_ia_proposta WHERE consentimento_id = :c ORDER BY id DESC LIMIT 1', ['c' => $this->consentimentos[(int) $this->olga->getId()]]);
        self::assertIsArray($guardada);
        $dados = json_decode((string) $guardada['dados'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($this->pasta->getId(), $dados['pasta_id']);

        $daBia = $this->proporComoBia((string) $guardada['ferramenta'], (string) $guardada['acao'], $dados, (string) $guardada['resumo']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta WHERE tenant_id = :b', ['b' => $this->contaB->getId()]), 'a proposta da Bia nasce em B');
        $antes = $this->foto();

        $recusa = $this->chamar(ConfirmarAcaoFerramenta::class, $daBia, $this->bia, $this->contaB);

        self::assertInstanceOf(ErroDaFerramenta::class, $recusa, 'confirmar como a Bia não pode gravar em A');
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $recusa->motivo);
        self::assertSame($antes, $this->foto(), 'nada foi gravado em A');
        self::assertSame('pendente', $this->em()->getConnection()->fetchOne('SELECT estado FROM conector_ia_proposta WHERE tenant_id = :b', ['b' => $this->contaB->getId()]), 'a recusa desfaz tudo');

        $confirmada = $this->chamar(ConfirmarAcaoFerramenta::class, ['proposta_id' => $daOlga->dados['proposta']['proposta_id'], 'resumo' => $daOlga->dados['proposta']['resumo']], $this->olga, $this->contaA);

        self::assertInstanceOf(RespostaDaFerramenta::class, $confirmada, 'controle: a proposta da Olga confirma');
        self::assertSame('confirmada', $confirmada->dados['estado']);
        self::assertNotSame($antes, $this->foto(), 'controle: a confirmação de A aparece na foto');
    }

    // ── Apoio ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> os argumentos válidos de cada caso, todos com a pasta P */
    private function argumentos(string $caso): array
    {
        $p = (int) $this->pasta->getId();

        return match ($caso) {
            'ler_pasta'       => ['pasta_id' => $p, 'partes' => ['resumo', 'andamento', 'registro']],
            'detalhar'        => ['acao' => 'detalhar', 'processo_id' => $this->processo->getId()],
            'para_vincular'   => ['acao' => 'para_vincular', 'pasta_id' => $p],
            'dados_do_cartao' => ['acao' => 'dados_do_cartao', 'pasta_id' => $p, 'processo_id' => $this->processo->getId(), 'jurisdicao' => 'Brasília'],
            'listar'          => ['acao' => 'listar', 'pasta_id' => $p],
            'ler'             => ['acao' => 'ler', 'pasta_id' => $p, 'documento_id' => $this->documento->getId()],
            'escrever_peca'   => ['acao' => 'escrever_peca', 'pasta_id' => $p, 'titulo' => 'Nota', 'conteudo' => 'Texto da nota', 'categoria' => 'DEMAIS'],
            'pro_bono'        => ['acao' => 'pro_bono', 'pasta_id' => $p, 'ligado' => true],
            'salvar_prazo'    => ['acao' => 'salvar_prazo', 'pasta_id' => $p, 'tipo' => 'prazo', 'texto' => 'Contestar a ação', 'data' => (new \DateTimeImmutable('+15 days'))->format('Y-m-d')],
            'mudar_fase'      => ['acao' => 'mudar_fase', 'pasta_id' => $p, 'fase' => 'protocolo', 'motivo' => 'Protocolado'],
            'escrever'        => ['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $p, 'texto' => 'Cliente ligou.'],
            'prioridade'      => ['acao' => 'prioridade', 'pasta_id' => $p, 'prioridade' => 'urgente'],
            'agenda'          => ['acao' => 'salvar', 'titulo' => 'Reunião com o cliente', 'data' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'), 'pasta_id' => $p],
        };
    }

    /**
     * A chamada como o `/mcp` faria: EntityManager novo, portão aberto para a pessoa no escritório, a ferramenta do
     * container. Depois dela, com recusa ou sem, o filtro do escritório segue ligado no escritório da chamada.
     *
     * @param class-string<FerramentaDoConector> $ferramenta
     * @param array<string, mixed>               $argumentos
     */
    private function chamar(string $ferramenta, array $argumentos, User $pessoa, Tenant $escritorio): RespostaDaFerramenta|ErroDaFerramenta
    {
        $chamada = $this->chamada($pessoa, $escritorio);

        try {
            try {
                $resultado = static::getContainer()->get($ferramenta)->executar($argumentos, $chamada);
            } catch (ErroDaFerramenta $erro) {
                $resultado = $erro;
            }
            self::assertTrue($this->filtro()->estaLigadoEm((int) $escritorio->getId()), 'o filtro do escritório segue ligado depois da chamada');

            return $resultado;
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    /**
     * @param class-string<FerramentaDoConector> $ferramenta
     * @param array<string, mixed>               $argumentos
     */
    private function resposta(string $ferramenta, array $argumentos, User $pessoa, Tenant $escritorio): RespostaDaFerramenta
    {
        $resultado = $this->chamar($ferramenta, $argumentos, $pessoa, $escritorio);
        if ($resultado instanceof ErroDaFerramenta) {
            self::fail(sprintf('a chamada deveria passar, recusou com %s: %s', $resultado->motivo, $resultado->getMessage()));
        }

        return $resultado;
    }

    private function chamada(User $pessoa, Tenant $escritorio): ChamadaDoConector
    {
        static::getContainer()->get('services_resetter')->reset();
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId());

        return new ChamadaDoConector($contexto, 41, $this->consentimentos[(int) $pessoa->getId()], 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{proposta_id: string, resumo: string}
     */
    private function proporComoBia(string $ferramenta, string $acao, array $dados, string $resumo): array
    {
        $chamada = $this->chamada($this->bia, $this->contaB);

        try {
            $resposta = static::getContainer()->get(PropostasDoConector::class)->propor($chamada, $ferramenta, $acao, $dados, $resumo);
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }

        return ['proposta_id' => $resposta->dados['proposta']['proposta_id'], 'resumo' => $resposta->dados['proposta']['resumo']];
    }

    /**
     * O estado de A que importa, pela conexão (o filtro do Doctrine não se aplica): cada tabela com `pasta_id` na linha
     * de P, a própria pasta, o processo vinculado e as propostas do conector.
     *
     * @return array<string, mixed>
     */
    private function foto(): array
    {
        $c = $this->em()->getConnection();
        $p = ['p' => $this->pasta->getId()];
        $tabelas = $c->fetchFirstColumn("SELECT table_name FROM information_schema.columns WHERE column_name = 'pasta_id' AND table_schema = current_schema() ORDER BY table_name");
        $foto = [];
        foreach ($tabelas as $tabela) {
            $foto[$tabela] = $c->fetchOne(sprintf("SELECT md5(COALESCE(json_agg(t ORDER BY t::text)::text, '')) FROM %s t WHERE t.pasta_id = :p", $c->quoteSingleIdentifier((string) $tabela)), $p);
        }
        $foto['pasta'] = $c->fetchOne('SELECT md5(row_to_json(t)::text) FROM pasta t WHERE t.id = :p', $p);
        $foto['processo'] = $c->fetchOne('SELECT md5(row_to_json(t)::text) FROM processo t WHERE t.id = :id', ['id' => $this->processo->getId()]);
        $foto['conector_ia_proposta'] = $c->fetchOne('SELECT md5(COALESCE(json_agg(t ORDER BY t.id)::text, \'\')) FROM conector_ia_proposta t');

        return $foto;
    }

    private function json(RespostaDaFerramenta $resposta): string
    {
        return json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    private function ligarConector(Tenant $escritorio, User $quem): void
    {
        $doConector = new EscritorioDoConector($escritorio);
        $doConector->liberarPeloMaster();
        $doConector->ligar($quem, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $this->em()->flush();
    }

    private function consentir(User $pessoa, Tenant $escritorio, ClienteDoConector $app): void
    {
        $consentimento = new ConsentimentoDoConector($pessoa, $escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($consentimento);
        $this->em()->flush();
        $this->consentimentos[(int) $pessoa->getId()] = (int) $consentimento->getId();
    }
}
