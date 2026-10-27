<?php

declare(strict_types=1);

namespace App\Central\Controller;

use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\EnvioNaConversaComAIaException;
use App\Central\Exception\ParticipacaoAlteradaAoMesmoTempoException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\AusenciaRecusadaException;
use App\Central\Service\AcessoACentral;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Exception\SemPermissaoNoGrupoException;
use App\Central\UseCase\AdicionarParticipanteUseCase;
use App\Central\UseCase\CriarGrupoUseCase;
use App\Central\UseCase\DefinirPreferenciasDaConversaUseCase;
use App\Central\UseCase\ListarParticipantesUseCase;
use App\Central\UseCase\RemoverParticipanteUseCase;
use App\Central\UseCase\RenomearGrupoUseCase;
use App\Central\UseCase\SairDoGrupoUseCase;
use App\Central\UseCase\AbrirConversaUseCase;
use App\Central\UseCase\EnviarMensagemUseCase;
use App\Central\UseCase\ListarContatosDaCentralUseCase;
use App\Central\UseCase\ListarConversasUseCase;
use App\Central\UseCase\ListarMensagensDaConversaUseCase;
use App\Central\UseCase\MarcarConversaComoLidaUseCase;
use App\Central\UseCase\MarcarConversaComoNaoLidaUseCase;
use App\Central\UseCase\ObterNovidadesDaCentralUseCase;
use App\Central\DTO\MembroDaEquipeOutput;
use App\Central\UseCase\ListarEquipeDaCentralUseCase;
use App\Central\UseCase\AlterarAusenciaDoEscritorioUseCase;
use App\Central\UseCase\ListarPresencaDoEscritorioUseCase;
use App\Central\UseCase\RegistrarPresencaUseCase;
use App\Central\UseCase\ListarAvisosDaCentralUseCase;
use App\Central\UseCase\MarcarAvisoUseCase;
use App\Central\UseCase\ResolverAvisoUseCase;
use App\Central\UseCase\MarcarTudoComoLidoUseCase;
use App\Central\UseCase\LimparAvisosLidosUseCase;
use App\Central\UseCase\ExcluirAvisoUseCase;
use App\Central\UseCase\LimparConversaUseCase;
use App\Central\Exception\AvisoNaoEncontradoException;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Central de Comunicação A1 — conversas 1:1 persistidas (`docs/specs/central-comunicacao-a1.md`) — e A2: @menção,
 * responder, marcar como não lida (`docs/specs/central-comunicacao-a2.md`) — e B: grupos e preferências individuais
 * (`docs/specs/central-comunicacao-b.md`).
 *
 * Toda a regra de acesso mora nos UseCases (vínculo ativo + participação); aqui só HTTP: ler o pedido,
 * chamar, traduzir a exceção de domínio no status. Escrita exige o token CSRF `ajax` no cabeçalho
 * `X-CSRF-Token` (o interceptador do layout injeta em todo fetch same-origin).
 */
#[Route('/central')]
final class CentralController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    /** Maior valor de uma coluna `int` do PostgreSQL — o tipo dos ids da Central. */
    private const MAIOR_ID = 2_147_483_647;

    /**
     * Teto do corpo BRUTO da mensagem, antes de resolver as menções: o visível é 4.000 (`Mensagem::TAMANHO_MAXIMO`) e
     * 20 tokens somam poucas centenas a mais; um corpo muito maior só faria o resolvedor trabalhar à toa.
     */
    private const TAMANHO_BRUTO_MAXIMO = 16_000;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListarContatosDaCentralUseCase $listarContatos,
        private readonly ListarConversasUseCase $listarConversas,
        private readonly AbrirConversaUseCase $abrirConversa,
        private readonly ListarMensagensDaConversaUseCase $listarMensagens,
        private readonly EnviarMensagemUseCase $enviarMensagem,
        private readonly MarcarConversaComoLidaUseCase $marcarComoLida,
        private readonly ObterNovidadesDaCentralUseCase $obterNovidades,
        private readonly MarcarConversaComoNaoLidaUseCase $marcarComoNaoLida,
        private readonly CriarGrupoUseCase $criarGrupo,
        private readonly ListarParticipantesUseCase $listarParticipantes,
        private readonly AdicionarParticipanteUseCase $adicionarParticipante,
        private readonly RemoverParticipanteUseCase $removerParticipante,
        private readonly SairDoGrupoUseCase $sairDoGrupo,
        private readonly RenomearGrupoUseCase $renomearGrupo,
        private readonly DefinirPreferenciasDaConversaUseCase $definirPreferencias,
        private readonly ListarEquipeDaCentralUseCase $listarEquipe,
        private readonly RegistrarPresencaUseCase $registrarPresenca,
        private readonly ListarPresencaDoEscritorioUseCase $presencaDoEscritorio,
        private readonly ListarAvisosDaCentralUseCase $listarAvisos,
        private readonly MarcarAvisoUseCase $marcarAviso,
        private readonly ResolverAvisoUseCase $resolverAviso,
        private readonly MarcarTudoComoLidoUseCase $marcarTudo,
        private readonly LimparAvisosLidosUseCase $limparLidos,
        private readonly ExcluirAvisoUseCase $excluirAviso,
        private readonly LimparConversaUseCase $limparConversa,
    ) {
    }

    #[Route('', name: 'central_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $this->listarContatos->executar($this->usuario(), $this->tenantContext->getCurrentTenant());
        } catch (SemAcessoACentralException) {
            throw $this->createAccessDeniedException('Sem acesso à Central neste escritório.');
        }

        // `?conversa=N` é o link da notificação de menção. Só um id bem formado chega à tela, e a tela só abre se
        // a conversa estiver na MINHA lista — o id sozinho não abre nada.
        return $this->render('central/index.html.twig', [
            'eu' => $this->usuario(),
            'abrirConversa' => self::idDaQuery($request, 'conversa'),
        ]);
    }

    #[Route('/contatos', name: 'central_contatos', methods: ['GET'])]
    public function contatos(): JsonResponse
    {
        return $this->responder(fn () => ['contatos' => $this->listarContatos->executar($this->usuario(), $this->tenantContext->getCurrentTenant())]);
    }

    #[Route('/conversas', name: 'central_conversas', methods: ['GET'])]
    public function conversas(): JsonResponse
    {
        return $this->responder(fn () => ['conversas' => $this->listarConversas->executar($this->usuario(), $this->tenantContext->getCurrentTenant())]);
    }

    #[Route('/conversas', name: 'central_conversa_abrir', methods: ['POST'])]
    public function abrir(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $destinatarioId = $this->inteiroDoCorpo($request, 'destinatarioId');

        return $this->responder(fn () => ['conversa' => $this->abrirConversa->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $destinatarioId)]);
    }

    #[Route('/conversas/{id}/mensagens', name: 'central_mensagens', requirements: ['id' => '\d{1,9}'], methods: ['GET'])]
    public function mensagens(int $id, Request $request): JsonResponse
    {
        $antesDe = self::idDaQuery($request, 'antesDe');

        return $this->responder(fn () => $this->listarMensagens->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $antesDe));
    }

    #[Route('/conversas/{id}/mensagens', name: 'central_mensagem_enviar', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function enviar(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $corpo = $this->corpo($request);
        $conteudo = $corpo['conteudo'] ?? null;
        if (!is_string($conteudo)) {
            return $this->json(['erro' => 'Mensagem inválida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (strlen($conteudo) > self::TAMANHO_BRUTO_MAXIMO * 4) {   // bytes: 4 por caractere no pior caso UTF-8
            return $this->json(['erro' => 'A mensagem é longa demais.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Ausente/null = mensagem comum. Presente e torto (texto, zero, grande demais) vira 0, que nenhuma mensagem
        // tem — o UseCase recusa com 422, em vez de mandar a mensagem sem a citação pedida.
        $respostaAId = null;
        if (($corpo['respostaAId'] ?? null) !== null) {
            $respostaAId = $this->inteiroDoCorpo($request, 'respostaAId');
        }

        return $this->responder(
            fn () => ['mensagem' => $this->enviarMensagem->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $conteudo, $respostaAId)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/conversas/{id}/lida', name: 'central_conversa_lida', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function lida(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $ateId = $this->inteiroDoCorpo($request, 'ateId');

        return $this->responder(fn () => ['ultimaLidaId' => $this->marcarComoLida->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $ateId)]);
    }

    #[Route('/conversas/{id}/nao-lida', name: 'central_conversa_nao_lida', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function naoLida(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->marcarComoNaoLida->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);

            return ['marcadaNaoLida' => true];
        });
    }

    /** Botão direito → "Excluir": limpa a conversa só para quem pede (o colega segue vendo tudo). */
    #[Route('/conversas/{id}/limpar', name: 'central_conversa_limpar', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function limparConversa(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->limparConversa->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);

            return ['ok' => true];
        });
    }

    #[Route('/grupos', name: 'central_grupo_criar', methods: ['POST'])]
    public function criarGrupo(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $corpo = $this->corpo($request);
        $nome = $corpo['nome'] ?? null;
        $ids = $corpo['participantes'] ?? null;
        if (!is_string($nome) || !is_array($ids) || count($ids) > 200) {
            return $this->json(['erro' => 'Pedido inválido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Id torto (texto, negativo, acima do int do banco) recusa o pedido inteiro, como o id de outro escritório —
        // criar o grupo calado sem a pessoa pedida seria pior.
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0 || $id > self::MAIOR_ID) {
                return $this->json(['erro' => 'Participante inválido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        $ids = array_values($ids);

        return $this->responder(
            fn () => ['grupo' => $this->criarGrupo->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $nome, $ids)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/conversas/{id}/participantes', name: 'central_participantes', requirements: ['id' => '\d{1,9}'], methods: ['GET'])]
    public function participantes(int $id): JsonResponse
    {
        return $this->responder(fn () => $this->listarParticipantes->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id));
    }

    #[Route('/conversas/{id}/participantes', name: 'central_participante_adicionar', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function adicionarParticipante(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $usuarioId = $this->inteiroDoCorpo($request, 'usuarioId');

        return $this->responder(
            fn () => ['participante' => $this->adicionarParticipante->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $usuarioId)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/conversas/{id}/participantes/{usuarioId}', name: 'central_participante_remover', requirements: ['id' => '\d{1,9}', 'usuarioId' => '\d{1,9}'], methods: ['DELETE'])]
    public function removerParticipante(int $id, int $usuarioId, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id, $usuarioId): array {
            $this->removerParticipante->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $usuarioId);

            return ['removido' => $usuarioId];
        });
    }

    #[Route('/conversas/{id}/sair', name: 'central_grupo_sair', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function sair(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->sairDoGrupo->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);

            return ['saiu' => true];
        });
    }

    #[Route('/conversas/{id}/renomear', name: 'central_grupo_renomear', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function renomear(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $nome = $this->corpo($request)['nome'] ?? null;
        if (!is_string($nome)) {
            return $this->json(['erro' => 'Nome inválido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->responder(fn () => ['grupo' => $this->renomearGrupo->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $nome)]);
    }

    #[Route('/conversas/{id}/preferencias', name: 'central_preferencias', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function preferencias(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $corpo = $this->corpo($request);
        $valor = static function (string $chave) use ($corpo): ?bool {
            return isset($corpo[$chave]) && is_bool($corpo[$chave]) ? $corpo[$chave] : null;
        };

        return $this->responder(fn () => ['preferencias' => $this->definirPreferencias->executar(
            $this->usuario(),
            $this->tenantContext->getCurrentTenant(),
            $id,
            $valor('fixada'),
            $valor('silenciada'),
            $valor('arquivada'),
        )]);
    }

    #[Route('/novidades', name: 'central_novidades', methods: ['GET'])]
    public function novidades(Request $request): JsonResponse
    {
        // Sem `desde` (ou com valor inválido) = primeira chamada da tela, só o cursor; `desde=0` é cursor de verdade.
        // O inválido não vira 0: isso devolveria o histórico inteiro como novidade.
        $desde = self::idDaQuery($request, 'desde', aceitaZero: true);

        return $this->responder(fn () => $this->obterNovidades->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $desde));
    }

    /**
     * Painel "Equipe · em aberto" e a pílula "N on-line" (`docs/specs/central-comunicacao-painel-presenca.md`): os
     * colegas ativos com o que têm em aberto e a presença. A foto vira URL aqui (a rota dela só serve no escritório).
     */
    #[Route('/equipe', name: 'central_equipe', methods: ['GET'])]
    public function equipe(): JsonResponse
    {
        return $this->responder(function (): array {
            $saida = $this->listarEquipe->executar($this->usuario(), $this->tenantContext->getCurrentTenant());

            return [
                'equipe' => array_map(fn (MembroDaEquipeOutput $m): array => [
                    'id' => $m->id,
                    'nome' => $m->nome,
                    'cargo' => $m->cargo,
                    'foto' => $m->foto !== null ? $this->generateUrl('app_profile_foto_serve', ['nome' => $m->foto]) : null,
                    'eu' => $m->eu,
                    'emAberto' => $m->emAberto,
                    'status' => $m->status,
                    'online' => $m->online,
                    'desde' => $m->desde,
                    'vistoEm' => $m->vistoEm,
                ], $saida->equipe),
                'online' => $saida->online,
                'total' => $saida->total,
            ];
        });
    }

    /** Avisos do sino na Caixa (F1, `docs/specs/central-comunicacao-f1-avisos.md`). */
    #[Route('/avisos', name: 'central_avisos', methods: ['GET'])]
    public function avisos(): JsonResponse
    {
        return $this->responder(fn () => ['avisos' => $this->listarAvisos->executar($this->usuario(), $this->tenantContext->getCurrentTenant())]);
    }

    #[Route('/avisos/{id}/lido', name: 'central_aviso_lido', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function avisoLido(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->marcarAviso->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, true);

            return ['ok' => true];
        });
    }

    #[Route('/avisos/{id}/nao-lido', name: 'central_aviso_nao_lido', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function avisoNaoLido(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->marcarAviso->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, false);

            return ['ok' => true];
        });
    }

    /** Dar baixa / reabrir um aviso (F3, `docs/specs/central-comunicacao-f3-menu.md` §2). */
    #[Route('/avisos/{id}/resolver', name: 'central_aviso_resolver', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function resolverAviso(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->resolverAviso->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, true);

            return ['ok' => true];
        });
    }

    #[Route('/avisos/{id}/reabrir', name: 'central_aviso_reabrir', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function reabrirAviso(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->resolverAviso->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, false);

            return ['ok' => true];
        });
    }

    /** Botão direito → "Excluir" num aviso: apaga a notificação da própria pessoa (sem confirmação, como no desenho L5586). */
    #[Route('/avisos/{id}/excluir', name: 'central_aviso_excluir', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function excluirAviso(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($id): array {
            $this->excluirAviso->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);

            return ['ok' => true];
        });
    }

    /** ⋮ "Marcar todas como lidas" (F3): avisos da Caixa e conversas, só minhas, neste escritório. */
    #[Route('/ler-tudo', name: 'central_ler_tudo', methods: ['POST'])]
    public function lerTudo(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(function (): array {
            $this->marcarTudo->executar($this->usuario(), $this->tenantContext->getCurrentTenant());

            return ['ok' => true];
        });
    }

    /** ⋮ "Limpar notificações lidas" (F3): apaga as já lidas da Caixa (a tela confirma antes). */
    #[Route('/avisos/limpar-lidos', name: 'central_avisos_limpar', methods: ['POST'])]
    public function limparLidos(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);

        return $this->responder(fn () => ['apagadas' => $this->limparLidos->executar($this->usuario(), $this->tenantContext->getCurrentTenant())]);
    }

    /**
     * Batimento de presença (F2): a cada 20 s e quando o status muda. Responde também quem está on-line no escritório
     * (só ids, status e minutos), para o selo do cabeçalho não pedir a equipe a cada 20 s.
     */
    #[Route('/presenca', name: 'central_presenca', methods: ['POST'])]
    public function presenca(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $corpo = $this->corpo($request);
        $status = isset($corpo['status']) && is_string($corpo['status']) ? $corpo['status'] : '';

        return $this->responder(fn () => [
            'status' => $this->registrarPresenca->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $status),
            'online' => $this->presencaDoEscritorio->executar($this->usuario(), $this->tenantContext->getCurrentTenant()),
        ]);
    }

    /**
     * Fase 3 da presença (spec `presenca-e-ponto-no-cabecalho.md` §4): o primário escolhe depois de quanto tempo sem
     * atividade a equipe aparece ausente (desenho 03 L.306). JSON `{minutos}`; 403 para quem não é primário, 422 para
     * valor fora da lista.
     */
    #[Route('/presenca/ausencia', name: 'central_presenca_ausencia', methods: ['POST'])]
    public function ausencia(Request $request, AlterarAusenciaDoEscritorioUseCase $alterar, AcessoACentral $acesso): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $corpo = $this->corpo($request);
        $minutos = isset($corpo['minutos']) && is_int($corpo['minutos']) ? $corpo['minutos'] : 0;
        try {
            return $this->responder(fn () => [
                'minutos' => $alterar->executar($this->usuario(), $acesso->exigirVinculoAtivo($this->usuario(), $this->tenantContext->getCurrentTenant()), $minutos),
            ]);
        } catch (AusenciaRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], match ($e->motivo) {
                AusenciaRecusadaException::SEM_PERMISSAO => Response::HTTP_FORBIDDEN,
                AusenciaRecusadaException::CONCORRENTE => Response::HTTP_CONFLICT,
                default => Response::HTTP_UNPROCESSABLE_ENTITY,
            });
        }
    }

    /** @param callable(): mixed $acao */
    private function responder(callable $acao, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            return $this->json($acao(), $status);
        } catch (SemAcessoACentralException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (ConversaNaoEncontradaException) {
            return $this->json(['erro' => 'Conversa não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (AvisoNaoEncontradoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (PedidoInvalidoNaCentralException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SemPermissaoNoGrupoException $e) {
            // 403 de regra do grupo, não de sessão: o `codigo` diz à tela para não tratar como "sessão encerrada".
            return $this->json(['erro' => $e->getMessage(), 'codigo' => 'sem_permissao_no_grupo'], Response::HTTP_FORBIDDEN);
        } catch (EnvioNaConversaComAIaException $e) {
            return $this->json(['erro' => $e->getMessage(), 'codigo' => 'conversa_ia'], Response::HTTP_FORBIDDEN);
        } catch (ConversaCriadaAoMesmoTempoException|ParticipacaoAlteradaAoMesmoTempoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /** @return array<string, mixed> */
    private function corpo(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Inteiro positivo do corpo JSON, dentro do `int` do PostgreSQL; ausente, torto ou grande demais vira 0, que
     * nenhum id tem (o UseCase recusa) — em vez de chegar ao banco e virar 500.
     */
    private function inteiroDoCorpo(Request $request, string $chave): int
    {
        $valor = $this->corpo($request)[$chave] ?? null;

        return is_int($valor) && $valor > 0 && $valor <= self::MAIOR_ID ? $valor : 0;
    }

    private static function idDaQuery(Request $request, string $chave, bool $aceitaZero = false): ?int
    {
        $valor = $request->query->get($chave);
        if (!is_string($valor) || !ctype_digit($valor) || strlen($valor) > 10 || (int) $valor > self::MAIOR_ID) {
            return null;
        }

        return ((int) $valor > 0 || $aceitaZero) ? (int) $valor : null;
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $usuario;
    }
}
