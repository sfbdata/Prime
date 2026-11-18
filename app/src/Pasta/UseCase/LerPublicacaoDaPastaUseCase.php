<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PublicacaoDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\PublicacaoDaPastaNaoEncontradaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * Ler as publicações do Diário ligadas à pasta, SEM marcar nada como lida (`docs/specs/mcp-remoto-oauth.md`, 6.4,
 * `ler_pasta` parte `publicacoes`). Quem: alguém que vê a pasta, pelo conector de I.A.
 *
 * O `pasta_push_teor` da tela grava `lida` para o escritório todo; aqui o caminho é o do `pasta_push_texto` (o "Copiar
 * texto" do cartão), que não tem efeito colateral. Nenhum `setLida`, nenhum flush.
 *
 * Regras (as mesmas da tela, na mesma ordem):
 *  - a pasta é do escritório `$tenant` ({@see PastaDeOutroEscritorioException});
 *  - quem lê pode vê-la, com o cadeado ({@see AcessoAPasta::podeVer()}; {@see SemPermissaoParaVerPastaException});
 *  - a aba Push não está oculta para quem lê (senão {@see PublicacaoDaPastaNaoEncontradaException});
 *  - a publicação casa, pelo NÚMERO do processo, com um processo DESTA pasta e do escritório; senão
 *    {@see PublicacaoDaPastaNaoEncontradaException}, sem distinguir "de outra pasta" de "de outro escritório".
 *
 * A lista é a mesma consulta da aba Push (`listarItensPorNumerosDoTenant`, teto {@see LIMITE_DA_LISTA}), e não o
 * `MontarPainelDoPushUseCase`: o painel não lista publicações e GRAVA a previsão e a taxa de acertos.
 */
final class LerPublicacaoDaPastaUseCase
{
    /** O mesmo teto da aba Push (`PastaController::PUSH_LIMITE`). */
    public const LIMITE_DA_LISTA = 100;

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly VisibilidadeDasAbasDaPasta $abas,
    ) {
    }

    /**
     * Uma página da lista, a mais recente primeiro. `texto` é o teor inteiro em texto puro, sem corte: quem entrega a terceiros mascara e só depois resume (decisão 22).
     *
     * @return array{itens: list<PublicacaoDaPastaOutput>, haMais: bool}
     *
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaVerPastaException
     * @throws PublicacaoDaPastaNaoEncontradaException
     */
    public function listar(Pasta $pasta, User $quem, Tenant $tenant, int $pagina, int $porPagina): array
    {
        $this->exigirLeitura($pasta, $quem, $tenant);
        $numeros = self::numerosDosProcessos($pasta);
        $todas = $this->publicacoes->listarItensPorNumerosDoTenant($tenant, $numeros, self::LIMITE_DA_LISTA);
        $daPagina = \array_slice($todas, max(0, ($pagina - 1) * $porPagina), $porPagina);

        // Uma consulta para a página inteira, com a mesma restrição de escritório e números da leitura de uma só.
        $porId = $this->publicacoes->findByIdsENumerosDoTenant(array_map(static fn ($item) => $item->id, $daPagina), $tenant, $numeros);
        $itens = [];
        foreach ($daPagina as $item) {
            $publicacao = $porId[$item->id] ?? null;
            if ($publicacao === null) {
                continue;
            }
            $itens[] = PublicacaoDaPastaOutput::de($publicacao, PublicacaoDjenListaItem::resumirTexto($publicacao->getTexto(), \PHP_INT_MAX));
        }

        return ['itens' => $itens, 'haMais' => \count($todas) > $pagina * $porPagina];
    }

    /**
     * O teor inteiro, em texto puro, de uma publicação desta pasta.
     *
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaVerPastaException
     * @throws PublicacaoDaPastaNaoEncontradaException
     */
    public function executar(Pasta $pasta, User $quem, Tenant $tenant, int $publicacaoId): PublicacaoDaPastaOutput
    {
        $this->exigirLeitura($pasta, $quem, $tenant);
        $publicacao = $this->publicacoes->findOneByIdENumerosDoTenant($publicacaoId, $tenant, self::numerosDosProcessos($pasta));
        if ($publicacao === null) {
            throw new PublicacaoDaPastaNaoEncontradaException('Publicação não encontrada nesta pasta.');
        }

        return PublicacaoDaPastaOutput::de($publicacao, PublicacaoDjenListaItem::resumirTexto($publicacao->getTexto(), \PHP_INT_MAX));
    }

    private function exigirLeitura(Pasta $pasta, User $quem, Tenant $tenant): void
    {
        $doEscritorio = $pasta->getTenant() === $tenant
            || ($tenant->getId() !== null && $pasta->getTenant()?->getId() === $tenant->getId());
        if (!$doEscritorio) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeVer($quem, $pasta)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }
        if (\in_array(AbaDaPasta::Push, $this->abas->abasOcultasPara($pasta, $quem), true)) {
            throw new PublicacaoDaPastaNaoEncontradaException('Publicação não encontrada nesta pasta.');
        }
    }

    /** @return list<string> */
    private static function numerosDosProcessos(Pasta $pasta): array
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }

        return $numeros;
    }
}
