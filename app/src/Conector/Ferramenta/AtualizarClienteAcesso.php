<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\CadastroInvalidoException;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\ContatoInvalidoException;
use App\Cliente\Exception\DocumentoDaPastaNaoEncontradoException;
use App\Cliente\Exception\DocumentoJaCadastradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Exception\SemPermissaoParaVerClienteException;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\UseCase\CriarClienteDaPastaUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\EnvioAutomaticoLigado;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\SegredoNasListas;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;

/**
 * As recusas de acesso da `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.2 e 6.6), num lugar só. Pessoa e
 * escritório vêm só da {@see ChamadaDoConector}; toda busca leva o escritório explícito.
 *
 *  - o cliente: por id E escritório, e a pessoa pode VÊ-LO; alheio, inexistente ou que ela não vê dão o mesmo "não
 *    encontrado" (6.2, regra 1). Quem vê e não edita recebe "sem permissão" ({@see exigirEdicaoDoCliente()}): ela já
 *    vê o cliente pela `ler_clientes`, então a recusa não revela nada;
 *  - a pasta ({@see pastaViva()}): a linha da tabela com a pasta (chave `expedientes`, ver a pasta com o cadeado), a
 *    lixeira ("não encontrado", como a BlueJus I.A.), o segredo de justiça e, quando a tela só abre a quem edita, o
 *    recurso `pasta` de editar ({@see GuardaDaAcaoDoConector::exigirEdicaoDaPasta()});
 *  - o envio automático do Push ({@see exigirSemEnvioAutomatico()}): recusa a chamada inteira (abertura da 6.6);
 *  - os valores de uma proposta ({@see conferirCampos()}): as regras da janela de Cadastro num cadastro em branco que
 *    nunca vai ao banco, para a proposta não prometer o que a confirmação recusaria (CPF inválido, CPF de outro
 *    cadastro).
 *
 * {@see gravando()} troca a exceção de regra do UseCase por recusa de mensagem fixa, escolhida pela CLASSE da exceção:
 * a mensagem do UseCase nunca passa adiante.
 */
final class AtualizarClienteAcesso
{
    public const DOCUMENTO_JA_CADASTRADO = 'Este CPF ou CNPJ já é de outro cadastro do escritório. Use ler_clientes com a ação conferir_documento para saber de qual. Nada foi gravado.';

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly SegredoNasListas $segredo,
        private readonly ClienteRepository $clientes,
        private readonly PastaRepository $pastas,
        private readonly PermissionChecker $permissoes,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly EnvioAutomaticoLigado $envio,
        private readonly CamposDoCadastro $campos,
    ) {
    }

    /** `pj` para pessoa jurídica, `pf` para o resto (o tipo dos cadastros em branco do `CriarClienteDaPastaUseCase`). */
    public static function tipoDo(Cliente $cliente): string
    {
        return $cliente instanceof ClientePJ ? 'pj' : 'pf';
    }

    /**
     * Aplica os campos num cadastro em branco do tipo, que nunca é persistido (só LÊ o banco, na busca do CPF ou CNPJ
     * repetido): a ferramenta propõe e reconfere sem mexer em entidade do EntityManager (contrato da
     * {@see ExecutorDeProposta}, item 1). Lança as exceções da {@see CamposDoCadastro}; quem chama as traduz.
     *
     * @param array<string, string> $campos chaves de {@see CamposDoCadastro::ROTULOS}
     *
     * @throws \App\Cliente\Exception\CadastroInvalidoException
     * @throws DocumentoJaCadastradoException
     */
    public function conferirCampos(string $tipo, array $campos, Tenant $escritorio): void
    {
        $rascunho = $tipo === 'pj' ? CriarClienteDaPastaUseCase::pjEmBranco() : CriarClienteDaPastaUseCase::pfEmBranco();
        $rascunho->setTenant($escritorio);
        $this->campos->aplicar($rascunho, $campos, $escritorio);
    }

    /**
     * O cliente do escritório da chamada que a pessoa vê; senão, "não encontrado".
     *
     * @throws ErroDaFerramenta
     */
    public function cliente(ChamadaDoConector $chamada, int $clienteId): Cliente
    {
        $contexto = $chamada->contexto;
        $cliente = $this->clientes->findOneBy(['id' => $clienteId, 'tenant' => $contexto->escritorio]);
        if (!$cliente instanceof Cliente
            || $cliente->getTenant()?->getId() !== $contexto->escritorio->getId()
            || !$this->permissoes->canAccessResource($contexto->pessoa, $contexto->escritorio, AccessRequest::RESOURCE_CLIENTE, $clienteId, AccessRequest::ACTION_VIEW)) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return $cliente;
    }

    /**
     * O recurso `cliente` de editar (6.6: completar, contato e ligar documento), como a janela de Cadastro.
     *
     * @throws ErroDaFerramenta
     */
    public function exigirEdicaoDoCliente(ChamadaDoConector $chamada, Cliente $cliente): void
    {
        $contexto = $chamada->contexto;
        if (!$this->permissoes->canAccessResource($contexto->pessoa, $contexto->escritorio, AccessRequest::RESOURCE_CLIENTE, (int) $cliente->getId(), AccessRequest::ACTION_EDIT)) {
            throw ErroDaFerramenta::semPermissao();
        }
    }

    /**
     * A pasta viva do escritório, conferida pela linha da ação com a pasta, a lixeira e o segredo; com `$editar`,
     * também o recurso `pasta` de editar (quem só vê recebe "não encontrado", como a guarda).
     *
     * @throws ErroDaFerramenta
     */
    public function pastaViva(ChamadaDoConector $chamada, string $acao, int $pastaId, bool $editar): Pasta
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $chamada->contexto->escritorio]);
        if (!$pasta instanceof Pasta) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->exigirPasta($chamada, $acao, $pasta);
        if ($editar) {
            $this->guarda->exigirEdicaoDaPasta($chamada, $pasta);
        }

        return $pasta;
    }

    /**
     * A pasta de onde vem o documento do `ligar_documento`: a mesma conferência da {@see pastaViva()}, sem editar (a
     * tela pede só ver a pasta), mais a aba Documentos visível: o recorte da `ler_clientes` `documentos_de_identidade`,
     * de onde o documento sai para o assistente, vale também para ligá-lo.
     *
     * @throws ErroDaFerramenta
     */
    public function pastaDoDocumento(ChamadaDoConector $chamada, Pasta $pasta): void
    {
        $this->exigirPasta($chamada, 'ligar_documento', $pasta);
        if (\in_array(AbaDaPasta::Documentos, $this->abas->abasOcultasPara($pasta, $chamada->contexto->pessoa), true)) {
            throw ErroDaFerramenta::naoEncontrado();
        }
    }

    /**
     * Cliente com o envio automático do Push ligado: a chamada inteira recusa, sem gravar nada (abertura da 6.6).
     *
     * @throws ErroDaFerramenta
     */
    public function exigirSemEnvioAutomatico(ChamadaDoConector $chamada, Cliente $cliente): void
    {
        if ($this->envio->paraOCliente($cliente, $chamada->contexto->escritorio)) {
            throw ErroDaFerramenta::envioAutomatico();
        }
    }

    /**
     * Roda o UseCase e troca a exceção de regra por recusa de mensagem fixa. O que não é recusa de regra (erro de
     * banco, programação) segue como está.
     *
     * @template T
     *
     * @param \Closure(): T $gravar
     *
     * @return T
     *
     * @throws ErroDaFerramenta
     */
    public static function gravando(\Closure $gravar, string $mensagemFixa): mixed
    {
        try {
            return $gravar();
        } catch (ClienteNaoEncontradoException|SemPermissaoParaVerClienteException|PastaDeOutroEscritorioException|SemPermissaoParaVerPastaException|SemPermissaoParaEditarPastaException|DocumentoDaPastaNaoEncontradoException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (SemPermissaoParaEditarClienteException) {
            throw ErroDaFerramenta::semPermissao();
        } catch (DocumentoJaCadastradoException) {
            throw ErroDaFerramenta::recusada(self::DOCUMENTO_JA_CADASTRADO);
        } catch (CadastroInvalidoException|ContatoInvalidoException|\DomainException|\InvalidArgumentException) {
            throw ErroDaFerramenta::recusada($mensagemFixa);
        }
    }

    /** @throws ErroDaFerramenta */
    private function exigirPasta(ChamadaDoConector $chamada, string $acao, Pasta $pasta): void
    {
        $this->guarda->exigir($chamada, AtualizarClienteFerramenta::NOME, $acao, $pasta);
        if ($pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        if ($this->segredo->tirar([(int) $pasta->getId()], $chamada->contexto->escritorio)['em_segredo'] > 0) {
            throw ErroDaFerramenta::emSegredo();
        }
    }
}
