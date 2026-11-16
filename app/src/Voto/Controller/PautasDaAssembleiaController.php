<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\PautaInput;
use App\Voto\DTO\RegrasDaPautaInput;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\Service\ModelosDePautas;
use App\Voto\UseCase\AdicionarPautaUseCase;
use App\Voto\UseCase\AplicarModeloDePautasUseCase;
use App\Voto\UseCase\DuplicarPautaUseCase;
use App\Voto\UseCase\EditarPautaUseCase;
use App\Voto\UseCase\MoverPautaUseCase;
use App\Voto\UseCase\ObterAssembleiaUseCase;
use App\Voto\UseCase\RemoverPautaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pautas da assembleia (spec voto-assembleia-condominio, seção 1.3; insumo do desenho §2, etapa 4). Em rascunho, a
 * tela inclui, altera e remove pautas (opções numeradas, padrão 1 Sim e 2 Não; a abstenção é automática). Depois de
 * publicada, a mesma tela é a mesa: cada pauta com as ações de abrir, prazo e encerrar (MesaDaAssembleiaController).
 *
 * No desenho, as pautas abertas na etapa 4 ficam em edição juntas e nada se perde entre elas. Por isso a tela grava por
 * fetch (public/js/voto-assembleia.js): o "Salvar" de uma pauta não recarrega a página, e as pautas alteradas são
 * gravadas antes de qualquer ação que recarrega. O pedido por fetch (X-Requested-With) recebe JSON com `ok` e
 * `mensagem` (o texto do toast): 200, 422 na recusa de validação e 404 na pauta que não existe mais (a tela deixa
 * seguir sem ela) ou 403 na sessão expirada (a tela tenta de novo); o envio comum segue com o toast na sessão e o redirecionamento.
 */
#[Route('/voto/assembleias/{id}/pautas', requirements: ['id' => '\d+', 'pauta' => '\d+', 'direcao' => 'cima|baixo'])]
final class PautasDaAssembleiaController extends AbstractController
{
    /** "Cargos e pautas frequentes" do desenho: cada um cria a pauta padrão com esse nome. */
    public const ATALHOS = ['Síndico(a)', 'Subsíndico(a)', 'Conselho fiscal', 'Aprovação de contas', 'Previsão orçamentária', 'Obra ou benfeitoria', 'Alteração da convenção'];

    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_pautas', methods: ['GET'])]
    public function pautas(int $id, ObterAssembleiaUseCase $obter): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $assembleia = $obter->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $this->render('voto/assembleia/pautas.html.twig', [
            'assembleia' => $assembleia,
            'atalhos' => self::ATALHOS,
            'modelos' => ModelosDePautas::modelos(),
            'opcoes_padrao' => PautaInput::OPCOES_PADRAO,
            'opcoes_padrao_eleicao' => [['numero' => '1', 'rotulo' => ''], ['numero' => '2', 'rotulo' => '']],
            'agora' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('', name: 'voto_assembleia_pauta_criar', methods: ['POST'])]
    public function criar(int $id, Request $request, AdicionarPautaUseCase $adicionar): Response
    {
        $tenant = $this->guarda->exigir();
        $atalho = (string) $request->request->get('atalho', '');
        $input = self::inputOuAtalho($request, $atalho);

        return $this->responder($request, $id, static function () use ($adicionar, $id, $input, $tenant): void {
            $adicionar->executar($tenant, $id, $input);
        }, 'voto.assembleia.gestor.aviso.pauta_incluida', ['id' => $id]);
    }

    /** "Modelos prontos" do desenho: cria de uma vez as pautas do modelo, em rascunho. */
    #[Route('/modelo', name: 'voto_assembleia_pauta_modelo', methods: ['POST'])]
    public function aplicarModelo(int $id, Request $request, AplicarModeloDePautasUseCase $aplicar): Response
    {
        $tenant = $this->guarda->exigir();
        $modelo = (string) $request->request->get('modelo', '');

        return $this->responder($request, $id, function () use ($aplicar, $id, $modelo, $tenant): string {
            $titulos = $aplicar->executar($tenant, $id, $modelo);

            return $this->tradutor->trans('voto.assembleia.gestor.modelos.aplicado', ['pautas' => implode(', ', $titulos)]);
        }, 'voto.assembleia.gestor.modelos.aplicado', ['id' => $id]);
    }

    #[Route('/{pauta}/editar', name: 'voto_assembleia_pauta_editar', methods: ['POST'])]
    public function editar(int $id, int $pauta, Request $request, EditarPautaUseCase $editar): Response
    {
        $tenant = $this->guarda->exigir();
        $input = self::input($request);

        return $this->responder($request, $id, static function () use ($editar, $id, $input, $pauta, $tenant): void {
            $editar->executar($tenant, $id, $pauta, $input);
        }, 'voto.assembleia.gestor.aviso.pauta_salva', ['id' => $id, '_fragment' => 'pauta-' . $pauta]);
    }

    #[Route('/{pauta}/remover', name: 'voto_assembleia_pauta_remover', methods: ['POST'])]
    public function remover(int $id, int $pauta, Request $request, RemoverPautaUseCase $remover): Response
    {
        $tenant = $this->guarda->exigir();

        return $this->responder($request, $id, static function () use ($id, $pauta, $remover, $tenant): void {
            $remover->executar($tenant, $id, $pauta);
        }, 'voto.assembleia.gestor.aviso.pauta_removida', ['id' => $id]);
    }

    /** "Mover para cima" e "Mover para baixo" do menu da pauta (desenho 18): troca de lugar com a vizinha, só em rascunho. */
    #[Route('/{pauta}/mover/{direcao}', name: 'voto_assembleia_pauta_mover', methods: ['POST'])]
    public function mover(int $id, int $pauta, string $direcao, Request $request, MoverPautaUseCase $mover): Response
    {
        $tenant = $this->guarda->exigir();
        $sentido = 'cima' === $direcao ? MoverPautaUseCase::PARA_CIMA : MoverPautaUseCase::PARA_BAIXO;

        return $this->responder($request, $id, static function () use ($id, $mover, $pauta, $sentido, $tenant): void {
            $mover->executar($tenant, $id, $pauta, $sentido);
        }, 'voto.assembleia.gestor.aviso.pauta_movida', ['id' => $id, '_fragment' => 'pauta-' . $pauta]);
    }

    /** "Duplicar" do menu da pauta (desenho 18): a cópia entra logo abaixo da original, só em rascunho. */
    #[Route('/{pauta}/duplicar', name: 'voto_assembleia_pauta_duplicar', methods: ['POST'])]
    public function duplicar(int $id, int $pauta, Request $request, DuplicarPautaUseCase $duplicar): Response
    {
        $tenant = $this->guarda->exigir();

        return $this->responder($request, $id, static function () use ($duplicar, $id, $pauta, $tenant): void {
            $duplicar->executar($tenant, $id, $pauta);
        }, 'voto.assembleia.gestor.aviso.pauta_duplicada', ['id' => $id, '_fragment' => 'pauta-' . $pauta]);
    }

    /**
     * Executa a ação e responde: JSON `{ok, mensagem}` ao fetch da tela (200, 422, 404 ou 403, sem aviso na sessão) ou o
     * toast na sessão e o redirecionamento para as pautas.
     *
     * @param array<string, int|string> $voltar parâmetros da rota das pautas no envio comum
     */
    private function responder(Request $request, int $id, callable $acao, string $avisoOk, array $voltar): Response
    {
        [$status, $mensagem] = $this->executar($request, $id, $acao, $avisoOk);
        $ok = Response::HTTP_OK === $status;
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => $ok, 'mensagem' => $mensagem], $status);
        }
        if ($ok) {
            $this->avisos->ok($mensagem);
        } else {
            $this->avisos->erro($mensagem);
        }

        return $this->redirectToRoute('voto_assembleia_pautas', $voltar);
    }

    /**
     * @return array{int, string} o status HTTP (200; 422 na recusa; 404 na pauta ou assembleia que não existe, só no
     *                            fetch; 403 na sessão expirada) e o texto do toast
     */
    private function executar(Request $request, int $id, callable $acao, string $avisoOk): array
    {
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            return [Response::HTTP_FORBIDDEN, $this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada')];
        }
        try {
            $texto = $acao();

            return [Response::HTTP_OK, \is_string($texto) ? $texto : $this->tradutor->trans($avisoOk)];
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            return [Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage()];
        } catch (AssembleiaNaoEncontradaException) {
            if (!$request->isXmlHttpRequest()) {
                throw $this->createNotFoundException();
            }

            return [Response::HTTP_NOT_FOUND, $this->tradutor->trans('voto.assembleia.gestor.aviso.pauta_nao_encontrada')];
        }
    }

    private static function input(Request $request): PautaInput
    {
        $numeros = $request->request->all('opcao_numero');
        $rotulos = $request->request->all('opcao_rotulo');
        $detalhes = ['descricao' => 'opcao_descricao', 'propostas' => 'opcao_propostas', 'composicao' => 'opcao_composicao', 'unidade' => 'opcao_unidade'];
        $campos = array_map(static fn (string $nome): array => $request->request->all($nome), $detalhes);
        // Foto 3x4 da candidatura: o nome da que a linha já tem (`opcao_foto[]`, vazio se o gestor a tirou) e o arquivo novo
        // (`opcao_foto_arquivo[]`). O servidor só aceita o nome que a própria pauta já guarda.
        $fotos = $request->request->all('opcao_foto');
        $arquivos = $request->files->get('opcao_foto_arquivo');
        $arquivos = \is_array($arquivos) ? $arquivos : [];
        $opcoes = [];
        foreach (array_values($numeros) as $i => $numero) {
            $linha = ['numero' => \is_string($numero) ? $numero : '', 'rotulo' => \is_string($rotulos[$i] ?? null) ? $rotulos[$i] : ''];
            foreach ($campos as $chave => $valores) {
                $linha[$chave] = \is_string($valores[$i] ?? null) ? $valores[$i] : '';
            }
            $linha['foto'] = \is_string($fotos[$i] ?? null) ? $fotos[$i] : '';
            $linha['arquivo'] = ($arquivos[$i] ?? null) instanceof UploadedFile ? $arquivos[$i] : null;
            $opcoes[] = $linha;
        }

        $tipo = TipoDaPauta::tryFrom((string) $request->request->get('tipo', TipoDaPauta::Deliberacao->value)) ?? TipoDaPauta::Deliberacao;

        return new PautaInput((string) $request->request->get('titulo', ''), (string) $request->request->get('descricao', ''), $opcoes, self::regras($request), $tipo);
    }

    private static function inputOuAtalho(Request $request, string $atalho): PautaInput
    {
        if (!\in_array($atalho, self::ATALHOS, true)) {
            return self::input($request);
        }

        // As regras do atalho vêm do cenário condomínio do desenho (pessoas com 2 dígitos; convenção com dois terços).
        return ModelosDePautas::pauta('condominio', $atalho);
    }

    /** As regras só vêm quando a tela manda o critério; sem ele, a pauta mantém as que tem. */
    private static function regras(Request $request): ?RegrasDaPautaInput
    {
        $criterio = RegraDeDecisao::tryFrom((string) $request->request->get('criterio', ''));
        if (null === $criterio) {
            return null;
        }
        $segunda = trim((string) $request->request->get('quorum_segunda', ''));

        return new RegrasDaPautaInput(
            $criterio,
            BaseDaDecisao::tryFrom((string) $request->request->get('base', '')) ?? BaseDaDecisao::Validos,
            self::inteiro((string) $request->request->get('digitos', '1')),
            self::inteiroOuPadrao((string) $request->request->get('vagas', '1'), 1),
            self::inteiroOuPadrao((string) $request->request->get('quorum_primeira', '50'), 0),
            '' === $segunda ? null : self::inteiro($segunda),
        );
    }

    private static function inteiroOuPadrao(string $valor, int $padrao): int
    {
        return '' === trim($valor) ? $padrao : self::inteiro($valor);
    }

    /** Só algarismos valem; o resto vira -1, que a pauta recusa como fora do limite (nada de lixo virando 0). */
    private static function inteiro(string $valor): int
    {
        return 1 === preg_match('/^\d{1,6}$/', trim($valor)) ? (int) $valor : -1;
    }
}
