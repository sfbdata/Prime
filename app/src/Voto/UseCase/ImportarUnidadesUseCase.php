<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\LinhaDeUnidade;
use App\Voto\DTO\ResultadoDaImportacao;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ArquivoDeUnidades;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\TokenDaUnidade;
use Symfony\Component\HttpFoundation\UploadedFile;

/**
 * O gestor cola a lista ou envia XLSX/CSV enquanto nenhuma pauta foi aberta ("a lista bloqueia na abertura"). As linhas
 * válidas ENTRAM na lista (cada uma com o seu link); quem já estava fica como está, e a linha que repete uma unidade
 * já cadastrada conta como duplicada. Devolve o relatório das inválidas e das duplicadas.
 *
 * Não substitui a lista: os links já enviados continuam valendo. Para corrigir uma unidade, remova e importe de novo.
 */
final class ImportarUnidadesUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LeitorDaListaDeUnidades $leitor,
        private readonly TokenDaUnidade $tokens,
        private readonly ArquivoDeUnidades $arquivos,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $texto): ResultadoDaImportacao
    {
        $assembleia = $this->carregarAssembleia($tenant, $assembleiaId);
        $assembleia->exigirUnidadesAlteraveis();

        return $this->importarLinhas($assembleia, $this->leitor->ler($texto, $assembleia->getPeso()));
    }

    public function executarArquivo(Tenant $tenant, int $assembleiaId, UploadedFile $arquivo): ResultadoDaImportacao
    {
        $assembleia = $this->carregarAssembleia($tenant, $assembleiaId);
        $assembleia->exigirUnidadesAlteraveis();
        $linhas = $this->arquivos->ler($arquivo);

        return $this->importarLinhas($assembleia, $this->leitor->lerTabela($linhas, $assembleia->getPeso()));
    }

    private function carregarAssembleia(Tenant $tenant, int $assembleiaId): Assembleia
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();

        return $assembleia;
    }

    /** @param array{validas: list<LinhaDeUnidade>, invalidas: list<array{linha: int, motivo: string}>, duplicadas: list<int>} $lidas */
    private function importarLinhas(Assembleia $assembleia, array $lidas): ResultadoDaImportacao
    {
        $jaNaLista = self::chavesDaLista($assembleia);
        $duplicadas = $lidas['duplicadas'];
        $importadas = 0;
        foreach ($lidas['validas'] as $linha) {
            if (isset($jaNaLista[$linha->chave()])) {
                $duplicadas[] = $linha->linha;

                continue;
            }
            $this->unidades->salvar($this->novaUnidade($assembleia, $linha));
            ++$importadas;
        }
        $this->assembleias->salvar($assembleia, flush: true);
        sort($duplicadas);

        return new ResultadoDaImportacao($importadas, $lidas['invalidas'], $duplicadas);
    }

    private function novaUnidade(Assembleia $assembleia, LinhaDeUnidade $linha): UnidadeDaAssembleia
    {
        $semente = $this->tokens->novaSemente();

        return new UnidadeDaAssembleia(
            assembleia: $assembleia,
            identificacao: $linha->identificacao,
            semente: $semente,
            tokenHash: $this->tokens->hash($this->tokens->token($semente)),
            bloco: $linha->bloco,
            situacao: $linha->situacao,
            fracao: $linha->fracao,
            responsavel: $linha->responsavel,
            email: $linha->email,
        );
    }

    /** @return array<string, true> */
    private static function chavesDaLista(Assembleia $assembleia): array
    {
        $chaves = [];
        foreach ($assembleia->getUnidades() as $unidade) {
            $chaves[LinhaDeUnidade::chaveDe($unidade->getBloco(), $unidade->getIdentificacao())] = true;
        }

        return $chaves;
    }
}
