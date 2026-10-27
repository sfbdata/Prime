<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\DTO\DadosDaProposta;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\OperacaoDeMensagem;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\UseCase\AlternarItemDoChecklistUseCase;
use App\TrabalhoExterno\UseCase\AjustarValorDoPagamentoUseCase;
use App\TrabalhoExterno\UseCase\PrepararPagamentoDaDemandaUseCase;
use App\TrabalhoExterno\UseCase\RecusarComprovanteDePagamentoUseCase;
use App\TrabalhoExterno\UseCase\AnexarDocumentosUseCase;
use App\TrabalhoExterno\UseCase\ApagarMensagemParaTodosUseCase;
use App\TrabalhoExterno\UseCase\AplicarAcaoNaDemandaUseCase;
use App\TrabalhoExterno\UseCase\AtualizarConfiguracaoCentralUseCase;
use App\TrabalhoExterno\UseCase\AtualizarEscritorioSolicitanteUseCase;
use App\TrabalhoExterno\UseCase\CriarEscritorioSolicitanteUseCase;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use App\TrabalhoExterno\UseCase\AvisarPagamentoNoChatUseCase;
use App\TrabalhoExterno\UseCase\ConfirmarRecebimentoDoPixUseCase;
use App\TrabalhoExterno\UseCase\DespacharNaDemandaUseCase;
use App\TrabalhoExterno\UseCase\DetalharDemandaUseCase;
use App\TrabalhoExterno\UseCase\EnviarComprovanteDePagamentoUseCase;
use App\TrabalhoExterno\UseCase\EnviarMensagemUseCase;
use App\TrabalhoExterno\UseCase\EnviarPropostaUseCase;
use App\TrabalhoExterno\UseCase\LiberarRespostasAoDespachoUseCase;
use App\TrabalhoExterno\UseCase\MarcarConversaLidaUseCase;
use App\TrabalhoExterno\UseCase\MarcarDemandasEmLoteUseCase;
use App\TrabalhoExterno\UseCase\MarcarMensagensEmLoteUseCase;
use App\TrabalhoExterno\UseCase\RegistrarAndamentoUseCase;
use App\TrabalhoExterno\UseCase\ResponderAoDespachoUseCase;
use App\TrabalhoExterno\UseCase\SalvarSolicitacaoUseCase;
use App\TrabalhoExterno\UseCase\SolicitarPeloMasterUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * As escritas do Trabalho Externo (todas JSON, CSRF no header `X-CSRF-Token`, token `ajax`).
 *
 * Ordem em toda ação: perfil (403) → CSRF (403) → UseCase, que localiza a demanda no escopo do
 * perfil (404) e confere a política (403). A resposta traz a demanda já atualizada no recorte de
 * quem pediu, para a tela redesenhar sem segunda chamada.
 */
#[Route('/trabalho-externo/api')]
final class DemandaExternaController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    private const CODIGO = 'TE-\d{1,9}';

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly DetalharDemandaUseCase $detalhar,
    ) {
    }

    #[Route('/solicitacoes', name: 'trabalho_externo_solicitacao', methods: ['POST'])]
    public function salvarSolicitacao(Request $request, SalvarSolicitacaoUseCase $salvar, SolicitarPeloMasterUseCase $peloMaster): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $salvar, $peloMaster): array {
            $dados = json_decode((string) $request->request->get('dados', '{}'), true);
            $dados = DadosDaSolicitacao::deArray(is_array($dados) ? $dados : []);
            $enviar = $request->request->getBoolean('enviar');
            $rascunho = trim((string) $request->request->get('rascunho', ''));

            // O Master só abre solicitação em nome de um escritório dele (spec §9.1); os demais
            // perfis seguem no próprio escritório, e o campo `escritorioId` é ignorado para eles.
            $demanda = $ctx->perfil === Perfil::Master
                ? $peloMaster->executar($ctx, $request->request->getInt('escritorioId'), $dados, $enviar, $this->arquivos($request))
                : $salvar->executar($ctx, $dados, $enviar, $rascunho !== '' ? $rascunho : null, $this->arquivos($request));

            return [
                'codigo' => $demanda->getCodigo(),
                'aviso' => $enviar
                    ? ['titulo' => 'Solicitação enviada', 'texto' => $demanda->getCodigo() . ' foi enviada ao Master para análise.']
                    : ['titulo' => 'Rascunho salvo', 'texto' => $demanda->getCodigo() . ' ficou salva como rascunho.'],
            ];
        });
    }

    #[Route('/demandas/{codigo}/acoes/{acao}', name: 'trabalho_externo_acao', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function acao(Request $request, string $codigo, string $acao, AplicarAcaoNaDemandaUseCase $aplicar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $acao, $aplicar): array {
            $acaoDemanda = AcaoDemanda::tryFrom($acao) ?? throw new DadosInvalidos('Ação desconhecida.');
            $motivo = $this->corpo($request)['motivo'] ?? null;
            $demanda = $aplicar->executar($ctx, $codigo, $acaoDemanda, is_string($motivo) ? $motivo : null);

            return ['demanda' => $demanda !== null ? $this->detalhar->executar($ctx, $codigo) : null];
        });
    }

    #[Route('/demandas/{codigo}/propostas', name: 'trabalho_externo_proposta', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function proposta(Request $request, string $codigo, EnviarPropostaUseCase $enviar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $enviar): array {
            $enviar->executar($ctx, $codigo, DadosDaProposta::deArray($this->corpo($request)));

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    #[Route('/demandas/{codigo}/mensagens', name: 'trabalho_externo_mensagem', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function mensagem(Request $request, string $codigo, EnviarMensagemUseCase $enviar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $enviar): array {
            $corpo = $this->corpo($request);
            $enviar->executar($ctx, $codigo, (string) ($corpo['texto'] ?? ''), (bool) ($corpo['interna'] ?? false));

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    #[Route('/demandas/{codigo}/lida', name: 'trabalho_externo_lida', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function lida(Request $request, string $codigo, MarcarConversaLidaUseCase $marcar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($codigo, $marcar): array {
            $marcar->executar($ctx, $codigo);

            return [];
        });
    }

    /** Barra de lote, estrela e importância (spec VS 18.10.26 §3.1): só para quem marca. */
    #[Route('/demandas/marcacoes', name: 'trabalho_externo_marcacoes', methods: ['POST'])]
    public function marcacoes(Request $request, MarcarDemandasEmLoteUseCase $marcar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $marcar): array {
            $corpo = $this->corpo($request);
            $op = OperacaoDeMarcacao::tryFrom((string) ($corpo['op'] ?? '')) ?? throw new DadosInvalidos('Operação desconhecida.');

            return $marcar->executar($ctx, $this->listaDeTextos($corpo['codigos'] ?? null), $op);
        });
    }

    #[Route('/demandas/{codigo}/mensagens/marcacoes', name: 'trabalho_externo_mensagens_marcacoes', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function marcacoesDoChat(Request $request, string $codigo, MarcarMensagensEmLoteUseCase $marcar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $marcar): array {
            $corpo = $this->corpo($request);
            $op = OperacaoDeMensagem::tryFrom((string) ($corpo['op'] ?? '')) ?? throw new DadosInvalidos('Operação desconhecida.');
            $ids = array_map('intval', $this->listaDeTextos($corpo['ids'] ?? null));

            return $marcar->executar($ctx, $codigo, $ids, $op) + ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    #[Route('/demandas/{codigo}/mensagens/{id}/apagar', name: 'trabalho_externo_mensagem_apagar', methods: ['POST'], requirements: ['codigo' => self::CODIGO, 'id' => '\d{1,9}'])]
    public function apagarMensagem(Request $request, string $codigo, int $id, ApagarMensagemParaTodosUseCase $apagar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($codigo, $id, $apagar): array {
            $apagar->executar($ctx, $codigo, $id);

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    #[Route('/demandas/{codigo}/checklist/{indice}', name: 'trabalho_externo_checklist', methods: ['POST'], requirements: ['codigo' => self::CODIGO, 'indice' => '\d{1,2}'])]
    public function checklist(Request $request, string $codigo, int $indice, AlternarItemDoChecklistUseCase $alternar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($codigo, $indice, $alternar): array {
            $alternar->executar($ctx, $codigo, $indice);

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    #[Route('/demandas/{codigo}/andamentos', name: 'trabalho_externo_andamento', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function andamento(Request $request, string $codigo, RegistrarAndamentoUseCase $registrar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $registrar): array {
            $registrar->executar($ctx, $codigo, (string) ($this->corpo($request)['texto'] ?? ''));

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    #[Route('/demandas/{codigo}/documentos', name: 'trabalho_externo_anexar', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function anexar(Request $request, string $codigo, AnexarDocumentosUseCase $anexar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $anexar): array {
            $anexar->executar($ctx, $codigo, $this->arquivos($request));

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    /** "Despachar" (desenho 06 de 24/10, l.516 e 1394): multipart com `texto`, `natureza`, `prazo` e `documentos[]`. */
    #[Route('/demandas/{codigo}/despachos', name: 'trabalho_externo_despachar', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function despachar(Request $request, string $codigo, DespacharNaDemandaUseCase $despachar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $despachar): array {
            $despachar->executar(
                $ctx,
                $codigo,
                (string) $request->request->get('texto', ''),
                (string) $request->request->get('natureza', ''),
                (string) $request->request->get('prazo', ''),
                $this->arquivos($request),
            );

            return [
                'demanda' => $this->detalhar->executar($ctx, $codigo),
                // P4 do dono (24/10): o despacho aparece no Resumo.
                'toast' => ['titulo' => 'Despacho publicado', 'texto' => 'O solicitante vê o despacho no Resumo e recebe um aviso.'],
            ];
        });
    }

    #[Route('/demandas/{codigo}/despachos/respostas', name: 'trabalho_externo_despacho_responder', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function responderAoDespacho(Request $request, string $codigo, ResponderAoDespachoUseCase $responder): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $responder): array {
            $responder->executar($ctx, $codigo, (string) ($this->corpo($request)['texto'] ?? ''));

            return [
                'demanda' => $this->detalhar->executar($ctx, $codigo),
                'toast' => ['titulo' => 'Resposta enviada', 'texto' => 'A Central BlueJus foi avisada.'],
            ];
        });
    }

    /** "Aceitar resposta" (l.529): `{"liberar": true|false}`. */
    #[Route('/demandas/{codigo}/despachos/aceitar-resposta', name: 'trabalho_externo_despacho_respostas', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function liberarRespostas(Request $request, string $codigo, LiberarRespostasAoDespachoUseCase $liberar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $liberar): array {
            $liberar->executar($ctx, $codigo, (bool) ($this->corpo($request)['liberar'] ?? false));

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    /** Aba Pagamento (desenho 25/10), "Já paguei · enviar comprovante": multipart com UM arquivo em `comprovante`. */
    #[Route('/demandas/{codigo}/pagamento/comprovante', name: 'trabalho_externo_pagamento_comprovante', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function comprovante(Request $request, string $codigo, EnviarComprovanteDePagamentoUseCase $enviar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $enviar): array {
            $arquivo = $request->files->get('comprovante');
            $enviar->executar($ctx, $codigo, $arquivo instanceof UploadedFile ? $arquivo : null);

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    /**
     * A aba Pagamento pede a cobrança PIX dinâmica (gateway M1): `{"novo": true}` só no botão "gerar novo QR". Sem
     * integração ligada não faz nada. Devolve a demanda e, se couber, o aviso para a tela.
     */
    #[Route('/demandas/{codigo}/pagamento/cobranca', name: 'trabalho_externo_pagamento_cobranca', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function prepararCobranca(Request $request, string $codigo, PrepararPagamentoDaDemandaUseCase $preparar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $preparar): array {
            $aviso = $preparar->executar($ctx, $codigo, ($this->corpo($request)['novo'] ?? false) === true);

            return ['demanda' => $this->detalhar->executar($ctx, $codigo), 'avisoPagamento' => $aviso];
        });
    }

    /** "Confirmar recebimento": sem valor no corpo (sai da proposta aceita); `{"motivo": "..."}` só com o PIX dinâmico aguardando. */
    #[Route('/demandas/{codigo}/pagamento/confirmacao', name: 'trabalho_externo_pagamento_confirmar', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function confirmarRecebimento(Request $request, string $codigo, ConfirmarRecebimentoDoPixUseCase $confirmar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $confirmar): array {
            $motivo = $this->corpo($request)['motivo'] ?? null;
            $confirmar->executar($ctx, $codigo, is_scalar($motivo) ? (string) $motivo : null);

            return [
                'demanda' => $this->detalhar->executar($ctx, $codigo),
                'toast' => ['titulo' => 'Pagamento confirmado', 'texto' => 'O solicitante foi avisado no chat e por e-mail.'],
            ];
        });
    }

    /** Lápis do valor (só Master): `{"valor": "1.234,56", "motivo": "..."}`. O valor é lido e validado no servidor. */
    #[Route('/demandas/{codigo}/pagamento/valor', name: 'trabalho_externo_pagamento_valor', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function ajustarValor(Request $request, string $codigo, AjustarValorDoPagamentoUseCase $ajustar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $ajustar): array {
            $corpo = $this->corpo($request);
            $valor = $corpo['valor'] ?? '';
            $motivo = $corpo['motivo'] ?? '';
            $ajustar->executar($ctx, $codigo, is_scalar($valor) ? (string) $valor : '', is_scalar($motivo) ? (string) $motivo : '');

            return [
                'demanda' => $this->detalhar->executar($ctx, $codigo),
                'toast' => ['titulo' => 'Valor alterado', 'texto' => 'O solicitante foi avisado no chat e por e-mail.'],
            ];
        });
    }

    /** "Recusar comprovante" (só Master): `{"motivo": "..."}`. O comprovante sai e o solicitante pode enviar outro. */
    #[Route('/demandas/{codigo}/pagamento/recusa', name: 'trabalho_externo_pagamento_recusar', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function recusarComprovante(Request $request, string $codigo, RecusarComprovanteDePagamentoUseCase $recusar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $recusar): array {
            $motivo = $this->corpo($request)['motivo'] ?? '';
            $recusar->executar($ctx, $codigo, is_scalar($motivo) ? (string) $motivo : '');

            return [
                'demanda' => $this->detalhar->executar($ctx, $codigo),
                'toast' => ['titulo' => 'Comprovante recusado', 'texto' => 'O solicitante foi avisado no chat e por e-mail.'],
            ];
        });
    }

    /** "Enviar no chat": grava o aviso do PIX no chat (sem corpo). */
    #[Route('/demandas/{codigo}/pagamento/aviso', name: 'trabalho_externo_pagamento_avisar', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function avisarPagamento(Request $request, string $codigo, AvisarPagamentoNoChatUseCase $avisar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($codigo, $avisar): array {
            $avisar->executar($ctx, $codigo);

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }

    #[Route('/configuracao', name: 'trabalho_externo_configuracao', methods: ['POST'])]
    public function configuracao(Request $request, AtualizarConfiguracaoCentralUseCase $atualizar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $atualizar): array {
            $atualizar->executar($ctx, $this->corpo($request));

            return [];
        });
    }

    /** "Novo escritório" da Nova solicitação: guarda para o usuário (não cria nem altera escritório da plataforma). */
    #[Route('/escritorios-solicitantes', name: 'trabalho_externo_escritorio_criar', methods: ['POST'])]
    public function criarEscritorio(Request $request, CriarEscritorioSolicitanteUseCase $criar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $criar): array {
            $escritorio = $criar->executar($ctx, DadosDoEscritorioSolicitante::deArray($this->corpo($request)));

            return $this->respostaDoEscritorio($escritorio, 'Escritório salvo', ' fica disponível nas próximas solicitações.');
        });
    }

    /** "Salvar edição": só o dono (id de outro usuário é 404). */
    #[Route('/escritorios-solicitantes/{id}', name: 'trabalho_externo_escritorio_atualizar', methods: ['POST'], requirements: ['id' => '\d{1,9}'])]
    public function atualizarEscritorio(Request $request, int $id, AtualizarEscritorioSolicitanteUseCase $atualizar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $id, $atualizar): array {
            $escritorio = $atualizar->executar($ctx, $id, DadosDoEscritorioSolicitante::deArray($this->corpo($request)));

            return $this->respostaDoEscritorio($escritorio, 'Escritório atualizado', '');
        });
    }

    /** @return array<string, mixed> */
    private function respostaDoEscritorio(EscritorioSolicitante $escritorio, string $titulo, string $complemento): array
    {
        return [
            'escritorio' => $escritorio->paraPainel(),
            'aviso' => ['titulo' => $titulo, 'texto' => $escritorio->getNome() . $complemento],
        ];
    }

    /** @param callable(Contexto): array<string, mixed> $acao */
    private function escrever(Request $request, callable $acao): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);

        return $this->responder(static fn (): array => $acao($ctx));
    }

    /** @return list<string> */
    private function listaDeTextos(mixed $valor): array
    {
        if (!is_array($valor)) {
            throw new DadosInvalidos('Selecione ao menos um item.');
        }

        return array_values(array_map('strval', array_filter($valor, 'is_scalar')));
    }

    /** @return list<UploadedFile> */
    private function arquivos(Request $request): array
    {
        $arquivos = $request->files->get('documentos', []);
        $arquivos = is_array($arquivos) ? $arquivos : [$arquivos];

        return array_values(array_filter($arquivos, static fn (mixed $a): bool => $a instanceof UploadedFile));
    }
}
