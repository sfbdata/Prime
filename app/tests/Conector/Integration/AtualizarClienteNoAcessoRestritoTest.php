<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Repository\DocumentoVinculadoRepository;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarClienteAcesso;
use App\Conector\Ferramenta\AtualizarClienteFerramenta;
use App\Conector\Ferramenta\AtualizarClienteLote;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `atualizar_cliente` no acesso restrito às pastas sob responsabilidade (spec acesso-restrito-e-compartilhar, §5.1, e
 * mcp-remoto-oauth, 6.2, regra 1): o cliente fora das pastas da pessoa e a pasta fora do escopo dela dão o mesmo "não
 * encontrado" da alheia e da inexistente, nas ações diretas, ao propor e na confirmação. O escopo chega pela mesma
 * porta da tela: o `PermissionChecker` (recurso `cliente`) e o cadeado da pasta (`AcessoAPasta`), com o
 * `EscopoDePastas` do #770. Cadastrar sem pasta exige `resources.carteira.gerenciar`, fora da lista de liberação do
 * restrito.
 */
#[CoversClass(AtualizarClienteFerramenta::class)]
#[CoversClass(AtualizarClienteAcesso::class)]
#[CoversClass(AtualizarClienteLote::class)]
final class AtualizarClienteNoAcessoRestritoTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const PERMISSOES = [
        'modules.expediente.view', 'modules.pastas.view', 'modules.clientes.view', 'resources.cliente.view',
        'resources.cliente.edit', 'resources.pasta.view', 'resources.pasta.edit', 'resources.carteira.gerenciar',
    ];

    private Tenant $escritorio;
    private User $restrito;
    private User $comum;
    private ClienteDoConector $app;
    private Cliente $meu;
    private Cliente $alheio;
    private Cliente $avulso;
    private Pasta $minha;
    private Pasta $alheia;
    private PastaDocumento $documentoMeu;
    private PastaDocumento $documentoAlheio;
    /** @var array<int, int> pessoa => consentimento */
    private array $consentimentos = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->restrito = $this->pessoa();
        $this->vinculo($this->restrito, $this->escritorio, permissoes: self::PERMISSOES)->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $this->comum = $this->pessoa();
        $this->vinculo($this->comum, $this->escritorio, permissoes: self::PERMISSOES);
        $this->app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($this->app);
        $this->em()->flush();

        $this->meu = $this->cliente('Cliente Do Restrito', '529.982.247-25');
        $this->alheio = $this->cliente('Cliente De Outro', '390.533.447-05');
        $this->avulso = $this->cliente('Cliente Sem Pasta', '');
        $this->minha = $this->pasta($this->restrito, $this->meu);
        // O cliente do restrito também está na pasta alheia: ele continua no escopo, a pasta não.
        $this->alheia = $this->pasta($this->comum, $this->alheio, $this->meu);
        $this->documentoMeu = $this->documento($this->minha);
        $this->documentoAlheio = $this->documento($this->alheia);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('cliente fora das pastas do restrito (de pasta alheia ou sem pasta) dá o mesmo não encontrado do inexistente, nas ações diretas e nas que propõem; nada é gravado nem proposto')]
    public function testClienteForaDoEscopoNaoExiste(): void
    {
        foreach ([$this->alheio->getId(), $this->avulso->getId(), 999_999_999] as $clienteId) {
            $pedidos = [
                ['acao' => 'completar', 'cliente_id' => $clienteId, 'campos' => ['bairro' => 'Bairro Novo']],
                ['acao' => 'completar', 'cliente_id' => $clienteId, 'campos' => ['profissao' => 'Troca Por Proposta']],
                ['acao' => 'contato', 'cliente_id' => $clienteId, 'campo' => 'celular', 'valor' => '61911112222'],
                ['acao' => 'vincular_a_pasta', 'cliente_id' => $clienteId, 'pasta_id' => $this->minha->getId()],
                ['acao' => 'vincular_a_pasta', 'operacao' => 'desvincular', 'cliente_id' => $clienteId, 'pasta_id' => $this->minha->getId()],
                ['acao' => 'principal', 'cliente_id' => $clienteId, 'pasta_id' => $this->minha->getId()],
                ['acao' => 'ligar_documento', 'cliente_id' => $clienteId, 'documento_id' => $this->documentoMeu->getId()],
            ];
            foreach ($pedidos as $pedido) {
                $this->assertNaoEncontrado($pedido);
            }
        }
        self::assertSame(['', 'PROFESSORA'], $this->bairroEProfissao($this->alheio));
        self::assertSame(['', 'PROFESSORA'], $this->bairroEProfissao($this->avulso));
        self::assertSame(1, $this->vinculos($this->minha));
        self::assertSame(0, $this->propostasNoBanco());

        // Controle: o restrito grava no cliente dele, e quem não é restrito grava no cliente alheio.
        self::assertSame(['bairro'], $this->chamar(['acao' => 'completar', 'cliente_id' => $this->meu->getId(), 'campos' => ['bairro' => 'Bairro Novo']])->dados['campos_alterados']);
        self::assertSame(['bairro'], $this->chamar(['acao' => 'completar', 'cliente_id' => $this->alheio->getId(), 'campos' => ['bairro' => 'Bairro Novo']], $this->comum)->dados['campos_alterados']);
    }

    #[TestDox('pasta fora do escopo do restrito dá não encontrado, mesmo com o cliente dele; no cadastrar, o item com a pasta alheia sai com não encontrado e o sem pasta, sem permissão (carteira fora da lista de liberação)')]
    public function testPastaForaDoEscopoNaoExiste(): void
    {
        $meu = $this->meu->getId();
        foreach ([
            ['acao' => 'vincular_a_pasta', 'cliente_id' => $meu, 'pasta_id' => $this->alheia->getId()],
            ['acao' => 'vincular_a_pasta', 'operacao' => 'desvincular', 'cliente_id' => $meu, 'pasta_id' => $this->alheia->getId()],
            ['acao' => 'principal', 'cliente_id' => $meu, 'pasta_id' => $this->alheia->getId()],
            ['acao' => 'ligar_documento', 'cliente_id' => $meu, 'documento_id' => $this->documentoAlheio->getId()],
        ] as $pedido) {
            $this->assertNaoEncontrado($pedido);
        }

        $cadastro = $this->chamar(['acao' => 'cadastrar', 'clientes' => [
            ['tipo' => 'pf', 'campos' => ['nome' => 'Na Pasta Alheia'], 'pasta_id' => $this->alheia->getId()],
            ['tipo' => 'pf', 'campos' => ['nome' => 'Sem Pasta']],
            ['tipo' => 'pf', 'campos' => ['nome' => 'Na Minha Pasta'], 'pasta_id' => $this->minha->getId()],
        ]]);

        self::assertSame([
            ['item' => 1, 'motivo' => ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, 'mensagem' => ErroDaFerramenta::NAO_ENCONTRADO],
            ['item' => 2, 'motivo' => ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, 'mensagem' => ErroDaFerramenta::SEM_PERMISSAO],
        ], $cadastro->dados['detalhes']['fora_da_lista']);
        self::assertStringStartsWith('Cadastrar 1 cliente no escritório:', $cadastro->dados['proposta']['resumo']);
        self::assertStringNotContainsString((string) $this->alheia->getNup(), (string) json_encode($cadastro->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        self::assertSame(2, $this->vinculos($this->alheia));
        self::assertSame((int) $this->alheio->getId(), $this->principalNoBanco($this->alheia));
        self::assertSame([], $this->ligados($this->meu));
    }

    #[TestDox('o escopo some entre propor e confirmar (a pasta do cliente passa a outro responsável): a confirmação dá não encontrado e o cadastro não muda')]
    public function testEscopoPerdidoEntreProporEConfirmar(): void
    {
        $proposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $this->meu->getId(), 'campos' => ['profissao' => 'Troca Por Proposta']]);
        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $pasta = $this->em()->find(Pasta::class, (int) $this->minha->getId()) ?? self::fail('a pasta sumiu');
        $pasta->setResponsavel($this->em()->find(User::class, (int) $this->comum->getId()));
        $this->em()->flush();
        static::getContainer()->get('services_resetter')->reset();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
        self::assertSame(['', 'PROFESSORA'], $this->bairroEProfissao($this->meu));
    }

    /** @param array<string, mixed> $pedido */
    private function assertNaoEncontrado(array $pedido): void
    {
        try {
            $this->chamar($pedido);
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $e->motivo, json_encode($pedido, \JSON_THROW_ON_ERROR));
            self::assertSame(ErroDaFerramenta::NAO_ENCONTRADO, $e->getMessage());

            return;
        }
        self::fail('deveria dar não encontrado: ' . json_encode($pedido, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $argumentos */
    private function chamar(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        try {
            return static::getContainer()->get(AtualizarClienteFerramenta::class)->executar($argumentos, $this->chamada($pessoa ?? $this->restrito));
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    private function recusaDaConfirmacao(RespostaDaFerramenta $proposta): ErroDaFerramenta
    {
        $dados = $proposta->dados['proposta'] ?? self::fail('a resposta não é uma proposta');
        try {
            static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar(['proposta_id' => $dados['proposta_id'], 'resumo' => $dados['resumo']], $this->chamada($this->restrito));
        } catch (ErroDaFerramenta $e) {
            return $e;
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
        self::fail('a confirmação deveria recusar');
    }

    private function chamada(User $pessoa): ChamadaDoConector
    {
        $id = (int) $pessoa->getId();
        $em = $this->em();
        if (!isset($this->consentimentos[$id])) {
            // Pelo id: o `services_resetter` de cada chamada limpa o EntityManager e solta as entidades do cenário.
            $consentimento = new ConsentimentoDoConector($em->getReference(User::class, $id), $em->getReference(Tenant::class, $this->escritorio->getId()), $em->getReference(ClienteDoConector::class, $this->app->getId()), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('-1 hour'));
            $em->persist($consentimento);
            $em->flush();
            $this->consentimentos[$id] = (int) $consentimento->getId();
        }
        $contexto = $this->portao()->abrir($id, (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, $this->consentimentos[$id], 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    /** @return array{0: string, 1: string} o bairro e a profissão lidos direto do banco */
    private function bairroEProfissao(Cliente $cliente): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT c.bairro, pf.profissao FROM cliente c JOIN cliente_pf pf ON pf.id = c.id WHERE c.id = ?', [$cliente->getId()]);
        self::assertIsArray($linha);

        return [(string) $linha['bairro'], (string) $linha['profissao']];
    }

    private function vinculos(Pasta $pasta): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = ?', [$pasta->getId()]);
    }

    private function principalNoBanco(Pasta $pasta): ?int
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT cliente_principal_id FROM pasta WHERE id = ?', [$pasta->getId()]);

        return $id === null || $id === false ? null : (int) $id;
    }

    /** @return list<int> */
    private function ligados(Cliente $cliente): array
    {
        return static::getContainer()->get(DocumentoVinculadoRepository::class)->idsDePastaDocumentoVinculados($this->em()->find(Cliente::class, (int) $cliente->getId()) ?? self::fail('o cliente sumiu'), $this->em()->find(Tenant::class, (int) $this->escritorio->getId()) ?? self::fail('o escritório sumiu'));
    }

    private function propostasNoBanco(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function cliente(string $nome, string $cpf): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto($nome)->setCpf($cpf)->setRg('')->setRgOrgaoExpedidor('')->setProfissao('Professora')->setNacionalidade('brasileira');
        $cliente->setEmail('')->setTelefoneCelular('(61) 98765-4321')->setCep('')->setEndereco('')->setCidade('Brasília')->setEstado('DF')->setTenant($this->escritorio);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function pasta(User $responsavel, Cliente ...$clientes): Pasta
    {
        $pasta = (new Pasta())->setNup('ACR-' . uniqid());
        $pasta->setTenant($this->escritorio);
        $pasta->setResponsavel($responsavel);
        foreach ($clientes as $cliente) {
            $pasta->addCliente($cliente);
        }
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function documento(Pasta $pasta): PastaDocumento
    {
        $documento = (new PastaDocumento())->setPasta($pasta)->setTenant($pasta->getTenant())->setCategoria(PastaDocumento::CATEGORIA_IDENTIFICACAO)
            ->setTitulo('rg.pdf')->setNomeOriginal('rg.pdf')->setCaminhoArquivo('acr_' . bin2hex(random_bytes(6)) . '.pdf')
            ->setMimeType('application/pdf')->setTamanhoBytes(1234);
        $pasta->getDocumentos()->add($documento);
        $this->em()->persist($documento);
        $this->em()->flush();

        return $documento;
    }
}
