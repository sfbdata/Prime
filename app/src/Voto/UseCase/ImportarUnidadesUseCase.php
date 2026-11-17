<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\LinhaDeUnidade;
use App\Voto\DTO\ResultadoDaImportacao;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Enum\OrigemDaOcorrencia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ArquivoDeUnidades;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TokenDaUnidade;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O gestor cola a lista ou envia XLSX/CSV enquanto nenhuma pauta foi aberta ("a lista bloqueia na abertura"). As linhas
 * válidas ENTRAM na lista (cada uma com o seu link); quem já estava fica como está, e a linha que repete uma unidade
 * já cadastrada conta como duplicada. Devolve o relatório das inválidas e das duplicadas.
 *
 * A coluna 8 `ocorrencia` (opcional) vira uma ocorrência de origem "importação", sem avaliação (spec
 * voto-avaliacoes-do-conselho-1611, seção 2).
 *
 * Não substitui a lista: os links já enviados continuam valendo. Para corrigir uma unidade, remova e importe de novo.
 */
final class ImportarUnidadesUseCase
{
    public const AUTOR_DA_IMPORTACAO = 'Importação da lista';

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LeitorDaListaDeUnidades $leitor,
        private readonly TokenDaUnidade $tokens,
        private readonly ArquivoDeUnidades $arquivos,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly OcorrenciaDaUnidadeRepository $ocorrencias,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $texto): ResultadoDaImportacao
    {
        return $this->serializacao->executar(
            $tenant,
            $assembleiaId,
            fn (): ResultadoDaImportacao => $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $texto),
        );
    }

    public function executarArquivo(Tenant $tenant, int $assembleiaId, UploadedFile $arquivo): ResultadoDaImportacao
    {
        return $this->serializacao->executar(
            $tenant,
            $assembleiaId,
            fn (): ResultadoDaImportacao => $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $arquivo),
        );
    }

    private function executarComConfiguracaoReservada(
        Tenant $tenant,
        int $assembleiaId,
        string|UploadedFile $entrada,
    ): ResultadoDaImportacao {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirUnidadesAlteraveis();
        $lidas = $entrada instanceof UploadedFile
            ? $this->leitor->lerTabela($this->arquivos->ler($entrada), $assembleia->getPeso())
            : $this->leitor->ler($entrada, $assembleia->getPeso());

        return $this->importarLinhas($assembleia, $lidas);
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
            $unidade = $this->novaUnidade($assembleia, $linha);
            $this->unidades->salvar($unidade);
            if (null !== $linha->ocorrencia) {
                // Coluna 8 da lista (N97 do desenho): entra como origem "importação" e sem avaliação, mesmo em unidade não apta.
                $this->ocorrencias->salvar(new OcorrenciaDaUnidade($assembleia, $unidade, $linha->ocorrencia, OrigemDaOcorrencia::Importacao, self::AUTOR_DA_IMPORTACAO));
            }
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
            telefone: $linha->telefone,
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
