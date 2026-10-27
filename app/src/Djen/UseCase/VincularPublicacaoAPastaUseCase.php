<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Pasta\UseCase\VincularProcessoUseCase;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\UseCase\CadastrarProcessoPelaPastaUseCase;

/**
 * "Vincular a uma pasta" (e "Trocar a pasta vinculada") na linha da publicação do Push (desenho 11).
 *
 * O vínculo é do PROCESSO com a pasta: todas as publicações do mesmo número passam a aparecer como
 * Vinculadas. Se o escritório ainda não cadastrou o processo, ele nasce do que a própria publicação traz
 * (órgão e classe; o assunto fica "Não informado" e se completa depois na pasta), pelo mesmo caminho do
 * "Vincular processo" da pasta. Só vincula quem EDITA a pasta (cadeado incluído).
 */
final class VincularPublicacaoAPastaUseCase
{
    private const NAO_INFORMADO = 'Não informado';

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaRepository $pastas,
        private readonly ProcessoRepository $processos,
        private readonly AcessoAPasta $acesso,
        private readonly CadastrarProcessoPelaPastaUseCase $cadastrarProcesso,
        private readonly VincularProcessoUseCase $vincular,
        private readonly DesvincularProcessoUseCase $desvincular,
        private readonly ReconciliarPublicacoesComProcessosUseCase $reconciliar,
    ) {
    }

    /**
     * @param int|null $trocarDaPastaId pasta de onde o processo sai, quando o pedido é "Trocar a pasta vinculada"
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executar(Tenant $tenant, User $usuario, int $publicacaoId, int $pastaId, ?int $trocarDaPastaId = null): Pasta
    {
        $publicacao = $this->publicacoes->findOneByIdDoTenant($publicacaoId, $tenant)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');
        $pasta = $this->pastaEditavel($tenant, $usuario, $pastaId);
        $processo = $publicacao->getProcesso() ?? $this->processoDaPublicacao($tenant, $usuario, $publicacao);

        if ($trocarDaPastaId !== null && $trocarDaPastaId !== $pastaId) {
            $anterior = $this->pastaEditavel($tenant, $usuario, $trocarDaPastaId);
            $this->desvincular->executar($anterior, $processo, $usuario);
        }

        $this->vincular->executar($pasta, $processo, $usuario);
        $this->reconciliar->executar($tenant);

        return $pasta;
    }

    /**
     * "Vincular a uma pasta" na aba Processos da OAB (desenho 11): o processo é identificado só pelo número (CNJ).
     * Sem cadastro no escritório, ele nasce do que a publicação mais recente traz; sem publicação, só do número.
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executarPorNumero(Tenant $tenant, User $usuario, string $numero, int $pastaId, ?int $trocarDaPastaId = null): Pasta
    {
        $numero = preg_replace('/\D+/', '', $numero) ?? '';
        if (strlen($numero) !== 20) {
            throw new VinculoDaPublicacaoInvalidoException('Informe o número completo do processo (20 dígitos, padrão CNJ).');
        }
        $pasta = $this->pastaEditavel($tenant, $usuario, $pastaId);
        $processo = $this->processos->findByNumeroProcessoDoTenant($numero, $tenant) ?? $this->cadastrarPeloNumero($tenant, $usuario, $numero);

        if ($trocarDaPastaId !== null && $trocarDaPastaId !== $pastaId) {
            $anterior = $this->pastaEditavel($tenant, $usuario, $trocarDaPastaId);
            $this->desvincular->executar($anterior, $processo, $usuario);
        }

        $this->vincular->executar($pasta, $processo, $usuario);
        $this->reconciliar->executar($tenant);

        return $pasta;
    }

    /**
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executarPorNumeroENup(Tenant $tenant, User $usuario, string $numero, string $nup, ?int $trocarDaPastaId = null): Pasta
    {
        $nup = trim($nup);
        $pasta = $nup === '' ? null : $this->pastas->findOneBy(['nup' => $nup, 'tenant' => $tenant]);
        if (!$pasta instanceof Pasta || $pasta->getExcluidaEm() !== null) {
            throw new VinculoDaPublicacaoInvalidoException(sprintf('A Pasta %s não existe neste escritório. Use "Abrir nova pasta".', $nup));
        }

        return $this->executarPorNumero($tenant, $usuario, $numero, (int) $pasta->getId(), $trocarDaPastaId);
    }

    /**
     * "Vincular à Pasta N" (desenho 11): o número digitado é o NUP da pasta. Pasta que não existe no escritório
     * não é criada às cegas: a mensagem manda usar "Abrir nova pasta".
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executarPorNup(Tenant $tenant, User $usuario, int $publicacaoId, string $nup, ?int $trocarDaPastaId = null): Pasta
    {
        $nup = trim($nup);
        $pasta = $nup === '' ? null : $this->pastas->findOneBy(['nup' => $nup, 'tenant' => $tenant]);
        if (!$pasta instanceof Pasta || $pasta->getExcluidaEm() !== null) {
            throw new VinculoDaPublicacaoInvalidoException(sprintf('A Pasta %s não existe neste escritório. Use "Abrir nova pasta".', $nup));
        }

        return $this->executar($tenant, $usuario, $publicacaoId, (int) $pasta->getId(), $trocarDaPastaId);
    }

    private function pastaEditavel(Tenant $tenant, User $usuario, int $pastaId): Pasta
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if (!$pasta instanceof Pasta || $pasta->getExcluidaEm() !== null) {
            throw new VinculoDaPublicacaoInvalidoException('Pasta não encontrada.');
        }
        if (!$this->acesso->podeEditar($usuario, $pasta)) {
            throw new VinculoDaPublicacaoInvalidoException('Você não tem permissão para alterar esta pasta.');
        }

        return $pasta;
    }

    private function cadastrarPeloNumero(Tenant $tenant, User $usuario, string $numero): Processo
    {
        $publicacao = $this->publicacoes->findOneBy(['numeroProcesso' => $numero, 'tenant' => $tenant], ['id' => 'DESC']);
        if ($publicacao instanceof PublicacaoDjen) {
            return $this->processoDaPublicacao($tenant, $usuario, $publicacao);
        }

        $entrada = new CadastroDoProcessoInput(
            numeroProcesso: $numero,
            orgaoJulgador: self::NAO_INFORMADO,
            classeProcessual: self::NAO_INFORMADO,
            assuntoProcessual: self::NAO_INFORMADO,
        );
        try {
            return $this->cadastrarProcesso->executar($entrada, $tenant, $usuario)->processo;
        } catch (DadosDoProcessoInvalidosException $e) {
            throw new VinculoDaPublicacaoInvalidoException($e->getMessage(), 0, $e);
        }
    }

    private function processoDaPublicacao(Tenant $tenant, User $usuario, PublicacaoDjen $publicacao): Processo
    {
        $numero = $publicacao->getNumeroProcesso();
        $existente = $this->processos->findByNumeroProcessoDoTenant($numero, $tenant);
        if ($existente !== null) {
            return $existente;
        }

        $entrada = new CadastroDoProcessoInput(
            numeroProcesso: $numero,
            orgaoJulgador: trim((string) $publicacao->getNomeOrgao()) ?: self::NAO_INFORMADO,
            classeProcessual: trim((string) $publicacao->getNomeClasse()) ?: self::NAO_INFORMADO,
            assuntoProcessual: self::NAO_INFORMADO,
        );
        try {
            return $this->cadastrarProcesso->executar($entrada, $tenant, $usuario)->processo;
        } catch (DadosDoProcessoInvalidosException $e) {
            throw new VinculoDaPublicacaoInvalidoException($e->getMessage(), 0, $e);
        }
    }
}
