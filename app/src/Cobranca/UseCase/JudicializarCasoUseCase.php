<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoJaJudicializadoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PastaNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorClienteDoResponsavel;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\CriarPastaUseCase;

/**
 * Judicializa um Caso de Cobrança (SPEC §16, invariável 16): decisão MANUAL do gestor quando a
 * cobrança extrajudicial vira ação judicial.
 *
 * História: quem = o gestor do escritório; o quê = muda o caso de `ativo` para `judicializado` e o
 * liga a uma Pasta (ligação unidirecional Caso → Pasta); por quê = passar a acompanhar a cobrança
 * pelo processo judicial SEM parar o acompanhamento financeiro — judicializar NÃO encerra o caso (o
 * saldo continua sendo derivado; invariável 16).
 *
 * Desde 2026-08-27 (`docs/specs/cobranca-judicializar-cria-pasta.md`) há DOIS caminhos:
 *
 * - **criar** (o normal): o sistema ABRE a pasta na hora, com número automático, o nome do cliente no
 *   padrão `<fantasia do credor da carteira> - <responsável principal>` (spec §2.5, montado por
 *   `ComporNomeDaPastaJudicial`) e `AÇÃO MONITÓRIA` como ação — os dois vindos do modal, onde o gestor
 *   já os viu e pôde corrigir. Quando o responsável tem CPF, ele também é cadastrado e vira o cliente
 *   principal da pasta (ver ResolvedorClienteDoResponsavel);
 * - **vincular**: o caminho antigo, para o caso já ajuizado antes — uma Pasta EXISTENTE do próprio
 *   escritório.
 *
 * Caso e Pasta são resolvidos por id + tenant (guarda multi-tenant, invariável 1): a Pasta TEM de ser
 * do mesmo escritório do caso, senão é como se não existisse. O caso e os dois eventos
 * ("judicialização" e "vínculo com pasta") são commitados juntos (flush único no último evento).
 *
 * ⚠️ Quem sincroniza a pasta nova com o Drive é o CONTROLLER, não este UseCase — o reconciliador do
 * Sync chama os mesmos UseCases e um dispatch aqui dispararia durante a própria reconciliação.
 */
final class JudicializarCasoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly CriarPastaUseCase $criarPasta,
        private readonly ResolvedorClienteDoResponsavel $resolvedorCliente,
        private readonly ComporNomeDaPastaJudicial $comporNome,
    ) {
    }

    public function executar(JudicializarCasoInput $input, Tenant $tenant, User $usuario): CasoCobranca
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não aceita novos movimentos (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Judicialização é transição única: revincular pasta não é operação do MVP (SPEC §16).
        if ($caso->estaJudicializado()) {
            throw new CasoJaJudicializadoException((int) $caso->getId());
        }

        // As guardas vêm ANTES de qualquer escrita: criar a pasta de um caso encerrado e só depois
        // recusar deixaria uma pasta órfã no acervo a cada clique errado.
        $pasta = $input->ehModoCriar()
            ? $this->abrirPastaJudicial($caso, $tenant, $usuario)
            : $this->pastaExistenteDoTenant($input, $tenant);

        // As três ações valem nos DOIS caminhos (decisão do dono, 02/09). Vincular deixava a pasta
        // exatamente como o usuário a tinha digitado — sem cliente e com o nome de quem ele quis —,
        // e 26 das 30 pastas judicializadas vieram por aí: era o caminho comum, não o secundário.
        $this->normalizarPastaJudicial($pasta, $caso, $tenant, $usuario);

        $caso->setPastaJudicial($pasta);
        $caso->setStatus(StatusCaso::Judicializado);

        // Persiste sem flush; o registro do último evento fecha a transação (caso + eventos juntos).
        $this->casoRepository->salvar($caso);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::Judicializacao,
            $usuario,
            'Caso judicializado.',
        );

        // O histórico distingue os dois caminhos: quem lê precisa saber se a pasta nasceu aqui ou já
        // existia — são consequências diferentes se ela depois aparecer errada.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::VinculoPasta,
            $usuario,
            sprintf(
                $input->ehModoCriar() ? 'Pasta %s criada e vinculada.' : 'Vínculo com a pasta %s.',
                $pasta->getNup() ?? (string) $pasta->getId(),
            ),
            ['pastaId' => $pasta->getId()],
            flush: true,
        );

        return $caso;
    }

    /**
     * Abre a pasta judicial do caso. O NUP vai vazio de propósito: quem numera é o
     * `GerarNumeroDePasta`, dentro da transação do CriarPastaUseCase (é lá que a trava por escritório
     * tem validade).
     */
    private function abrirPastaJudicial(CasoCobranca $caso, Tenant $tenant, User $usuario): Pasta
    {
        // Nasce já com os valores certos; a normalização depois é idempotente aqui. O formulário
        // NÃO é lido: seus campos são somente-leitura desde 02/09, e quem decide o nome é o caso.
        return $this->criarPasta->executar(
            new CriarPastaDTO(
                nomeCliente: $this->comporNome->paraCaso($caso),
                nomeAcao: JudicializarCasoInput::ACAO_PADRAO,
            ),
            $usuario,
            // O tenant é o DO CASO, não o da sessão: é o caso que manda de quem é a pasta.
            $tenant,
        );
    }

    /**
     * As três ações que deixam a pasta no padrão da cobrança, iguais nos dois caminhos:
     * nome `CREDOR - DEVEDOR`, ação `AÇÃO MONITÓRIA` e o responsável como cliente principal.
     *
     * ⚠️ Sobrescreve o que houver — inclusive uma ação diferente e um nome digitado à mão. É o
     * pedido do dono: a pasta judicializada se identifica pelo caso, não pelo que alguém digitou.
     */
    private function normalizarPastaJudicial(Pasta $pasta, CasoCobranca $caso, Tenant $tenant, User $usuario): void
    {
        $nome = $this->comporNome->paraCaso($caso);

        // Só sobrescreve quando há o que compor. Sem pessoa cobrada, manter o nome que estava é
        // melhor que apagá-lo — apagar em silêncio foi o defeito que custou 3 pastas até 01/09.
        if ($nome !== null) {
            $pasta->setNomeCliente($nome);
        }

        $pasta->setNomeAcao(JudicializarCasoInput::ACAO_PADRAO);

        // Sem CPF na ficha do responsável não há identidade a cadastrar, e a pasta segue sem cliente
        // — o dado que falta é da ficha, não desta operação (spec §3.1). Nunca inventa.
        $cliente = $this->resolvedorCliente->resolver($caso->getPessoaCobradaAtual(), $tenant, $usuario);

        if ($cliente === null) {
            return;
        }

        $pasta->addCliente($cliente);
        // `addCliente` só marca o principal no PRIMEIRO vínculo. A pasta VINCULADA pode já ter
        // outros clientes, e o responsável do caso tem de ser o principal de qualquer maneira.
        $pasta->definirClientePrincipal($cliente);
    }

    /**
     * Guarda crítica do caminho antigo: a Pasta tem de ser do MESMO tenant do caso (resolve por id +
     * tenant). Pasta inexistente OU de outro escritório cai no mesmo erro (não vaza existência alheia).
     */
    private function pastaExistenteDoTenant(JudicializarCasoInput $input, Tenant $tenant): Pasta
    {
        $pasta = $this->pastaRepository->findOneBy(['id' => (int) $input->pastaId, 'tenant' => $tenant]);

        if ($pasta === null) {
            throw new PastaNaoEncontradaException((int) $input->pastaId);
        }

        return $pasta;
    }
}
