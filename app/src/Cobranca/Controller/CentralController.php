<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\AtividadePessoaOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\UsuarioCobrancaRepository;
use App\Cobranca\UseCase\MontarAtividadeEquipeUseCase;
use App\Cobranca\UseCase\MontarDetalheAtividadePessoaUseCase;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP da Central de Acompanhamento da Cobrança (spec `cobranca-central-acompanhamento.md`,
 * Fatia 1). Duas rotas de LEITURA (GET): a central com as 4 abas — só "Atividade" preenchida nesta
 * fatia — e o detalhe de uma pessoa, carregado sob demanda.
 *
 * Controller FINO no padrão do `DashboardController`: gate de módulo `cobrancas` (decisão do dono:
 * "todos veem tudo" dentro do tenant, sem permissão nova), filtros resolvidos tenant-safe (carteira de
 * outro escritório → 404, não lista vazia) e delegação aos UseCases. Nenhuma regra de negócio aqui.
 * Sem escrita → sem CSRF. Tenant nunca vem do request.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class CentralController extends AbstractController
{
    use AutorizacaoCobranca;

    /** Valor de `{chave}` na rota de detalhe para a linha dos eventos órfãos. */
    private const CHAVE_SEM_RESPONSAVEL = 'sem-responsavel';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly UsuarioCobrancaRepository $usuarioRepository,
        private readonly MontarAtividadeEquipeUseCase $montarAtividade,
        private readonly MontarDetalheAtividadePessoaUseCase $montarDetalhe,
    ) {
    }

    #[Route('/central', name: 'cobranca_central', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->resolverCarteira($request, $tenant);
        $periodo = $this->resolverPeriodo($request);

        $atividade = $this->montarAtividade->executar($tenant, $periodo['inicio'], $periodo['fimExclusivo'], $carteira);

        return $this->render('cobranca/central/index.html.twig', [
            'atividade' => $atividade,
            'carteiras' => $this->carteiraRepository->opcoesFacetaDoTenant($tenant),
            'carteiraId' => $carteira?->getId(),
            'periodo' => $periodo,
        ]);
    }

    /**
     * Detalhe de uma pessoa no mesmo recorte da tabela. Responde um FRAGMENTO (sem layout): a tela o
     * injeta abaixo da linha clicada, e é por ser rota própria que a consulta da tabela não cresce com o
     * tamanho da equipe (spec §8).
     */
    #[Route('/central/atividade/{chave}', name: 'cobranca_central_atividade_detalhe', requirements: ['chave' => '\d+|sem-responsavel'], methods: ['GET'])]
    public function detalheAtividade(Request $request, string $chave): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->resolverCarteira($request, $tenant);
        $periodo = $this->resolverPeriodo($request);

        [$usuarioId, $nome] = $this->resolverPessoa($chave, $tenant);

        $detalhe = $this->montarDetalhe->executar($tenant, $usuarioId, $nome, $periodo['inicio'], $periodo['fimExclusivo'], $carteira);

        return $this->render('cobranca/central/_detalhe_pessoa.html.twig', ['detalhe' => $detalhe]);
    }

    /**
     * Resolve a pessoa do detalhe. `sem-responsavel` não tem dono por definição; qualquer outro valor é
     * um id que SÓ vale se pertencer ao escritório — id de outro tenant vira 404 e não o nome de alguém
     * de fora numa tela vazia (anti-IDOR, spec §7).
     *
     * @return array{0: ?int, 1: string}
     */
    private function resolverPessoa(string $chave, Tenant $tenant): array
    {
        if ($chave === self::CHAVE_SEM_RESPONSAVEL) {
            return [null, AtividadePessoaOutput::SEM_RESPONSAVEL];
        }

        $usuario = $this->usuarioRepository->doTenantPorId((int) $chave, $tenant);
        if ($usuario === null) {
            throw $this->createNotFoundException('Usuário não encontrado neste escritório.');
        }

        return [$usuario['id'], $usuario['nome']];
    }

    /**
     * Filtro opcional por carteira, idêntico ao do painel: só resolve se o id vier no request, sempre
     * tenant-safe — id de outro escritório ou inexistente vira 404 (anti-IDOR).
     */
    private function resolverCarteira(Request $request, Tenant $tenant): ?Carteira
    {
        $id = trim((string) $request->query->get('carteira', ''));
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant((int) $id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        return $carteira;
    }

    /**
     * Período da central. Padrão HOJE (spec §6) — a pergunta que a tela responde é "o que a equipe está
     * fazendo", e a semana inteira dilui o dia de hoje.
     *
     * A faixa é fechada no início e ABERTA no fim (`>= inicio AND < fimExclusivo`), sempre no fuso da
     * aplicação: com fim inclusivo por `23:59:59` um evento gravado no último segundo do dia sumiria.
     * Atalho inválido ou data ilegível cai no padrão, nunca em erro.
     *
     * @return array{atalho: string, inicio: \DateTimeImmutable, fimExclusivo: \DateTimeImmutable, inicioTexto: string, fimTexto: string}
     */
    private function resolverPeriodo(Request $request): array
    {
        $hoje = new \DateTimeImmutable('today');
        $atalho = (string) $request->query->get('periodo', 'hoje');

        [$inicio, $fimExclusivo] = match ($atalho) {
            'ontem' => [$hoje->modify('-1 day'), $hoje],
            'semana' => [$hoje->modify('monday this week'), $hoje->modify('monday this week')->modify('+7 days')],
            'mes' => [$hoje->modify('first day of this month'), $hoje->modify('first day of next month')],
            'personalizado' => $this->periodoPersonalizado($request, $hoje),
            default => [$hoje, $hoje->modify('+1 day')],
        };

        if (!\in_array($atalho, ['ontem', 'semana', 'mes', 'personalizado'], true)) {
            $atalho = 'hoje';
        }

        return [
            'atalho' => $atalho,
            'inicio' => $inicio,
            'fimExclusivo' => $fimExclusivo,
            'inicioTexto' => $inicio->format('Y-m-d'),
            // O campo do formulário mostra o último dia INCLUÍDO, que é o que o gestor digitou.
            'fimTexto' => $fimExclusivo->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function periodoPersonalizado(Request $request, \DateTimeImmutable $hoje): array
    {
        $inicio = $this->parseData((string) $request->query->get('inicio', '')) ?? $hoje;
        $fim = $this->parseData((string) $request->query->get('fim', '')) ?? $hoje;

        // Datas trocadas seriam uma faixa vazia (tela zerada sem explicar por quê): endireita.
        if ($inicio > $fim) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        return [$inicio, $fim->modify('+1 day')];
    }

    private function parseData(string $valor): ?\DateTimeImmutable
    {
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $data === false ? null : $data;
    }
}
